<?php

namespace Tests\Feature;

use App\Livewire\Sales\SalesHistory;
use App\Models\Batch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\ReturnService;
use App\Services\SaleService;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class SalesHistoryTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private function sell(User $cashier, Product $product, int $quantity = 5): \App\Models\Sale
    {
        return app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => $quantity]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            paymentConfirmed: true,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: $cashier,
        );
    }

    public function test_a_partially_refunded_sale_shows_how_many_items_were_returned(): void
    {
        $cashier = User::factory()->create();
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(100)->create();

        $sale = $this->sell($cashier, $product);

        app(ReturnService::class)->processReturn(
            $sale,
            [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 2, 'condition_type' => 'sellable']],
            'partial return',
            $cashier,
        );

        Livewire::actingAs($cashier)->test(SalesHistory::class)
            ->assertSee('2 of 5 items returned');
    }

    public function test_a_sale_with_no_returns_shows_no_returned_subnote(): void
    {
        $cashier = User::factory()->create();
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(100)->create();

        $this->sell($cashier, $product);

        Livewire::actingAs($cashier)->test(SalesHistory::class)
            ->assertDontSee('items returned');
    }

    public function test_the_table_refreshes_live_once_a_refund_is_processed_elsewhere(): void
    {
        // RefundModal is a separate Livewire component — this table only
        // reflects a refund it just processed because it listens for the
        // 'refund-processed' event that component dispatches on success.
        $cashier = User::factory()->create();
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(100)->create();

        $sale = $this->sell($cashier, $product);

        $component = Livewire::actingAs($cashier)->test(SalesHistory::class)
            ->assertDontSee('items returned');

        app(ReturnService::class)->processReturn(
            $sale,
            [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 2, 'condition_type' => 'sellable']],
            'partial return',
            $cashier,
        );

        $component->dispatch('refund-processed')
            ->assertSee('2 of 5 items returned');
    }
}
