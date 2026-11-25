<?php

namespace Tests\Feature;

use App\Livewire\Pos\Terminal;
use App\Models\Batch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * The till sells whole units at the product's own price: no cart discounts,
 * no fractions of an item.
 */
class PosCartTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->cashier()->create();
        $this->product = Product::factory()->create(['name' => 'Whole Widget', 'selling_price' => 10, 'cost_price' => 6]);
        Batch::factory()->for($this->product)->remaining(100)->create(['unit_cost' => 5]);
    }

    private function checkout(array $cart): mixed
    {
        $result = null;

        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->call('checkout', $cart, null, PaymentMethod::where('code', 'cash')->firstOrFail()->id, true)
            ->assertReturned(function ($returned) use (&$result) {
                $result = $returned;

                return true;
            });

        return $result;
    }

    private function sell(float|string $quantity): Sale
    {
        return app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $this->product->id, 'quantity' => $quantity]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            paymentConfirmed: true,
            discountType: 'none', discountValue: 0, discountReason: null,
            cashier: $this->cashier,
        );
    }

    // ------------------------------------------------------- no cart discount

    public function test_the_till_offers_no_discount_controls(): void
    {
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertDontSee('Discount reason')
            ->assertDontSeeHtml('x-model="discountType"')
            ->assertDontSeeHtml('discountValue')
            ->assertDontSeeHtml('discountAmount');
    }

    public function test_a_checkout_through_the_till_never_carries_a_discount(): void
    {
        $result = $this->checkout([['product_id' => $this->product->id, 'quantity' => 3]]);

        $this->assertTrue($result['success']);

        $sale = Sale::findOrFail($result['saleId']);
        $this->assertSame('none', $sale->discount_type);
        $this->assertEquals(0, $sale->discount_amount);
        $this->assertEquals(30.00, $sale->subtotal);
        $this->assertNull($sale->discount_applied_by);
    }

    public function test_a_product_promotion_is_still_the_price_charged(): void
    {
        // Promotions belong to the product, not the cart — they stay.
        $this->product->update(['promo_discount_type' => 'percentage', 'promo_discount_value' => 20]);

        $result = $this->checkout([['product_id' => $this->product->id, 'quantity' => 2]]);

        $this->assertEquals(16.00, Sale::findOrFail($result['saleId'])->subtotal);   // 2 x 8.00
    }

    // ---------------------------------------------------------- whole numbers

    public function test_cart_quantity_is_a_stepper_not_a_free_typed_number(): void
    {
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            // A −/N/+ stepper that only ever moves by one whole unit — no
            // free-text quantity box a cashier could type a fraction into.
            ->assertSeeHtml('stepQuantity(index, -1)')
            ->assertSeeHtml('stepQuantity(index, 1)')
            ->assertDontSeeHtml('step="0.001"')
            ->assertDontSeeHtml('setQuantity(index');
    }

    public function test_whole_quantities_sell_including_ones_sent_as_text(): void
    {
        $this->assertEquals(20.00, $this->sell(2)->subtotal);
        $this->assertEquals(30.00, $this->sell('3')->subtotal);
        $this->assertEquals(10.00, $this->sell(1.0)->subtotal);
    }

    /** @dataProvider badQuantities */
    public function test_anything_that_is_not_a_whole_number_is_refused_and_nothing_is_sold(mixed $quantity): void
    {
        try {
            $this->sell($quantity);
            $this->fail('expected refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Quantities must be whole numbers', $e->getMessage());
            $this->assertStringContainsString('Whole Widget', $e->getMessage());
        }

        $this->assertSame(0, Sale::count());
        $this->assertEquals(100, Batch::where('product_id', $this->product->id)->value('qty_remaining'), 'no stock was taken');
    }

    public static function badQuantities(): array
    {
        return [
            'half' => [0.5],
            'two and a half' => [2.5],
            'a thousandth' => [0.001],
            'text fraction' => ['1.5'],
            'zero' => [0],
            'negative' => [-2],
            'not a number' => ['abc'],
            'empty' => [''],
        ];
    }

    public function test_a_fractional_quantity_through_the_till_returns_a_plain_message(): void
    {
        $result = $this->checkout([['product_id' => $this->product->id, 'quantity' => 2.5]]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Quantities must be whole numbers (1, 2, 3…)', $result['message']);
    }

    public function test_one_bad_line_stops_the_whole_cart(): void
    {
        $other = Product::factory()->create(['selling_price' => 5, 'cost_price' => 2]);
        Batch::factory()->for($other)->remaining(10)->create(['unit_cost' => 1]);

        $result = $this->checkout([
            ['product_id' => $this->product->id, 'quantity' => 2],
            ['product_id' => $other->id, 'quantity' => 1.5],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(0, Sale::count());
    }

    // -------------------------------------- bogus ids (crafted, not from the JS)

    /**
     * The JS only ever submits ids from data this component already gave
     * it, but a direct/crafted call with a nonexistent id must still come
     * back as the same {success:false, message} shape the JS expects —
     * not an uncaught 404/500 from a bare findOrFail() deep in SaleService.
     */
    public function test_a_nonexistent_product_id_returns_a_friendly_message_not_a_crash(): void
    {
        $result = $this->checkout([['product_id' => 999999, 'quantity' => 1]]);

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertSame(0, Sale::count());
    }

    public function test_a_nonexistent_payment_method_id_returns_a_friendly_message_not_a_crash(): void
    {
        $result = null;

        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->call('checkout', [['product_id' => $this->product->id, 'quantity' => 1]], null, 999999, true)
            ->assertReturned(function ($returned) use (&$result) {
                $result = $returned;

                return true;
            });

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertSame(0, Sale::count());
    }

    public function test_a_nonexistent_customer_id_returns_a_friendly_message_not_a_crash(): void
    {
        $result = null;

        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->call('checkout', [['product_id' => $this->product->id, 'quantity' => 1]], 999999, PaymentMethod::where('code', 'cash')->firstOrFail()->id, true)
            ->assertReturned(function ($returned) use (&$result) {
                $result = $returned;

                return true;
            });

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertSame(0, Sale::count());
    }
}
