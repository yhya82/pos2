<?php

namespace Tests\Feature;

use App\Livewire\Pos\Terminal;
use App\Models\Batch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * Aps (mobile_money) and Wave (bank_transfer) have no reference number to
 * fill in anymore — the cashier has to explicitly tick that the payment
 * actually came through before the sale can complete. Cash and credit have
 * nothing to confirm and are never blocked by this.
 */
class PaymentConfirmationTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->cashier()->create();
        $this->product = Product::factory()->create(['selling_price' => 10, 'cost_price' => 6]);
        Batch::factory()->for($this->product)->remaining(100)->create(['unit_cost' => 5]);
    }

    private function checkout(string $paymentMethodCode, bool $paymentConfirmed): mixed
    {
        $result = null;

        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->call(
                'checkout',
                [['product_id' => $this->product->id, 'quantity' => 1]],
                null,
                PaymentMethod::where('code', $paymentMethodCode)->firstOrFail()->id,
                $paymentConfirmed,
            )
            ->assertReturned(function ($returned) use (&$result) {
                $result = $returned;

                return true;
            });

        return $result;
    }

    public function test_an_aps_mobile_money_sale_is_blocked_until_payment_received_is_confirmed(): void
    {
        $result = $this->checkout('mobile_money', false);

        $this->assertFalse($result['success']);
        $this->assertSame('Please confirm the payment was received before completing the sale.', $result['message']);
        $this->assertSame(0, Sale::count());
    }

    public function test_an_aps_mobile_money_sale_succeeds_once_confirmed_and_records_it(): void
    {
        $result = $this->checkout('mobile_money', true);

        $this->assertTrue($result['success']);
        $this->assertTrue(Sale::findOrFail($result['saleId'])->payment->payment_confirmed);
    }

    public function test_a_wave_bank_transfer_sale_is_blocked_until_payment_received_is_confirmed(): void
    {
        $result = $this->checkout('bank_transfer', false);

        $this->assertFalse($result['success']);
        $this->assertSame(0, Sale::count());
    }

    public function test_a_wave_bank_transfer_sale_succeeds_once_confirmed(): void
    {
        $result = $this->checkout('bank_transfer', true);

        $this->assertTrue($result['success']);
    }

    public function test_a_cash_sale_never_needs_payment_confirmation(): void
    {
        $result = $this->checkout('cash', false);

        $this->assertTrue($result['success']);
    }
}
