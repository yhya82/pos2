<?php

namespace Tests\Feature;

use App\Livewire\Pos\Terminal;
use App\Models\Batch;
use App\Models\Product;
use App\Models\SalesSetting;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * Clicking an out-of-stock product on the till should say so, not add it to
 * the cart — the guard itself runs client-side in Alpine, so these pin the
 * two things the server is responsible for: reporting the right stock
 * number per product, and shipping the guard/setting the click relies on.
 */
class PosOutOfStockTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->cashier()->create();
    }

    public function test_a_depleted_product_is_reported_to_the_till_as_zero_stock(): void
    {
        $product = Product::factory()->create(['name' => 'Sold Out Thing']);
        Batch::factory()->for($product)->remaining(0)->create(['qty_received' => 10]);

        $products = Livewire::actingAs($this->cashier)->test(Terminal::class)->viewData('products');

        $this->assertSame(0.0, $products->firstWhere('id', $product->id)['stock_quantity']);
    }

    public function test_a_product_with_no_batches_at_all_is_zero_stock_not_null(): void
    {
        $product = Product::factory()->create(['name' => 'Never Received']);

        $products = Livewire::actingAs($this->cashier)->test(Terminal::class)->viewData('products');

        $this->assertSame(0.0, $products->firstWhere('id', $product->id)['stock_quantity']);
    }

    public function test_a_stocked_product_reports_its_real_quantity(): void
    {
        $product = Product::factory()->create(['name' => 'In Stock Thing']);
        Batch::factory()->for($product)->remaining(7)->create(['qty_received' => 10]);

        $products = Livewire::actingAs($this->cashier)->test(Terminal::class)->viewData('products');

        $this->assertSame(7.0, $products->firstWhere('id', $product->id)['stock_quantity']);
    }

    public function test_an_inactive_expired_batch_does_not_count_as_stock_on_hand(): void
    {
        $product = Product::factory()->create(['name' => 'Expired Thing']);
        Batch::factory()->for($product)->remaining(5)->create(['qty_received' => 10, 'status' => 'expired']);

        $products = Livewire::actingAs($this->cashier)->test(Terminal::class)->viewData('products');

        $this->assertSame(0.0, $products->firstWhere('id', $product->id)['stock_quantity']);
    }

    public function test_the_page_tells_the_browser_whether_negative_stock_sales_are_allowed(): void
    {
        SalesSetting::current()->update(['allow_negative_stock_sale' => false]);

        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSeeHtml('allowNegativeStockSale: false');

        SalesSetting::current()->update(['allow_negative_stock_sale' => true]);

        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSeeHtml('allowNegativeStockSale: true');
    }

    public function test_the_out_of_stock_guard_is_shipped_to_the_browser(): void
    {
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            // The click handler stays wired up (so a click is never silently
            // inert) — the guard inside addToCart() is what stops the add.
            ->assertSeeHtml('@click="addToCart(product)"')
            ->assertSeeHtml('isOutOfStock(product)')
            ->assertSee('Out of stock.', false)
            ->assertSee("all of it's in the cart.", false);
    }

    public function test_the_notice_floats_over_the_clicked_tile_not_the_checkout_error_bar(): void
    {
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            // A bubble anchored to this one tile (bottom-full = sits above it),
            // bound to oosProductId/oosMessage — separate from the shared
            // errorMessage bar used for checkout-time problems.
            ->assertSeeHtml('x-show="oosProductId === product.id"')
            ->assertSeeHtml('bottom-full')
            ->assertSeeHtml('x-text="oosMessage"')
            ->assertSeeHtml('showOosNotice(product,');
    }

    public function test_a_live_settings_broadcast_refreshes_the_negative_stock_flag_too(): void
    {
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSeeHtml('this.allowNegativeStockSale = detail.allowNegativeStockSale;');
    }
}
