<?php

namespace Tests\Feature;

use App\Livewire\Reports\ReportViewer;
use App\Livewire\PurchaseOrders\PurchaseOrderManager;
use App\Models\Batch;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReceivingIssue;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseReceivingService;
use App\Services\SupplierClaimService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * Damaged and missing goods on receiving: recorded as a loss at cost and a
 * claim against the supplier, never as stock, and the claim stays "owed"
 * until it's credited or waived.
 */
class ReceivingIssuesTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $admin;

    private User $clerk;

    private Product $product;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();

        $role = Role::create(['name' => 'Receiving Clerk', 'description' => 'receives, cannot write off', 'status' => 'active']);
        $role->permissions()->attach(Permission::where(fn ($q) => $q
            ->where(fn ($p) => $p->where('module', 'purchase_orders')->whereIn('action', ['view', 'update']))
            ->orWhere(fn ($p) => $p->where('module', 'inventory')->whereIn('action', ['view', 'update']))
        )->pluck('id'));
        $this->clerk = User::factory()->create(['role_id' => $role->id]);

        $unit = Unit::factory()->create();
        $this->product = Product::factory()->create([
            'name' => 'Glass Bottle', 'selling_price' => 10, 'cost_price' => 6, 'conversion_qty' => 1,
            'purchase_unit_id' => $unit->id, 'selling_unit_id' => $unit->id,
        ]);
        $this->supplier = Supplier::create(['name' => 'Acme Supply', 'status' => 'active']);
    }

    /** 10 ordered at 6.00 each. */
    private function order(): array
    {
        $po = PurchaseOrder::create([
            'po_number' => PurchaseOrder::generatePoNumber(),
            'supplier_id' => $this->supplier->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);
        $line = $po->lineItems()->create([
            'product_id' => $this->product->id, 'qty_ordered' => 10,
            'purchase_unit_id' => $this->product->purchase_unit_id, 'cost_price' => 6,
        ]);

        return [$po, $line];
    }

    private function receive(PurchaseOrder $po, array $receipt): array
    {
        return app(PurchaseReceivingService::class)->receive($po, [$receipt], $this->admin);
    }

    // -------------------------------------------------------------- damaged

    public function test_damaged_units_never_become_stock_but_use_up_the_order_and_create_a_claim(): void
    {
        [$po, $line] = $this->order();

        $notes = $this->receive($po, ['line_item_id' => $line->id, 'qty' => 5, 'damaged_qty' => 2, 'damaged_reason' => 'crushed in transit']);

        $this->assertEquals(5, Batch::where('product_id', $this->product->id)->sum('qty_received'), 'only the good units are stock');

        $line = $line->fresh();
        $this->assertEquals(5, $line->qty_received);
        $this->assertEquals(2, $line->qty_damaged);
        $this->assertEquals(3, $line->remainingQty());
        $this->assertSame('partially_received', $po->fresh()->status);

        $issue = ReceivingIssue::firstOrFail();
        $this->assertSame('damaged', $issue->issue_type);
        $this->assertEquals(2, $issue->qty);
        $this->assertEquals(12.00, $issue->loss_value);          // 2 x 6.00
        $this->assertSame('owed', $issue->claim_status);
        $this->assertSame($this->supplier->id, $issue->supplier_id);
        $this->assertStringContainsString('12.00 owed by the supplier', $notes[0]);
    }

    public function test_the_loss_is_valued_at_the_cost_actually_paid(): void
    {
        [$po, $line] = $this->order();

        $this->receive($po, ['line_item_id' => $line->id, 'qty' => 5, 'unit_cost' => '7', 'damaged_qty' => 2, 'damaged_reason' => 'leaking']);

        $this->assertEquals(14.00, ReceivingIssue::firstOrFail()->loss_value);   // 2 x 7.00
    }

    public function test_only_damaged_units_can_be_reported_with_nothing_received(): void
    {
        [$po, $line] = $this->order();

        $this->receive($po, ['line_item_id' => $line->id, 'damaged_qty' => 3, 'damaged_reason' => 'wet']);

        $this->assertSame(0, Batch::where('product_id', $this->product->id)->count());
        $this->assertSame('partially_received', $po->fresh()->status);
        $this->assertEquals(3, $line->fresh()->qty_damaged);
    }

    public function test_a_damaged_report_needs_a_reason_and_saves_nothing_without_one(): void
    {
        [$po, $line] = $this->order();

        try {
            $this->receive($po, ['line_item_id' => $line->id, 'qty' => 5, 'damaged_qty' => 2, 'damaged_reason' => '  ']);
            $this->fail('expected refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Say what was wrong', $e->getMessage());
        }

        $this->assertSame(0, Batch::count());
        $this->assertSame(0, ReceivingIssue::count());
        $this->assertEquals(0, $line->fresh()->qty_received);
    }

    public function test_good_plus_damaged_cannot_exceed_what_is_left_on_the_order(): void
    {
        [$po, $line] = $this->order();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only 10 remaining');

        $this->receive($po, ['line_item_id' => $line->id, 'qty' => 8, 'damaged_qty' => 3, 'damaged_reason' => 'x']);
    }

    // ----------------------------------------------------------- closed short

    public function test_closing_a_line_short_records_the_missing_units_and_lets_the_order_finish(): void
    {
        [$po, $line] = $this->order();

        $this->receive($po, ['line_item_id' => $line->id, 'qty' => 6, 'close_short' => true, 'short_reason' => 'supplier ran out']);

        $line = $line->fresh();
        $this->assertEquals(4, $line->qty_closed_short);
        $this->assertEquals(0, $line->remainingQty());
        $this->assertSame('received', $po->fresh()->status, 'a short order can still complete');

        $issue = ReceivingIssue::firstOrFail();
        $this->assertSame('missing', $issue->issue_type);
        $this->assertEquals(4, $issue->qty);
        $this->assertEquals(24.00, $issue->loss_value);          // 4 x 6.00, the ordered cost
    }

    public function test_damaged_and_missing_can_be_reported_together(): void
    {
        [$po, $line] = $this->order();

        $this->receive($po, [
            'line_item_id' => $line->id, 'qty' => 5, 'damaged_qty' => 2, 'damaged_reason' => 'broken',
            'close_short' => true, 'short_reason' => 'not shipped',
        ]);

        $this->assertEquals(['damaged' => 12.00, 'missing' => 18.00], ReceivingIssue::pluck('loss_value', 'issue_type')->map(fn ($v) => (float) $v)->all());   // 3 missing x 6
        $this->assertSame('received', $po->fresh()->status);
    }

    public function test_closing_short_needs_a_reason(): void
    {
        [$po, $line] = $this->order();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("won't arrive");

        $this->receive($po, ['line_item_id' => $line->id, 'qty' => 6, 'close_short' => true, 'short_reason' => '']);
    }

    public function test_leaving_the_rest_expected_keeps_the_order_open_as_before(): void
    {
        [$po, $line] = $this->order();

        $this->receive($po, ['line_item_id' => $line->id, 'qty' => 6]);

        $this->assertSame('partially_received', $po->fresh()->status);
        $this->assertSame(0, ReceivingIssue::count());
        $this->assertEquals(4, $line->fresh()->remainingQty());
    }

    public function test_the_database_refuses_more_accounted_for_than_ordered(): void
    {
        [, $line] = $this->order();

        $this->expectException(QueryException::class);
        DB::table('purchase_order_line_items')->where('id', $line->id)->update(['qty_received' => 6, 'qty_damaged' => 3, 'qty_closed_short' => 2]);
    }

    // ------------------------------------------------- the receive panel / who can

    public function test_the_receive_panel_offers_damaged_and_shows_the_claim_as_it_is_typed(): void
    {
        [$po] = $this->order();

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->assertSee('Some damaged or missing?')
            ->set('receivingLines.0.qty', '5')
            ->set('receivingLines.0.damaged_qty', '2')
            ->assertSee('claim on the supplier')
            ->assertSee('12.00')
            ->assertSee('Still expected after this delivery: 3');
    }

    public function test_the_panel_warns_when_good_plus_damaged_is_more_than_ordered(): void
    {
        [$po] = $this->order();

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->set('receivingLines.0.qty', '8')
            ->set('receivingLines.0.damaged_qty', '3')
            ->assertSee('You ordered')
            ->assertSee('no more than');
    }

    public function test_receiving_with_damaged_through_the_panel_saves_the_claim_and_says_so(): void
    {
        [$po] = $this->order();

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->set('receivingLines.0.qty', '5')
            ->set('receivingLines.0.damaged_qty', '2')
            ->set('receivingLines.0.damaged_reason', 'crushed')
            ->call('receive')
            ->assertDispatched('flash-message');

        $this->assertSame(1, ReceivingIssue::count());
        $this->assertEquals(5, Batch::where('product_id', $this->product->id)->sum('qty_received'));
    }

    public function test_a_clerk_can_report_damaged_but_cannot_close_a_line_short(): void
    {
        [$po, $line] = $this->order();

        Livewire::actingAs($this->clerk)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->assertDontSee("The rest won't arrive", false)
            ->set('receivingLines.0.qty', '5')
            ->set('receivingLines.0.damaged_qty', '2')
            ->set('receivingLines.0.damaged_reason', 'crushed')
            ->set('receivingLines.0.close_short', true)            // tampered
            ->set('receivingLines.0.short_reason', 'trying it on')
            ->call('receive');

        $this->assertSame(['damaged'], ReceivingIssue::pluck('issue_type')->all(), 'no missing claim was created');
        $this->assertEquals(0, $line->fresh()->qty_closed_short);
        $this->assertSame('partially_received', $po->fresh()->status);
    }

    public function test_an_admin_is_offered_close_short_and_can_use_it(): void
    {
        [$po, $line] = $this->order();

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->assertSee("The rest won't arrive", false)
            ->set('receivingLines.0.qty', '6')
            ->set('receivingLines.0.close_short', true)
            ->set('receivingLines.0.short_reason', 'supplier out of stock')
            ->assertSee('closed as missing')
            ->call('receive');

        $this->assertEquals(4, $line->fresh()->qty_closed_short);
        $this->assertSame('received', $po->fresh()->status);
    }

    // ------------------------------------------------------------- settling

    private function claim(float $value = 12.00): ReceivingIssue
    {
        [$po, $line] = $this->order();
        $this->receive($po, ['line_item_id' => $line->id, 'qty' => 5, 'damaged_qty' => $value / 6, 'damaged_reason' => 'broken']);

        return ReceivingIssue::firstOrFail();
    }

    public function test_a_claim_can_be_credited_in_full(): void
    {
        $issue = $this->claim();

        app(SupplierClaimService::class)->resolve($issue, 'credited', 12, 'credit note CN-9', $this->admin);

        $issue = $issue->fresh();
        $this->assertSame('credited', $issue->claim_status);
        $this->assertEquals(12.00, $issue->credited_amount);
        $this->assertSame($this->admin->id, $issue->resolved_by);
        $this->assertNotNull($issue->resolved_at);
        $this->assertEquals(0.0, $issue->outstanding());
    }

    public function test_a_partial_credit_closes_the_claim_at_what_was_actually_given(): void
    {
        $issue = $this->claim();

        app(SupplierClaimService::class)->resolve($issue, 'credited', 8, null, $this->admin);

        $this->assertEquals(8.00, $issue->fresh()->credited_amount);
        $this->assertSame('credited', $issue->fresh()->claim_status);
    }

    public function test_a_credit_cannot_exceed_the_claim(): void
    {
        $issue = $this->claim();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("can't be more than the claim");

        app(SupplierClaimService::class)->resolve($issue, 'credited', 12.01, null, $this->admin);
    }

    public function test_waiving_needs_a_reason(): void
    {
        $issue = $this->claim();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Say why the claim is being waived');

        app(SupplierClaimService::class)->resolve($issue, 'waived', null, '', $this->admin);
    }

    public function test_a_waived_claim_is_no_longer_owed(): void
    {
        $issue = $this->claim();

        app(SupplierClaimService::class)->resolve($issue, 'waived', null, 'goodwill — long-standing supplier', $this->admin);

        $this->assertSame('waived', $issue->fresh()->claim_status);
        $this->assertEquals(0.0, $issue->fresh()->outstanding());
    }

    public function test_a_settled_claim_cannot_be_settled_again(): void
    {
        $issue = $this->claim();
        app(SupplierClaimService::class)->resolve($issue, 'credited', 12, null, $this->admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been settled');

        app(SupplierClaimService::class)->resolve($issue, 'waived', null, 'changed my mind', $this->admin);
    }

    public function test_a_clerk_cannot_open_or_settle_a_claim(): void
    {
        $issue = $this->claim();

        Livewire::actingAs($this->clerk)->test(PurchaseOrderManager::class)
            ->call('openResolve', $issue->id)
            ->assertForbidden();

        $this->assertSame('owed', $issue->fresh()->claim_status);
    }

    public function test_an_admin_settles_a_claim_from_the_panel(): void
    {
        $issue = $this->claim();

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('openResolve', $issue->id)
            ->assertSet('resolveAmount', '12.00')
            ->set('resolveAmount', '10')
            ->call('submitResolve')
            ->assertHasNoErrors();

        $this->assertSame('credited', $issue->fresh()->claim_status);
        $this->assertEquals(10.00, $issue->fresh()->credited_amount);
    }

    public function test_an_invalid_settlement_shows_the_reason_and_changes_nothing(): void
    {
        $issue = $this->claim();

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('openResolve', $issue->id)
            ->set('resolveAction', 'waived')
            ->set('resolveNote', '')
            ->call('submitResolve')
            ->assertHasErrors('resolveNote');

        $this->assertSame('owed', $issue->fresh()->claim_status);
    }

    // ------------------------------------------------------ what people see

    public function test_the_details_panel_lists_claims_and_what_the_supplier_still_owes(): void
    {
        $issue = $this->claim();

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('view', $issue->purchase_order_id)
            ->assertSee('claims on Acme Supply')
            ->assertSee('broken')
            ->assertSee('Supplier still owes for this order')
            ->assertSee('12.00')
            ->assertSee('Owed')
            ->assertSee('2 damaged');
    }

    public function test_the_order_list_shows_damaged_and_short_next_to_each_product(): void
    {
        $issue = $this->claim();
        $issue->lineItem->update(['qty_closed_short' => 3]);

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->assertSee('2 damaged')
            ->assertSee('3 short');
    }

    // -------------------------------------------------------------- reports

    public function test_the_receiving_losses_report_lists_each_loss_with_its_claim_status(): void
    {
        $this->claim();

        Livewire::actingAs($this->admin)->test(ReportViewer::class)
            ->call('selectReport', 'receiving_losses')
            ->assertSee('Acme Supply')
            ->assertSee('Glass Bottle')
            ->assertSee('damaged')
            ->assertSee('12.00')
            ->assertSee('owed');
    }

    public function test_the_supplier_claims_report_totals_what_each_supplier_owes(): void
    {
        $issue = $this->claim();                                              // 12.00 owed
        [$po, $line] = $this->order();
        $this->receive($po, ['line_item_id' => $line->id, 'qty' => 6, 'close_short' => true, 'short_reason' => 'out of stock']);   // 24.00 missing
        app(SupplierClaimService::class)->resolve($issue, 'credited', 12, null, $this->admin);

        Livewire::actingAs($this->admin)->test(ReportViewer::class)
            ->call('selectReport', 'supplier_claims')
            ->assertSee('Acme Supply')
            ->assertSee('24.00')      // still owed: only the missing units
            ->assertSee('36.00');     // total claimed
    }

    public function test_the_claims_reports_are_hidden_without_purchase_access(): void
    {
        $cashier = User::factory()->cashier()->create();

        Livewire::actingAs($cashier)->test(ReportViewer::class)
            ->assertDontSee('Supplier Claims')
            ->assertDontSee('Receiving Losses');
    }
}
