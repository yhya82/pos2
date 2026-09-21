<?php

namespace Tests\Feature;

use App\Livewire\Products\ProductProfile;
use App\Models\Batch;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * Add Stock opens with the product's current cost and selling price filled in,
 * both editable, so a delivery at a different cost/price is one save rather
 * than an add-stock followed by two separate corrections.
 */
class AddStockPricingTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        // Sold per piece, bought per carton of 12. Cost is stored per piece.
        $this->product = Product::factory()->create([
            'selling_price' => 10,
            'cost_price' => 6,
            'conversion_qty' => 12,
            'purchase_unit_id' => Unit::factory()->create()->id,
            'selling_unit_id' => Unit::factory()->create()->id,
        ]);
    }

    private function form()
    {
        return Livewire::actingAs($this->admin)->test(ProductProfile::class, ['product' => $this->product])
            ->call('openAddStockForm');
    }

    public function test_it_opens_with_the_current_cost_and_price_filled_in(): void
    {
        $this->form()
            ->assertSet('addStockUnitCost', '72')        // 6 per piece x 12 per carton
            ->assertSet('addStockSellingPrice', '10');
    }

    public function test_switching_the_unit_keeps_the_cost_meaning_the_same_money(): void
    {
        $this->form()
            ->set('addStockQtyUnit', 'selling')
            ->assertSet('addStockUnitCost', '6')
            ->set('addStockQtyUnit', 'purchase')
            ->assertSet('addStockUnitCost', '72');
    }

    public function test_an_edited_cost_survives_a_unit_switch_converted(): void
    {
        $this->form()
            ->set('addStockUnitCost', '96')             // 8 per piece
            ->set('addStockQtyUnit', 'selling')
            ->assertSet('addStockUnitCost', '8');
    }

    public function test_adding_stock_with_the_prefilled_values_changes_nothing_on_the_product(): void
    {
        $this->form()
            ->set('addStockQty', '2')
            ->call('submitAddStock')
            ->assertHasNoErrors();

        $product = $this->product->fresh();
        $this->assertEquals(10, $product->selling_price);
        $this->assertEquals(6, $product->cost_price);
        $this->assertEquals(6, Batch::where('product_id', $product->id)->value('unit_cost'));
    }

    public function test_a_different_cost_and_price_update_the_batch_and_the_product_together(): void
    {
        $this->form()
            ->set('addStockQty', '2')
            ->set('addStockUnitCost', '96')             // 8 per piece
            ->set('addStockSellingPrice', '14')
            ->call('submitAddStock')
            ->assertHasNoErrors();

        $product = $this->product->fresh();
        $this->assertEquals(14, $product->selling_price);
        $this->assertEquals(8, $product->cost_price);
        $this->assertEquals(8, Batch::where('product_id', $product->id)->value('unit_cost'));
        $this->assertDatabaseHas('audit_logs', ['record_type' => 'Product', 'record_id' => $product->id, 'action' => 'update']);
    }

    public function test_it_shows_the_profit_and_flags_a_price_change_as_it_is_typed(): void
    {
        $this->form()
            ->set('addStockSellingPrice', '14')
            ->assertSee('This changes the product')
            ->assertSee('Profit per')
            ->assertSee('8.00')                         // 14 - 6
            ->set('addStockUnitCost', '168')            // 14 per piece = the new price
            ->assertSee('there\'d be no profit', false);
    }

    public function test_a_price_at_or_below_the_cost_is_refused_and_no_stock_is_added(): void
    {
        // Price 5 with a 6-per-piece cost: cost would sit above the price.
        $this->form()
            ->set('addStockQty', '2')
            ->set('addStockSellingPrice', '5')
            ->call('submitAddStock')
            ->assertHasErrors('addStockSellingPrice');

        $this->assertSame(0, Batch::where('product_id', $this->product->id)->count());
        $this->assertEquals(10, $this->product->fresh()->selling_price);
    }

    public function test_a_price_of_zero_is_refused(): void
    {
        $this->form()
            ->set('addStockQty', '2')
            ->set('addStockSellingPrice', '0')
            ->call('submitAddStock')
            ->assertHasErrors('addStockSellingPrice');
    }

    public function test_a_promo_cant_be_left_below_cost_by_the_new_price(): void
    {
        $this->product->update(['promo_discount_type' => 'percentage', 'promo_discount_value' => 50]);

        // Price 11 -> promo 5.50, below the 6 cost.
        $this->form()
            ->set('addStockQty', '2')
            ->set('addStockSellingPrice', '11')
            ->call('submitAddStock')
            ->assertHasErrors('addStockSellingPrice');

        $this->assertSame(0, Batch::where('product_id', $this->product->id)->count());
    }

    public function test_a_higher_cost_with_a_higher_price_is_one_save(): void
    {
        // Supplier went up to 11 per piece; price raised to 15 in the same save.
        $this->form()
            ->set('addStockQty', '2')
            ->set('addStockUnitCost', '132')
            ->set('addStockSellingPrice', '15')
            ->call('submitAddStock')
            ->assertHasNoErrors();

        $product = $this->product->fresh();
        $this->assertEquals(15, $product->selling_price);
        $this->assertEquals(11, $product->cost_price);
    }
}
