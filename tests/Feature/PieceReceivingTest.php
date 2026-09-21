<?php

namespace Tests\Feature;

use App\Livewire\PurchaseOrders\PurchaseOrderManager;
use App\Livewire\Reports\ReportViewer;
use App\Models\Batch;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReceivingIssue;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseReceivingService;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * Bought by the packet, sold by the piece: damaged/missing goods are often a
 * few pieces out of a packet. Everything is exact in pieces (2 of 12 is not
 * 0.167 of a packet), and good + damaged can never pass what was ordered.
 */
class PieceReceivingTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $admin;

    private Product $product;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->supplier = Supplier::create(['name' => 'Sweet Supply', 'status' => 'active']);

        // 1 packet = 12 pieces; 60.00 a packet = 5.00 a piece.
        $this->product = Product::factory()->create([
            'name' => 'Candy', 'selling_price' => 9, 'cost_price' => 5, 'conversion_qty' => 12,
            'purchase_unit_id' => Unit::factory()->create(['name' => 'packet'])->id,
            'selling_unit_id' => Unit::factory()->create(['name' => 'piece'])->id,
        ]);
    }

    /** One packet ordered at 60.00. */
    private function order(float $packets = 1): array
    {
        $po = PurchaseOrder::create([
            'po_number' => PurchaseOrder::generatePoNumber(),
            'supplier_id' => $this->supplier->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);
        $line = $po->lineItems()->create([
            'product_id' => $this->product->id, 'qty_ordered' => $packets,
            'purchase_unit_id' => $this->product->purchase_unit_id, 'cost_price' => 60,
        ]);

        return [$po, $line];
    }

    private function receive(PurchaseOrder $po, array $receipt): array
    {
        return app(PurchaseReceivingService::class)->receive($po, [$receipt], $this->admin);
    }

    private function stock(): float
    {
        return (float) Batch::where('product_id', $this->product->id)->sum('qty_received');
    }

    // ------------------------------------------------------------ the scenario

    public function test_a_packet_two_pieces_short_is_recorded_exactly(): void
    {
        [$po, $line] = $this->order();

        // The packet came with 10 pieces in it; the other 2 aren't coming.
        $notes = $this->receive($po, [
            'line_item_id' => $line->id, 'qty' => 10, 'qty_unit' => 'selling',
            'close_short' => true, 'short_reason' => 'packet was short-packed',
        ]);

        $this->assertEquals(10, $this->stock(), 'stock is exactly 10 pieces, not 9.996');

        $issue = ReceivingIssue::firstOrFail();
        $this->assertSame('missing', $issue->issue_type);
        $this->assertEquals(2, $issue->qty);                     // pieces
        $this->assertEquals(5.00, $issue->unit_cost);            // per piece
        $this->assertEquals(10.00, $issue->loss_value);          // exactly 2 x 5.00, not 10.02
        $this->assertStringContainsString('2 piece', $notes[0]);

        $this->assertSame('received', $po->fresh()->status);
        $this->assertEquals(0, $line->fresh()->remainingQty());
    }

    public function test_a_full_packet_with_two_broken_pieces(): void
    {
        [$po, $line] = $this->order();

        $this->receive($po, [
            'line_item_id' => $line->id, 'qty' => 10, 'qty_unit' => 'selling',
            'damaged_qty' => 2, 'damaged_unit' => 'selling', 'damaged_reason' => 'crushed',
        ]);

        $this->assertEquals(10, $this->stock());
        $this->assertEquals(10.00, ReceivingIssue::firstOrFail()->loss_value);
        $this->assertEquals(0, $line->fresh()->remainingQty(), 'no rounding dust left on the line');
        $this->assertSame('received', $po->fresh()->status, 'the order completes — nothing more is expected');
    }

    public function test_whole_packets_and_pieces_can_be_mixed(): void
    {
        [$po, $line] = $this->order(2);   // 24 pieces

        $this->receive($po, [
            'line_item_id' => $line->id, 'qty' => 1, 'qty_unit' => 'purchase',       // 12 pieces
            'damaged_qty' => 3, 'damaged_unit' => 'selling', 'damaged_reason' => 'wet',
        ]);

        $this->assertEquals(12, $this->stock());
        $this->assertEquals(15.00, ReceivingIssue::firstOrFail()->loss_value);   // 3 x 5.00
        $this->assertEquals(9, round($line->fresh()->remainingQty() * 12, 3), '9 pieces still expected');
    }

    public function test_three_deliveries_of_four_pieces_complete_the_packet_exactly(): void
    {
        [$po, $line] = $this->order();

        foreach ([1, 2, 3] as $i) {
            $this->receive($po->fresh(), ['line_item_id' => $line->id, 'qty' => 4, 'qty_unit' => 'selling']);
        }

        $this->assertEquals(12, $this->stock());
        $this->assertEquals(0, $line->fresh()->remainingQty());
        $this->assertSame('received', $po->fresh()->status, 'thirds of a packet must not leave the order open');
    }

    // ------------------------------------------------- must not pass the order

    public function test_good_plus_damaged_pieces_cannot_pass_the_packet(): void
    {
        [$po, $line] = $this->order();

        try {
            $this->receive($po, [
                'line_item_id' => $line->id, 'qty' => 11, 'qty_unit' => 'selling',
                'damaged_qty' => 2, 'damaged_unit' => 'selling', 'damaged_reason' => 'x',   // 13 > 12
            ]);
            $this->fail('expected refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('only 12 piece (1 packet) remaining', $e->getMessage());
        }

        $this->assertSame(0, Batch::count());
        $this->assertSame(0, ReceivingIssue::count());
        $this->assertEquals(0, $line->fresh()->qty_received);
    }

    public function test_a_packet_plus_one_piece_is_more_than_one_packet_ordered(): void
    {
        [$po, $line] = $this->order();

        $this->expectException(RuntimeException::class);

        $this->receive($po, [
            'line_item_id' => $line->id, 'qty' => 1, 'qty_unit' => 'purchase',
            'damaged_qty' => 1, 'damaged_unit' => 'selling', 'damaged_reason' => 'x',       // 12 + 1
        ]);
    }

    public function test_exactly_the_order_is_allowed(): void
    {
        [$po, $line] = $this->order();

        $this->receive($po, [
            'line_item_id' => $line->id, 'qty' => 9, 'qty_unit' => 'selling',
            'damaged_qty' => 3, 'damaged_unit' => 'selling', 'damaged_reason' => 'x',        // 12 = 12
        ]);

        $this->assertSame('received', $po->fresh()->status);
    }

    public function test_pieces_already_received_count_against_the_next_delivery(): void
    {
        [$po, $line] = $this->order();
        $this->receive($po, ['line_item_id' => $line->id, 'qty' => 8, 'qty_unit' => 'selling']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only 4 piece (0.333 packet) remaining');

        $this->receive($po->fresh(), ['line_item_id' => $line->id, 'qty' => 3, 'qty_unit' => 'selling', 'damaged_qty' => 2, 'damaged_unit' => 'selling', 'damaged_reason' => 'x']);   // 5 > 4
    }

    public function test_the_same_delivery_repeated_cannot_be_received_twice(): void
    {
        [$po, $line] = $this->order();
        $receipt = ['line_item_id' => $line->id, 'qty' => 12, 'qty_unit' => 'selling'];

        $this->receive($po, $receipt);

        $this->expectException(RuntimeException::class);
        $this->receive($po->fresh(), $receipt);
    }

    // -------------------------------------------------------------- the panel

    public function test_the_panel_offers_a_unit_for_each_quantity_and_shows_pieces(): void
    {
        [$po] = $this->order();

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->assertSee('= 12 piece')                                   // remaining, in pieces
            ->set('receivingLines.0.qty', '10')
            ->set('receivingLines.0.qty_unit', 'selling')
            ->assertSee('Adds 10 piece to stock')
            ->set('receivingLines.0.damaged_qty', '2')
            ->set('receivingLines.0.damaged_unit', 'selling')
            ->assertSee('2 piece damaged')
            ->assertSee('10.00')                                        // 2 x 5.00
            ->assertDontSee('receiving is blocked');
    }

    public function test_the_panel_says_receiving_is_blocked_when_it_passes_the_order(): void
    {
        [$po] = $this->order();

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->set('receivingLines.0.qty', '11')
            ->set('receivingLines.0.qty_unit', 'selling')
            ->set('receivingLines.0.damaged_qty', '2')
            ->set('receivingLines.0.damaged_unit', 'selling')
            ->assertSee('receiving is blocked', false)
            ->assertSee('You ordered')
            ->assertSee('no more than')
            ->assertSee('12 piece')
            ->assertSee('You\'ve entered 13 piece', false);
    }

    public function test_the_panel_refuses_to_save_a_delivery_that_passes_the_order(): void
    {
        [$po] = $this->order();

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->set('receivingLines.0.qty', '11')
            ->set('receivingLines.0.qty_unit', 'selling')
            ->set('receivingLines.0.damaged_qty', '2')
            ->set('receivingLines.0.damaged_unit', 'selling')
            ->set('receivingLines.0.damaged_reason', 'x')
            ->call('receive');

        $this->assertSame(0, Batch::count());
        $this->assertSame(0, ReceivingIssue::count());
    }

    public function test_receiving_pieces_through_the_panel_saves_exactly(): void
    {
        [$po] = $this->order();

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->set('receivingLines.0.qty', '10')
            ->set('receivingLines.0.qty_unit', 'selling')
            ->set('receivingLines.0.damaged_qty', '2')
            ->set('receivingLines.0.damaged_unit', 'selling')
            ->set('receivingLines.0.damaged_reason', 'crushed')
            ->call('receive');

        $this->assertEquals(10, $this->stock());
        $this->assertEquals(10.00, ReceivingIssue::firstOrFail()->loss_value);
        $this->assertSame('received', $po->fresh()->status);
    }

    public function test_the_details_panel_and_report_show_pieces(): void
    {
        [$po, $line] = $this->order();
        $this->receive($po, [
            'line_item_id' => $line->id, 'qty' => 10, 'qty_unit' => 'selling',
            'damaged_qty' => 2, 'damaged_unit' => 'selling', 'damaged_reason' => 'crushed',
        ]);

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('view', $po->id)
            ->assertSee('2 piece');

        Livewire::actingAs($this->admin)->test(ReportViewer::class)
            ->call('selectReport', 'receiving_losses')
            ->assertSee('2 piece')
            ->assertSee('10.00');
    }

    public function test_the_panel_header_says_what_was_ordered_not_what_is_left(): void
    {
        [$po] = $this->order(2);

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->assertSee('2 packet ordered')
            ->assertSee('= 24 piece')
            ->assertDontSee('remaining')
            ->assertDontSee('already in');
    }

    public function test_once_part_is_in_the_header_still_says_ordered_and_adds_how_it_stands(): void
    {
        [$po, $line] = $this->order(2);
        $this->receive($po, ['line_item_id' => $line->id, 'qty' => 1, 'qty_unit' => 'purchase']);

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->assertSee('2 packet ordered')
            ->assertSee('1 already in · 1 left');
    }

    public function test_the_friendly_message_says_how_much_is_left_when_part_is_already_in(): void
    {
        [$po, $line] = $this->order(2);
        $this->receive($po, ['line_item_id' => $line->id, 'qty' => 1, 'qty_unit' => 'purchase']);

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->set('receivingLines.0.qty', '2')                        // 2 packets, only 1 left
            ->assertSee('You ordered')
            ->assertSee('1 is already in', false)
            ->assertSee('no more than')
            ->assertSee('12 piece');
    }

    public function test_the_server_refusal_reads_as_a_plain_explanation(): void
    {
        [$po, $line] = $this->order(2);
        $this->receive($po, ['line_item_id' => $line->id, 'qty' => 1, 'qty_unit' => 'purchase']);

        try {
            $this->receive($po->fresh(), ['line_item_id' => $line->id, 'qty' => 15, 'qty_unit' => 'selling']);
            $this->fail('expected refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("That's more than was ordered", $e->getMessage());
            $this->assertStringContainsString('you entered 15 piece', $e->getMessage());
            $this->assertStringContainsString('only 12 piece (1 packet) remaining on this order', $e->getMessage());
            $this->assertStringContainsString('2 packet ordered, 1 already received', $e->getMessage());
            $this->assertStringContainsString('Please lower the quantity', $e->getMessage());
        }
    }
}
