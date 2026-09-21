<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\DashboardOverview;
use App\Livewire\Inventory\InventoryOverview;
use App\Models\Batch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class StockValuationTabTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private function stocked(string $name, float $price, float $cost, float $qty, array $extra = []): Product
    {
        $product = Product::factory()->create(array_merge(['name' => $name, 'selling_price' => $price], $extra));
        Batch::factory()->for($product)->remaining($qty)->create(['unit_cost' => $cost]);

        return $product;
    }

    public function test_rows_and_totals_are_per_product_selling_value_minus_batch_cost(): void
    {
        $this->stocked('Alpha Item', 10, 6, 10);   // value 100, cost 60, profit 40
        $this->stocked('Beta Item', 20, 15, 4);    // value 80,  cost 60, profit 20

        Livewire::actingAs(User::factory()->create())->test(InventoryOverview::class)
            ->call('setTab', 'valuation')
            ->assertSee('Alpha Item')
            ->assertSee('Beta Item')
            ->assertSee('180.00')   // total selling value
            ->assertSee('120.00')   // total cost
            ->assertSee('60.00');   // total profit
    }

    public function test_the_totals_row_matches_the_dashboard_cards(): void
    {
        $this->stocked('Alpha Item', 10, 6, 10);
        $this->stocked('Promo Item', 20, 12, 5, ['promo_discount_type' => 'percentage', 'promo_discount_value' => 25]);
        $admin = User::factory()->create();

        $expectedValue = number_format((float) DB::table('v_inventory_valuation')->sum('value_at_selling_price'), 2);
        $expectedProfit = number_format((float) DB::table('v_inventory_valuation')->sum('estimated_gross_profit'), 2);

        Livewire::actingAs($admin)->test(DashboardOverview::class)
            ->assertSee($expectedValue)
            ->assertSee($expectedProfit);

        Livewire::actingAs($admin)->test(InventoryOverview::class)
            ->call('setTab', 'valuation')
            ->assertSee($expectedValue)
            ->assertSee($expectedProfit);
    }

    public function test_a_running_promo_lowers_the_selling_value(): void
    {
        // 10 units @ 20 with 25% off = 15 charged -> value 150, cost 120, profit 30.
        $this->stocked('Promo Item', 20, 12, 10, ['promo_discount_type' => 'percentage', 'promo_discount_value' => 25]);

        Livewire::actingAs(User::factory()->create())->test(InventoryOverview::class)
            ->call('setTab', 'valuation')
            ->assertSee('150.00')
            ->assertSee('30.00')
            ->assertDontSee('200.00');
    }

    public function test_search_filters_rows_and_totals_and_says_so(): void
    {
        $this->stocked('Alpha Item', 10, 6, 10);
        $this->stocked('Beta Item', 20, 15, 4);

        Livewire::actingAs(User::factory()->create())->test(InventoryOverview::class)
            ->call('setTab', 'valuation')
            ->set('valuationSearch', 'Alpha')
            ->assertSee('Alpha Item')
            ->assertDontSee('Beta Item')
            ->assertSee('Total (filtered)')
            ->assertSee('100.00');
    }

    public function test_out_of_stock_products_are_hidden_unless_asked_for(): void
    {
        $this->stocked('Stocked Item', 10, 6, 5);
        Product::factory()->create(['name' => 'Empty Item']);

        $component = Livewire::actingAs(User::factory()->create())->test(InventoryOverview::class)
            ->call('setTab', 'valuation')
            ->assertSee('Stocked Item')
            ->assertDontSee('Empty Item');

        $component->set('valuationInStockOnly', false)->assertSee('Empty Item');
    }

    public function test_a_cashier_gets_no_valuation_tab_even_via_the_url(): void
    {
        $this->stocked('Alpha Item', 10, 6, 10);
        $cashier = User::factory()->cashier()->create();

        Livewire::actingAs($cashier)->withQueryParams(['tab' => 'valuation'])->test(InventoryOverview::class)
            ->assertDontSee('Stock Valuation')
            ->assertDontSee('Value at Cost')
            ->assertSet('activeTab', 'stock');
    }
}
