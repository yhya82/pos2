<?php

namespace Tests\Feature;

use App\Events\ProductPriceChanged;
use App\Livewire\Inventory\InventoryOverview;
use App\Livewire\Pos\Terminal;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class ProductPriceBroadcastTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    public function test_changing_the_selling_price_broadcasts(): void
    {
        $product = Product::factory()->create(['selling_price' => 10]);

        Event::fake([ProductPriceChanged::class]);

        $product->update(['selling_price' => 12]);

        Event::assertDispatched(ProductPriceChanged::class, fn ($e) => $e->productIds === [$product->id]);
    }

    public function test_changing_a_promo_broadcasts(): void
    {
        $product = Product::factory()->create();

        Event::fake([ProductPriceChanged::class]);

        $product->update(['promo_discount_type' => 'percentage', 'promo_discount_value' => 10]);

        Event::assertDispatchedTimes(ProductPriceChanged::class, 1);
    }

    public function test_edits_that_do_not_touch_price_do_not_broadcast(): void
    {
        $product = Product::factory()->create();

        Event::fake([ProductPriceChanged::class]);

        $product->update(['name' => 'Renamed', 'description' => 'New text', 'min_stock_level' => 99]);

        Event::assertNotDispatched(ProductPriceChanged::class);
    }

    public function test_bulk_discount_broadcasts_once_for_the_whole_batch(): void
    {
        $admin = User::factory()->create();
        $products = Product::factory()->count(3)->create(['selling_price' => 10]);

        Event::fake([ProductPriceChanged::class]);

        Livewire::actingAs($admin)->test(InventoryOverview::class)
            ->set('selectedProductIds', $products->pluck('id')->all())
            ->set('bulkDiscountType', 'percentage')
            ->set('bulkDiscountValue', '10')
            ->call('applyBulkDiscount')
            ->assertHasNoErrors();

        Event::assertDispatchedTimes(ProductPriceChanged::class, 1);
        Event::assertDispatched(ProductPriceChanged::class, fn ($e) => count($e->productIds) === 3);
        $this->assertSame('percentage', $products->first()->fresh()->promo_discount_type);

        Livewire::actingAs($admin)->test(InventoryOverview::class)
            ->set('selectedProductIds', $products->pluck('id')->all())
            ->call('clearBulkDiscount');

        Event::assertDispatchedTimes(ProductPriceChanged::class, 2);
        $this->assertSame('none', $products->first()->fresh()->promo_discount_type);
    }

    public function test_the_midnight_command_broadcasts(): void
    {
        Event::fake([ProductPriceChanged::class]);

        $this->artisan('pos:refresh-prices')->assertSuccessful();

        Event::assertDispatchedTimes(ProductPriceChanged::class, 1);
    }

    public function test_a_broadcast_failure_never_blocks_saving_a_price(): void
    {
        $product = Product::factory()->create(['selling_price' => 10]);

        Event::listen(ProductPriceChanged::class, function () {
            throw new RuntimeException('websocket server is down');
        });

        $product->update(['selling_price' => 15]);

        $this->assertEquals(15, $product->fresh()->selling_price);
    }

    public function test_the_pos_repulls_products_when_a_price_changes(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Terminal::class)
            ->dispatch('echo-private:stock,.ProductPriceChanged')
            ->assertDispatched('pos-live-update');
    }
}
