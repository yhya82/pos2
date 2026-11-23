<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSessionNotExpired;
use App\Models\Batch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\ReturnService;
use App\Services\SaleService;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * The original sale's own receipt (sales/receipt.blade.php) is a frozen
 * snapshot of what the customer originally saw — but a refund can happen
 * long after that snapshot was taken, so a reprint still needs to show it
 * happened. Same idea as the existing VOIDED banner, which already reads
 * $sale->status live rather than from the snapshot.
 */
class SalesReceiptTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->create();
        $this->product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($this->product)->remaining(100)->create();
    }

    private function sell(int $quantity = 5): \App\Models\Sale
    {
        return app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $this->product->id, 'quantity' => $quantity]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            referenceNumber: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: $this->cashier,
        );
    }

    public function test_an_untouched_sales_receipt_shows_no_refund_banner_or_lines(): void
    {
        $sale = $this->sell(5);

        $this->withoutMiddleware(EnsureSessionNotExpired::class)->actingAs($this->cashier)->get(route('sales.receipt', $sale))
            ->assertOk()
            ->assertDontSee('REFUNDED')
            ->assertDontSee('Returned:', false)
            ->assertDontSee('Net Total');
    }

    public function test_a_partially_refunded_sale_shows_the_banner_and_per_line_breakdown(): void
    {
        $sale = $this->sell(5);

        app(ReturnService::class)->processReturn(
            $sale,
            [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 2, 'condition_type' => 'sellable']],
            'customer changed their mind',
            $this->cashier,
        );

        $this->withoutMiddleware(EnsureSessionNotExpired::class)->actingAs($this->cashier)->get(route('sales.receipt', $sale))
            ->assertOk()
            ->assertSee('PARTIALLY REFUNDED', false)
            ->assertDontSee('FULLY REFUNDED', false)
            ->assertSee('Returned: 2', false)
            ->assertSee('Refunded', false)
            ->assertSee('Net Total', false)
            // 5 × 10 = 50 total, 2 × 10 = 20 refunded, net 30.
            ->assertSee(number_format(30, 2), false);
    }

    public function test_a_fully_refunded_sale_shows_the_fully_refunded_banner(): void
    {
        $sale = $this->sell(3);

        app(ReturnService::class)->processReturn(
            $sale,
            [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 3, 'condition_type' => 'sellable']],
            'fully returned',
            $this->cashier,
        );

        $this->withoutMiddleware(EnsureSessionNotExpired::class)->actingAs($this->cashier)->get(route('sales.receipt', $sale))
            ->assertOk()
            ->assertSee('FULLY REFUNDED', false)
            ->assertSee(number_format(0, 2), false);
    }

    public function test_two_partial_returns_add_up_in_the_refunded_total(): void
    {
        $sale = $this->sell(5);
        $lineItemId = $sale->lineItems()->first()->id;

        app(ReturnService::class)->processReturn(
            $sale,
            [['sale_line_item_id' => $lineItemId, 'quantity' => 2, 'condition_type' => 'sellable']],
            'first partial return',
            $this->cashier,
        );
        app(ReturnService::class)->processReturn(
            $sale->fresh(),
            [['sale_line_item_id' => $lineItemId, 'quantity' => 1, 'condition_type' => 'sellable']],
            'second partial return',
            $this->cashier,
        );

        // 5 sold, 3 returned total, so still only partially refunded.
        $this->withoutMiddleware(EnsureSessionNotExpired::class)->actingAs($this->cashier)->get(route('sales.receipt', $sale))
            ->assertOk()
            ->assertSee('PARTIALLY REFUNDED', false)
            ->assertSee('Returned: 3', false)
            // 50 total, 30 refunded (3 × 10), net 20.
            ->assertSee(number_format(20, 2), false);
    }
}
