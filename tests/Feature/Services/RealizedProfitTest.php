<?php

namespace Tests\Feature\Services;

use App\Models\Batch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\ReturnService;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * Pins v_realized_profit_lines: revenue net of sale-level discounts and
 * refunds, cost from the batches actually sold, sellable returns recovering
 * cost, damaged returns keeping it as a loss, voids excluded.
 */
class RealizedProfitTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private function sell(Product $product, float $qty, string $discountType = 'none', float $discountValue = 0, ?User $cashier = null): Sale
    {
        return app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => $qty]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            referenceNumber: null,
            discountType: $discountType,
            discountValue: $discountValue,
            discountReason: $discountType === 'none' ? null : 'test',
            cashier: $cashier ?? User::factory()->create(),
        );
    }

    private function profitFor(Sale $sale): ?object
    {
        return DB::table('v_realized_profit_lines')->where('sale_id', $sale->id)->first();
    }

    public function test_plain_sale_profit_is_price_minus_batch_cost(): void
    {
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(50)->create(['unit_cost' => 6]);

        $row = $this->profitFor($this->sell($product, 5));

        $this->assertEqualsWithDelta(50.00, (float) $row->net_revenue, 0.001);
        $this->assertEqualsWithDelta(30.00, (float) $row->net_cost, 0.001);
        $this->assertEqualsWithDelta(20.00, (float) $row->profit, 0.001);
    }

    public function test_sale_spanning_two_batches_is_costed_at_each_batchs_own_cost(): void
    {
        $product = Product::factory()->create(['selling_price' => 10]);
        // FEFO/received-date order: the older batch is drawn first.
        Batch::factory()->for($product)->remaining(2)->create(['unit_cost' => 4, 'received_date' => '2026-01-01']);
        Batch::factory()->for($product)->remaining(10)->create(['unit_cost' => 7, 'received_date' => '2026-02-01']);

        $row = $this->profitFor($this->sell($product, 5));

        // 2 units @ 4 + 3 units @ 7 = 29 cost; 50 revenue.
        $this->assertEqualsWithDelta(29.00, (float) $row->net_cost, 0.001);
        $this->assertEqualsWithDelta(21.00, (float) $row->profit, 0.001);
    }

    public function test_an_older_sale_that_carried_a_discount_still_reports_its_profit_correctly(): void
    {
        // New sales can't carry a discount, but ones made before that rule still exist
        // and must keep reporting as they always did — so build one the way it would be stored.
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(50)->create(['unit_cost' => 6]);

        $sale = $this->sell($product, 5);
        DB::table('sales')->where('id', $sale->id)->update([
            'discount_type' => 'fixed', 'discount_value' => 10, 'discount_amount' => 10,
            'discount_reason' => 'legacy', 'discount_applied_by' => $sale->cashier_id, 'total_amount' => 40,
        ]);

        $row = $this->profitFor($sale);

        $this->assertEqualsWithDelta(40.00, (float) $row->net_revenue, 0.001);
        $this->assertEqualsWithDelta(10.00, (float) $row->profit, 0.001);
    }

    public function test_promo_price_is_what_counts_as_revenue(): void
    {
        $product = Product::factory()->create([
            'selling_price' => 10,
            'promo_discount_type' => 'percentage',
            'promo_discount_value' => 20,
        ]);
        Batch::factory()->for($product)->remaining(50)->create(['unit_cost' => 6]);

        $row = $this->profitFor($this->sell($product, 5));

        // Charged 8.00 each, not the 10.00 list price: 40 revenue - 30 cost.
        $this->assertEqualsWithDelta(40.00, (float) $row->net_revenue, 0.001);
        $this->assertEqualsWithDelta(10.00, (float) $row->profit, 0.001);
    }

    public function test_sellable_return_refunds_revenue_and_recovers_cost(): void
    {
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(50)->create(['unit_cost' => 6]);
        $cashier = User::factory()->create();
        $sale = $this->sell($product, 5, cashier: $cashier);

        app(ReturnService::class)->processReturn(
            $sale,
            [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 2, 'condition_type' => 'sellable']],
            'changed mind',
            $cashier,
        );

        $row = $this->profitFor($sale);

        // Kept 3 of 5 units: 30 revenue, 18 cost (2 units back on the shelf).
        $this->assertEqualsWithDelta(30.00, (float) $row->net_revenue, 0.001);
        $this->assertEqualsWithDelta(18.00, (float) $row->net_cost, 0.001);
        $this->assertEqualsWithDelta(12.00, (float) $row->profit, 0.001);
    }

    public function test_partial_return_from_a_mixed_cost_line_recovers_the_exact_batch_costs(): void
    {
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(2)->create(['unit_cost' => 4, 'received_date' => '2026-01-01']);
        Batch::factory()->for($product)->remaining(10)->create(['unit_cost' => 7, 'received_date' => '2026-02-01']);
        $cashier = User::factory()->create();

        // 2 units @ 4 + 3 units @ 7 = 29 cost.
        $sale = $this->sell($product, 5, cashier: $cashier);

        // Returning 3 puts back the 2 cheap units and 1 dear one: 2*4 + 1*7 = 15
        // recovered (an average-cost estimate would have said 17.40).
        app(ReturnService::class)->processReturn(
            $sale,
            [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 3, 'condition_type' => 'sellable']],
            'changed mind',
            $cashier,
        );

        $row = $this->profitFor($sale);

        $this->assertEqualsWithDelta(20.00, (float) $row->net_revenue, 0.001);
        $this->assertEqualsWithDelta(14.00, (float) $row->net_cost, 0.001);
        $this->assertEqualsWithDelta(6.00, (float) $row->profit, 0.001);
    }

    public function test_returns_without_batch_rows_fall_back_to_the_average_cost(): void
    {
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(50)->create(['unit_cost' => 6]);
        $cashier = User::factory()->create();
        $sale = $this->sell($product, 5, cashier: $cashier);

        app(ReturnService::class)->processReturn(
            $sale,
            [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 2, 'condition_type' => 'sellable']],
            'changed mind',
            $cashier,
        );

        // Simulate a return made before batch tracking existed.
        DB::table('sales_return_line_item_batches')->delete();

        $row = $this->profitFor($sale);

        $this->assertEqualsWithDelta(18.00, (float) $row->net_cost, 0.001);
    }

    public function test_damaged_return_refunds_revenue_but_keeps_the_cost_as_a_loss(): void
    {
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(50)->create(['unit_cost' => 6]);
        $cashier = User::factory()->create();
        $sale = $this->sell($product, 5, cashier: $cashier);

        app(ReturnService::class)->processReturn(
            $sale,
            [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 2, 'condition_type' => 'damaged']],
            'broken',
            $cashier,
        );

        $row = $this->profitFor($sale);

        // 30 revenue kept, but all 30 of cost stays (the 2 units are written off).
        $this->assertEqualsWithDelta(30.00, (float) $row->net_revenue, 0.001);
        $this->assertEqualsWithDelta(30.00, (float) $row->net_cost, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $row->profit, 0.001);
    }

    public function test_voided_sales_are_excluded(): void
    {
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(50)->create(['unit_cost' => 6]);
        $cashier = User::factory()->create();
        $sale = $this->sell($product, 5, cashier: $cashier);

        app(SaleService::class)->voidSale($sale, 'mistake', $cashier);

        $this->assertNull($this->profitFor($sale));
    }
}
