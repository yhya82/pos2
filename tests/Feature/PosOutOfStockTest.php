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

    public function test_the_out_of_stock_guard_cannot_be_bent_by_the_negative_stock_sale_setting(): void
    {
        // The sale service rejects any line that outruns real stock on
        // hand unconditionally now (no allow_negative_stock_sale escape
        // hatch left in it) — the till would only be setting a cart up to
        // fail at checkout if it still let that setting through a tile
        // that's actually out of stock, so the guard itself must no
        // longer read or forward the setting at all.
        SalesSetting::current()->update(['allow_negative_stock_sale' => true]);

        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertDontSeeHtml('allowNegativeStockSale')
            ->assertSeeHtml('isOutOfStock(product) {')
            ->assertSee('return product.stock_quantity < 1;', false);
    }

    public function test_the_out_of_stock_guard_is_shipped_to_the_browser(): void
    {
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            // A truly out-of-stock tile is a genuinely disabled button —
            // nothing to click, nothing to say.
            ->assertSeeHtml('@click="addToCart(product)"')
            ->assertSeeHtml(':disabled="isOutOfStock(product)"')
            ->assertSeeHtml('addToCart(product) {')
            ->assertSee('if (this.isOutOfStock(product)) return;', false);
    }

    public function test_the_partial_stock_message_is_a_bottom_toast_not_a_per_tile_bubble(): void
    {
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            // One shared toast (bottom-centre), not a bubble anchored to the
            // tile — fired only when some stock exists but it's all already
            // in the cart; true zero stock never reaches this at all.
            ->assertSeeHtml('class="toast"')
            ->assertSeeHtml('x-text="toastMessage"')
            ->assertSee("in stock.", false)
            ->assertDontSeeHtml('oosProductId')
            ->assertDontSeeHtml('bottom-full');
    }

}
