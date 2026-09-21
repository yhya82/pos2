<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class InventoryValuationTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    /**
     * v_inventory_valuation reimplements Product::effectiveSellingPrice()
     * in SQL — these cases pin the two to the same answer.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function promoCases(): array
    {
        return [
            'no promo' => [['promo_discount_type' => 'none']],
            'percentage, always on' => [['promo_discount_type' => 'percentage', 'promo_discount_value' => 15]],
            'fixed, always on' => [['promo_discount_type' => 'fixed', 'promo_discount_value' => 3.5]],
            'fixed larger than price floors at zero' => [['promo_discount_type' => 'fixed', 'promo_discount_value' => 999]],
            'not started yet' => [['promo_discount_type' => 'percentage', 'promo_discount_value' => 50, 'promo_starts_at' => '2999-01-01']],
            'already ended' => [['promo_discount_type' => 'percentage', 'promo_discount_value' => 50, 'promo_ends_at' => '2000-01-01']],
            'inside its window' => [['promo_discount_type' => 'fixed', 'promo_discount_value' => 2, 'promo_starts_at' => '2000-01-01', 'promo_ends_at' => '2999-01-01']],
        ];
    }

    #[DataProvider('promoCases')]
    public function test_view_selling_side_matches_the_products_effective_price(array $promo): void
    {
        $product = Product::factory()->create(array_merge(['selling_price' => 20.00], $promo));
        Batch::factory()->for($product)->remaining(10)->create(['unit_cost' => 12.00]);

        $row = DB::table('v_inventory_valuation')->where('product_id', $product->id)->first();

        $expectedSelling = round($product->fresh()->effectiveSellingPrice() * 10, 2);

        $this->assertEqualsWithDelta($expectedSelling, (float) $row->value_at_selling_price, 0.001);
        $this->assertEqualsWithDelta(200.00, (float) $row->value_at_list_price, 0.001);
        $this->assertEqualsWithDelta(120.00, (float) $row->value_at_cost, 0.001);
        $this->assertEqualsWithDelta($expectedSelling - 120.00, (float) $row->estimated_gross_profit, 0.001);
    }

    /**
     * The view decides "is the promo on today" with UTC_DATE(); PHP decides
     * it with now() in the app timezone. They only agree while the app
     * runs in UTC — this fails loudly if that ever changes.
     */
    public function test_the_database_and_the_app_agree_on_todays_date(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame(now()->toDateString(), DB::selectOne('SELECT UTC_DATE() AS d')->d);
    }

    public function test_a_promo_is_active_on_both_its_first_and_last_day(): void
    {
        $today = now()->toDateString();

        foreach (['starts today' => ['promo_starts_at' => $today], 'ends today' => ['promo_ends_at' => $today]] as $window) {
            $product = Product::factory()->create(array_merge([
                'selling_price' => 20.00,
                'promo_discount_type' => 'fixed',
                'promo_discount_value' => 5,
            ], $window));
            Batch::factory()->for($product)->remaining(1)->create(['unit_cost' => 10.00]);

            $row = DB::table('v_inventory_valuation')->where('product_id', $product->id)->first();

            $this->assertEqualsWithDelta(15.00, (float) $row->value_at_selling_price, 0.001);
            $this->assertTrue($product->fresh()->hasActivePromo());
        }
    }

    public function test_only_active_batches_count_toward_the_estimate(): void
    {
        $product = Product::factory()->create(['selling_price' => 10.00]);
        Batch::factory()->for($product)->remaining(5)->create(['unit_cost' => 4.00]);
        Batch::factory()->for($product)->remaining(5)->create(['unit_cost' => 4.00, 'status' => 'expired']);

        $row = DB::table('v_inventory_valuation')->where('product_id', $product->id)->first();

        $this->assertEqualsWithDelta(30.00, (float) $row->estimated_gross_profit, 0.001);
    }
}
