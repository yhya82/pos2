<?php

namespace Tests\Feature\Services;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\SaleService;
use RuntimeException;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class SaleServiceTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private SaleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SaleService::class);
    }

    private function cashMethod(): PaymentMethod
    {
        return PaymentMethod::where('code', 'cash')->firstOrFail();
    }

    private function creditMethod(): PaymentMethod
    {
        return PaymentMethod::where('code', 'credit')->firstOrFail();
    }

    public function test_completing_a_cash_sale_deducts_stock_and_records_everything(): void
    {
        $cashier = User::factory()->create();
        $product = Product::factory()->create(['selling_price' => 10]);
        $batch = Batch::factory()->for($product)->remaining(50)->create();

        $sale = $this->service->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => 3]],
            customerId: null,
            paymentMethodId: $this->cashMethod()->id,
            referenceNumber: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: $cashier,
        );

        $this->assertSame('completed', $sale->status);
        $this->assertEquals(30.00, $sale->total_amount);
        $this->assertSame(1, $sale->lineItems()->count());

        $this->assertEquals(47, $batch->fresh()->qty_remaining);

        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'movement_type' => 'sale',
            'quantity' => -3,
        ]);

        $this->assertNotNull($sale->payment);
        $this->assertNotNull($sale->receipt);
        $this->assertTrue(AuditLog::where('module', 'sales')->where('record_id', $sale->id)->where('action', 'create')->exists());
    }

    public function test_cannot_complete_a_sale_with_an_empty_cart(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot complete a sale with no items.');

        $this->service->completeSale(
            cartLines: [],
            customerId: null,
            paymentMethodId: $this->cashMethod()->id,
            referenceNumber: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: User::factory()->create(),
        );
    }

    public function test_insufficient_stock_blocks_the_sale_by_default(): void
    {
        $product = Product::factory()->create();
        Batch::factory()->for($product)->remaining(2)->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough stock');

        $this->service->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => 5]],
            customerId: null,
            paymentMethodId: $this->cashMethod()->id,
            referenceNumber: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: User::factory()->create(),
        );
    }

    public function test_deduction_follows_fefo_soonest_expiry_first(): void
    {
        $product = Product::factory()->create();

        $soon = Batch::factory()->for($product)->remaining(10)->expiringOn(now()->addDays(5)->toDateString())->create();
        $later = Batch::factory()->for($product)->remaining(10)->expiringOn(now()->addDays(30)->toDateString())->create();

        $this->service->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => 4]],
            customerId: null,
            paymentMethodId: $this->cashMethod()->id,
            referenceNumber: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: User::factory()->create(),
        );

        $this->assertEquals(6, $soon->fresh()->qty_remaining);
        $this->assertEquals(10, $later->fresh()->qty_remaining);
    }

    public function test_discount_over_the_configured_cap_is_rejected(): void
    {
        SalesSetting::current()->update(['max_discount_percentage' => 10]);

        $product = Product::factory()->create(['selling_price' => 100]);
        Batch::factory()->for($product)->remaining(10)->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Discount exceeds the maximum allowed');

        $this->service->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => 1]],
            customerId: null,
            paymentMethodId: $this->cashMethod()->id,
            referenceNumber: null,
            discountType: 'percentage',
            discountValue: 50,
            discountReason: 'too generous',
            cashier: User::factory()->create(),
        );
    }

    public function test_credit_sale_requires_a_customer(): void
    {
        $product = Product::factory()->create();
        Batch::factory()->for($product)->remaining(10)->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Credit sales require a customer');

        $this->service->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => 1]],
            customerId: null,
            paymentMethodId: $this->creditMethod()->id,
            referenceNumber: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: User::factory()->create(),
        );
    }

    public function test_credit_sale_exceeding_the_limit_is_rejected(): void
    {
        $customer = Customer::factory()->withCredit(50)->create();
        $product = Product::factory()->create(['selling_price' => 100]);
        Batch::factory()->for($product)->remaining(10)->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('would exceed');

        $this->service->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => 1]],
            customerId: $customer->id,
            paymentMethodId: $this->creditMethod()->id,
            referenceNumber: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: User::factory()->create(),
        );
    }

    public function test_credit_sale_within_the_limit_charges_the_customer(): void
    {
        $customer = Customer::factory()->withCredit(200)->create();
        $product = Product::factory()->create(['selling_price' => 50]);
        Batch::factory()->for($product)->remaining(10)->create();

        $sale = $this->service->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => 2]],
            customerId: $customer->id,
            paymentMethodId: $this->creditMethod()->id,
            referenceNumber: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: User::factory()->create(),
        );

        $this->assertEquals(100, $customer->fresh()->outstanding_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'type' => 'credit_sale',
            'amount' => 100,
        ]);
    }

    public function test_voiding_a_sale_reverses_stock_via_the_schemas_own_trigger(): void
    {
        $cashier = User::factory()->create();
        $product = Product::factory()->create(['selling_price' => 10]);
        $batch = Batch::factory()->for($product)->remaining(20)->create();

        $sale = $this->service->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => 5]],
            customerId: null,
            paymentMethodId: $this->cashMethod()->id,
            referenceNumber: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: $cashier,
        );

        $this->assertEquals(15, $batch->fresh()->qty_remaining);

        $this->service->voidSale($sale, 'customer changed their mind', $cashier);

        $this->assertSame('voided', $sale->fresh()->status);
        $this->assertEquals(20, $batch->fresh()->qty_remaining, 'trg_sales_void_reverses_inventory should restore the batch quantity');

        $this->assertDatabaseHas('inventory_movements', [
            'batch_id' => $batch->id,
            'movement_type' => 'adjustment',
            'quantity' => 5,
        ]);
    }
}
