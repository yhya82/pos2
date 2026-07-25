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

    public function test_cannot_return_against_a_non_completed_sale(): void
    {
        [$sale, , , $cashier] = $this->makeCompletedSale(stock: 20, soldQty: 5);
        $lineItem = $sale->lineItems()->first();

        $this->saleService->voidSale($sale, 'test void', $cashier);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only completed sales');

        $this->service->processReturn(
            $sale->fresh(),
            [['sale_line_item_id' => $lineItem->id, 'quantity' => 1, 'condition_type' => 'sellable']],
            null,
            $cashier,
        );
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
