<?php

namespace Tests\Feature\Services;

use App\Models\Batch;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\ReturnService;
use App\Services\SaleService;
use RuntimeException;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class ReturnServiceTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private ReturnService $service;

    private SaleService $saleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ReturnService::class);
        $this->saleService = app(SaleService::class);
    }

    /**
     * @return array{0: Sale, 1: Product, 2: Batch, 3: User}
     */
    private function makeCompletedSale(float $stock = 20, float $soldQty = 5, ?int $customerId = null, ?string $paymentCode = 'cash'): array
    {
        $cashier = User::factory()->create();
        $product = Product::factory()->create(['selling_price' => 10]);
        $batch = Batch::factory()->for($product)->remaining($stock)->create();

        $sale = $this->saleService->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => $soldQty]],
            customerId: $customerId,
            paymentMethodId: PaymentMethod::where('code', $paymentCode)->firstOrFail()->id,
            referenceNumber: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: $cashier,
        );

        return [$sale, $product, $batch, $cashier];
    }

    public function test_sellable_return_restores_stock_and_refunds_the_sale(): void
    {
        [$sale, , $batch, $cashier] = $this->makeCompletedSale(stock: 20, soldQty: 5);
        $lineItem = $sale->lineItems()->first();

        $this->assertEquals(15, $batch->fresh()->qty_remaining);

        $return = $this->service->processReturn(
            $sale,
            [['sale_line_item_id' => $lineItem->id, 'quantity' => 2, 'condition_type' => 'sellable']],
            'customer changed their mind',
            $cashier,
        );

        $this->assertEquals(17, $batch->fresh()->qty_remaining);
        $this->assertEquals(20.00, $return->refund_amount);
        $this->assertSame('refunded', $sale->fresh()->status, 'trg_sales_returns_sync_status_ins should flip the sale to refunded');
        $this->assertNotNull($return->fresh()->receipt);

        $this->assertDatabaseHas('inventory_movements', [
            'batch_id' => $batch->id,
            'movement_type' => 'return',
            'quantity' => 2,
        ]);
    }

    public function test_damaged_return_restores_then_writes_off_leaving_quantity_unchanged(): void
    {
        [$sale, , $batch, $cashier] = $this->makeCompletedSale(stock: 20, soldQty: 5);
        $lineItem = $sale->lineItems()->first();

        $this->assertEquals(15, $batch->fresh()->qty_remaining);

        $this->service->processReturn(
            $sale,
            [['sale_line_item_id' => $lineItem->id, 'quantity' => 3, 'condition_type' => 'damaged']],
            'arrived broken',
            $cashier,
        );

        // Restored (+3) then immediately written off (-3) — net zero change,
        // but as two separate, auditable movements, not one silent no-op.
        $this->assertEquals(15, $batch->fresh()->qty_remaining);

        $this->assertDatabaseHas('inventory_movements', [
            'batch_id' => $batch->id,
            'movement_type' => 'return',
            'quantity' => 3,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'batch_id' => $batch->id,
            'movement_type' => 'damaged',
            'quantity' => -3,
        ]);
    }

    public function test_cannot_return_more_than_was_sold(): void
    {
        [$sale, , , $cashier] = $this->makeCompletedSale(stock: 20, soldQty: 5);
        $lineItem = $sale->lineItems()->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only 5 eligible');

        $this->service->processReturn(
            $sale,
            [['sale_line_item_id' => $lineItem->id, 'quantity' => 10, 'condition_type' => 'sellable']],
            null,
            $cashier,
        );
    }

    public function test_cannot_return_against_a_voided_sale(): void
    {
        [$sale, , , $cashier] = $this->makeCompletedSale(stock: 20, soldQty: 5);
        $lineItem = $sale->lineItems()->first();

        $this->saleService->voidSale($sale, 'test void', $cashier);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only completed or already-refunded sales');

        $this->service->processReturn(
            $sale->fresh(),
            [['sale_line_item_id' => $lineItem->id, 'quantity' => 1, 'condition_type' => 'sellable']],
            null,
            $cashier,
        );
    }

    public function test_a_second_partial_return_is_allowed_against_an_already_refunded_sale(): void
    {
        // One partial return flips the sale's status to 'refunded' via
        // the schema's own trigger — that alone must not block a second,
        // later return against whatever's still eligible.
        [$sale, , $batch, $cashier] = $this->makeCompletedSale(stock: 20, soldQty: 5);
        $lineItem = $sale->lineItems()->first();

        $this->service->processReturn(
            $sale,
            [['sale_line_item_id' => $lineItem->id, 'quantity' => 2, 'condition_type' => 'sellable']],
            'first partial return',
            $cashier,
        );

        $this->assertSame('refunded', $sale->fresh()->status);

        $second = $this->service->processReturn(
            $sale->fresh(),
            [['sale_line_item_id' => $lineItem->id, 'quantity' => 1, 'condition_type' => 'sellable']],
            'second partial return',
            $cashier,
        );

        $this->assertEquals(10.00, $second->refund_amount);
        $this->assertEquals(18, $batch->fresh()->qty_remaining);
        $this->assertEquals(2, $lineItem->fresh()->remainingReturnableQty());
    }

    public function test_a_fully_returned_sale_has_nothing_left_to_return(): void
    {
        [$sale, , , $cashier] = $this->makeCompletedSale(stock: 20, soldQty: 5);
        $lineItem = $sale->lineItems()->first();

        $this->service->processReturn(
            $sale,
            [['sale_line_item_id' => $lineItem->id, 'quantity' => 5, 'condition_type' => 'sellable']],
            'fully returned',
            $cashier,
        );

        $this->assertSame([], $this->service->returnableLines($sale->fresh(['lineItems.product'])));
    }

    public function test_returnable_lines_reports_the_original_and_already_returned_quantities(): void
    {
        [$sale, , , $cashier] = $this->makeCompletedSale(stock: 20, soldQty: 5);
        $lineItem = $sale->lineItems()->first();

        $this->service->processReturn(
            $sale,
            [['sale_line_item_id' => $lineItem->id, 'quantity' => 2, 'condition_type' => 'sellable']],
            'partial return',
            $cashier,
        );

        $lines = $this->service->returnableLines($sale->fresh(['lineItems.product']));

        $this->assertSame(5.0, $lines[0]['original_qty']);
        $this->assertSame(2.0, $lines[0]['already_returned']);
        $this->assertSame(3.0, $lines[0]['max_returnable']);
    }

    public function test_the_per_line_reason_is_no_longer_collected(): void
    {
        [$sale, , , $cashier] = $this->makeCompletedSale(stock: 20, soldQty: 5);
        $lineItem = $sale->lineItems()->first();

        $this->service->processReturn(
            $sale,
            [['sale_line_item_id' => $lineItem->id, 'quantity' => 2, 'condition_type' => 'sellable']],
            'overall reason only',
            $cashier,
        );

        $this->assertDatabaseHas('sales_return_line_items', [
            'sale_line_item_id' => $lineItem->id,
            'reason' => null,
        ]);
    }

    public function test_returning_a_credit_sale_refunds_the_customers_balance(): void
    {
        $customer = Customer::factory()->withCredit(500)->create();

        [$sale, , , $cashier] = $this->makeCompletedSale(stock: 20, soldQty: 5, customerId: $customer->id, paymentCode: 'credit');
        $lineItem = $sale->lineItems()->first();

        $this->assertEquals(50, $customer->fresh()->outstanding_balance);

        $this->service->processReturn(
            $sale,
            [['sale_line_item_id' => $lineItem->id, 'quantity' => 2, 'condition_type' => 'sellable']],
            'refund to credit',
            $cashier,
        );

        $this->assertEquals(30, $customer->fresh()->outstanding_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'customer_id' => $customer->id,
            'type' => 'payment',
            'amount' => 20,
        ]);
    }
}
