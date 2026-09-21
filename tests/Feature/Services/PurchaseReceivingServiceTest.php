<?php

namespace Tests\Feature\Services;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseReceivingService;
use RuntimeException;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class PurchaseReceivingServiceTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private PurchaseReceivingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PurchaseReceivingService::class);
    }

    private function makeOrderWithLine(float $qtyOrdered = 100): array
    {
        $product = Product::factory()->create();
        $supplier = Supplier::create(['name' => 'Test Supplier', 'status' => 'active']);
        $user = User::factory()->create();

        $po = PurchaseOrder::create([
            'po_number' => PurchaseOrder::generatePoNumber(),
            'supplier_id' => $supplier->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        $line = $po->lineItems()->create([
            'product_id' => $product->id,
            'qty_ordered' => $qtyOrdered,
            'purchase_unit_id' => $product->purchase_unit_id,
            'cost_price' => $product->cost_price,
        ]);

        return [$po, $line, $product, $user];
    }

    public function test_receiving_creates_a_batch_and_marks_the_order_partially_received(): void
    {
        [$po, $line, $product, $user] = $this->makeOrderWithLine(100);

        $this->service->receive($po, [
            ['line_item_id' => $line->id, 'qty' => 40, 'batch_code' => 'B1'],
        ], $user);

        $this->assertDatabaseHas('batches', [
            'product_id' => $product->id,
            'batch_code' => 'B1',
            'qty_received' => 40,
            'qty_remaining' => 40,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'movement_type' => 'stock_received',
            'quantity' => 40,
        ]);

        $this->assertEquals(40, $line->fresh()->qty_received);
        $this->assertSame('partially_received', $po->fresh()->status);
    }

    public function test_receiving_the_full_ordered_quantity_marks_the_order_received(): void
    {
        [$po, $line, , $user] = $this->makeOrderWithLine(25);

        $this->service->receive($po, [
            ['line_item_id' => $line->id, 'qty' => 25],
        ], $user);

        $this->assertSame('received', $po->fresh()->status);
    }

    public function test_cannot_receive_more_than_the_remaining_quantity(): void
    {
        [$po, $line, , $user] = $this->makeOrderWithLine(10);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('remaining on this order');

        $this->service->receive($po, [
            ['line_item_id' => $line->id, 'qty' => 15],
        ], $user);
    }

    public function test_multiple_partial_receipts_accumulate_correctly(): void
    {
        [$po, $line, , $user] = $this->makeOrderWithLine(50);

        $this->service->receive($po, [['line_item_id' => $line->id, 'qty' => 20]], $user);
        $this->service->receive($po, [['line_item_id' => $line->id, 'qty' => 30]], $user);

        $this->assertEquals(50, $line->fresh()->qty_received);
        $this->assertSame('received', $po->fresh()->status);
        $this->assertSame(2, \App\Models\Batch::where('product_id', $line->product_id)->count());
    }
}
