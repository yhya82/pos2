<?php

namespace Tests\Feature;

use App\Livewire\Returns\ReturnManager;
use App\Models\Batch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\ReturnService;
use App\Services\SaleService;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * The Returns page's own "process a known sale" flow — a sale with
 * returnable items left must stay reachable here even after it's already
 * had one return processed against it (status flips to 'refunded', not
 * "fully done"), and the per-line reason field that used to sit next to
 * quantity/condition is gone (the one required "Overall Reason" already
 * covers why).
 */
class ReturnManagerTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($this->product)->remaining(100)->create();
    }

    private function sell(User $cashier, int $quantity = 5): \App\Models\Sale
    {
        return app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $this->product->id, 'quantity' => $quantity]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            paymentConfirmed: true,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: $cashier,
        );
    }

    public function test_a_partially_refunded_sale_still_shows_up_in_the_returnable_sales_picker(): void
    {
        $sale = $this->sell($this->admin, 5);
        app(ReturnService::class)->processReturn(
            $sale,
            [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 2, 'condition_type' => 'sellable']],
            'first partial return',
            $this->admin,
        );

        Livewire::actingAs($this->admin)->test(ReturnManager::class)
            ->call('startProcessing')
            ->assertSee($sale->receipt_number);
    }

    public function test_a_partially_refunded_sale_can_be_selected_for_a_second_return(): void
    {
        $sale = $this->sell($this->admin, 5);
        app(ReturnService::class)->processReturn(
            $sale,
            [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 2, 'condition_type' => 'sellable']],
            'first partial return',
            $this->admin,
        );

        Livewire::actingAs($this->admin)->test(ReturnManager::class)
            ->call('startProcessing')
            ->call('selectSale', $sale->fresh()->id)
            ->assertSet('returnLines.0.max_returnable', 3.0)
            ->assertSee('2 already returned')
            ->set('returnLines.0.quantity', '1')
            ->set('overallReason', 'second partial return')
            ->call('submitReturn')
            ->assertHasNoErrors();

        $this->assertSame(2, \App\Models\SalesReturn::where('original_sale_id', $sale->id)->count());
    }

    public function test_a_fully_returned_sale_cannot_be_selected_again(): void
    {
        $sale = $this->sell($this->admin, 1);
        app(ReturnService::class)->processReturn(
            $sale,
            [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 1, 'condition_type' => 'sellable']],
            'fully returned',
            $this->admin,
        );

        Livewire::actingAs($this->admin)->test(ReturnManager::class)
            ->call('startProcessing')
            ->call('selectSale', $sale->fresh()->id)
            ->assertSet('foundSaleId', null)
            ->assertSee('already been fully returned');
    }

    public function test_a_voided_sale_cannot_be_selected(): void
    {
        $sale = $this->sell($this->admin, 1);
        app(SaleService::class)->voidSale($sale, 'test void', $this->admin);

        Livewire::actingAs($this->admin)->test(ReturnManager::class)
            ->call('startProcessing')
            ->call('selectSale', $sale->fresh()->id)
            ->assertSet('foundSaleId', null)
            ->assertSee('only completed or already-refunded sales can have a return processed');
    }

    public function test_the_per_line_reason_field_is_gone(): void
    {
        $sale = $this->sell($this->admin, 2);

        Livewire::actingAs($this->admin)->test(ReturnManager::class)
            ->call('startProcessing')
            ->call('selectSale', $sale->id)
            ->assertDontSee('returnLines.0.reason', false);
    }
}
