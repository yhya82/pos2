<?php

namespace Tests\Feature;

use App\Livewire\Products\ProductProfile;
use App\Models\Batch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\ReturnService;
use App\Services\SaleService;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * The Correct Cost form shows the "before", previews what the new cost would
 * change (including profit already reported on past sales), and leaves a
 * trail — these pin the numbers to the same data the profit report reads.
 */
class CostCorrectionPreviewTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $admin;

    private Product $product;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        // Price 10; the batch cost of 20 is the mistake being corrected.
        $this->product = Product::factory()->create(['selling_price' => 10, 'cost_price' => 6]);
        $this->batch = Batch::factory()->for($this->product)->remaining(100)->create(['unit_cost' => 20]);
    }

    private function sell(float $qty): Sale
    {
        return app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $this->product->id, 'quantity' => $qty]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            referenceNumber: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: $this->admin,
        );
    }

    private function openForm(string $newCost)
    {
        return Livewire::actingAs($this->admin)->test(ProductProfile::class, ['product' => $this->product])
            ->call('openCostCorrection', $this->batch->id)
            ->set('costCorrectionValue', $newCost);
    }

    public function test_the_current_cost_stays_visible_as_fixed_text(): void
    {
        Livewire::actingAs($this->admin)->test(ProductProfile::class, ['product' => $this->product])
            ->call('openCostCorrection', $this->batch->id)
            ->assertSee('Current cost')
            ->assertSee('20.00')
            ->assertSee('Selling price')->assertSee('10.00')
            ->assertSee('Enter a different cost or selling price to see what changes');
    }

    public function test_the_preview_shows_before_after_and_change_for_every_figure(): void
    {
        $this->sell(4);   // 96 left; 4 sold from this batch

        $this->openForm('2.5')
            ->assertSee('What changes')
            ->assertSee('−1,680.00')   // stock value 1,920.00 -> 240.00
            ->assertSee('1,920.00')
            ->assertSee('240.00')
            ->assertSee('-10.00')      // profit per unit before: 10 - 20
            ->assertSee('7.50')        // after: 10 - 2.50
            ->assertSee('+17.50')
            ->assertSee('-100.0%')     // margin before
            ->assertSee('75.0%');      // margin after
    }

    public function test_it_states_the_effect_on_profit_already_reported_on_past_sales(): void
    {
        $this->sell(4);

        $this->openForm('2.5')
            ->assertSee('already sold from this batch')
            ->assertSee('+70.00');   // (20 - 2.50) x 4 units
    }

    public function test_sellable_returns_net_out_of_the_units_already_sold(): void
    {
        $sale = $this->sell(4);

        app(ReturnService::class)->processReturn(
            $sale,
            [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 1, 'condition_type' => 'sellable']],
            'changed mind',
            $this->admin,
        );

        // 4 sold - 1 back on the shelf = 3 units whose profit moves: 17.50 x 3.
        $this->openForm('2.5')->assertSee('+52.50');
    }

    public function test_voided_sales_are_not_counted(): void
    {
        $sale = $this->sell(4);
        app(SaleService::class)->voidSale($sale, 'mistake', $this->admin);

        $this->openForm('2.5')
            ->assertSee('Nothing has been sold from this batch yet')
            ->assertDontSee('already sold from this batch');
    }

    public function test_it_warns_when_the_new_cost_is_still_above_the_selling_price(): void
    {
        $this->openForm('12')->assertSee('still not below the selling price');
        $this->openForm('9')->assertDontSee('still not below the selling price');
    }

    public function test_a_half_typed_or_invalid_cost_shows_no_preview_and_does_not_break(): void
    {
        $this->openForm('abc')->assertDontSee('What changes')->assertDontSee('still not below');
        $this->openForm('')->assertDontSee('What changes');
    }

    public function test_a_batch_that_is_not_in_stock_says_it_is_not_counted_in_stock_value(): void
    {
        $this->batch->update(['status' => 'expired']);

        $this->openForm('2.5')
            ->assertSee("isn't counted in stock value", false)
            ->assertDontSee('Stock value at cost');
    }

    public function test_a_correction_leaves_a_marker_on_the_batch_and_a_history_in_the_form(): void
    {
        $component = $this->openForm('2.5')
            ->set('costCorrectionReason', 'entered per pack, not per piece')
            ->call('submitCostCorrection')
            ->assertHasNoErrors();

        // Marker on the Batches tab, with the detail in its hover text.
        $component->call('setTab', 'inventory')
            ->assertSee('corrected')
            ->assertSee('20.00 → 2.50', false)
            ->assertSee('entered per pack, not per piece');

        // Reopening the form lists what was done before.
        $component->call('openCostCorrection', $this->batch->id)
            ->assertSee('Earlier corrections to this batch')
            ->assertSee('“entered per pack, not per piece”');
    }

    public function test_an_uncorrected_batch_has_no_marker_or_history(): void
    {
        Livewire::actingAs($this->admin)->test(ProductProfile::class, ['product' => $this->product])
            ->call('setTab', 'inventory')
            ->assertDontSee('corrected')
            ->call('openCostCorrection', $this->batch->id)
            ->assertDontSee('Earlier corrections to this batch');
    }

    public function test_the_form_offers_the_selling_price_prefilled(): void
    {
        Livewire::actingAs($this->admin)->test(ProductProfile::class, ['product' => $this->product])
            ->call('openCostCorrection', $this->batch->id)
            ->assertSet('costCorrectionPrice', '10.00')
            ->assertSee('Selling price (per');
    }

    public function test_changing_the_price_previews_a_price_row_and_says_past_profit_is_unaffected(): void
    {
        $this->openForm('20')
            ->set('costCorrectionPrice', '25')
            ->assertSee('Selling price per')
            ->assertSee('+15.00')   // 25 - 10
            ->assertSee('Only the selling price changes');
    }

    public function test_price_and_cost_can_be_fixed_together_and_both_are_audited(): void
    {
        $this->openForm('4')
            ->set('costCorrectionPrice', '8')
            ->set('costCorrectionReason', 'both were mistyped')
            ->call('submitCostCorrection')
            ->assertHasNoErrors();

        $this->assertEquals(4, $this->batch->fresh()->unit_cost);
        $this->assertEquals(8, $this->product->fresh()->selling_price);
        $this->assertDatabaseHas('audit_logs', ['record_type' => 'Product', 'record_id' => $this->product->id, 'action' => 'update']);
        $this->assertDatabaseHas('audit_logs', ['record_type' => 'Batch', 'record_id' => $this->batch->id, 'action' => 'update']);
    }

    public function test_a_price_only_change_leaves_the_batch_cost_alone(): void
    {
        $this->openForm('20')
            ->set('costCorrectionPrice', '25')
            ->set('costCorrectionReason', 'price was wrong')
            ->call('submitCostCorrection')
            ->assertHasNoErrors();

        $this->assertEquals(20, $this->batch->fresh()->unit_cost);
        $this->assertEquals(25, $this->product->fresh()->selling_price);
    }

    public function test_a_price_below_the_products_cost_price_is_refused_and_nothing_is_saved(): void
    {
        // The product's cost_price is 6, so a price of 5 would break cost <= price.
        $this->openForm('4')
            ->set('costCorrectionPrice', '5')
            ->set('costCorrectionReason', 'x')
            ->call('submitCostCorrection')
            ->assertHasErrors('costCorrectionReason');

        $this->assertEquals(20, $this->batch->fresh()->unit_cost);   // rolled back together
        $this->assertEquals(10, $this->product->fresh()->selling_price);
    }

    public function test_a_price_of_zero_is_refused(): void
    {
        $this->openForm('4')
            ->set('costCorrectionPrice', '0')
            ->set('costCorrectionReason', 'x')
            ->call('submitCostCorrection')
            ->assertHasErrors('costCorrectionPrice');
    }

    public function test_the_promo_cant_price_below_cost_after_a_price_change(): void
    {
        $this->product->update(['promo_discount_type' => 'percentage', 'promo_discount_value' => 50]);

        // Price 7 -> promo 3.50, below the product's cost of 6.
        $this->openForm('4')
            ->set('costCorrectionPrice', '7')
            ->set('costCorrectionReason', 'x')
            ->call('submitCostCorrection')
            ->assertHasErrors('costCorrectionReason');

        $this->assertEquals(10, $this->product->fresh()->selling_price);
    }

    public function test_correcting_the_newest_batch_updates_the_products_cost_price(): void
    {
        $this->openForm('4')
            ->set('costCorrectionReason', 'typo')
            ->call('submitCostCorrection')
            ->assertHasNoErrors();

        $this->assertEquals(4, $this->product->fresh()->cost_price);   // was 6
        $this->assertDatabaseHas('audit_logs', ['record_type' => 'Product', 'record_id' => $this->product->id, 'action' => 'update']);
    }

    public function test_correcting_an_older_batch_leaves_the_products_cost_price_alone(): void
    {
        Batch::factory()->for($this->product)->remaining(10)->create(['unit_cost' => 7, 'received_date' => now()->addDay()->toDateString()]);

        $this->openForm('4')
            ->set('costCorrectionReason', 'typo')
            ->call('submitCostCorrection')
            ->assertHasNoErrors();

        $this->assertEquals(6, $this->product->fresh()->cost_price);
    }

    public function test_a_corrected_cost_not_below_the_price_is_not_pushed_onto_the_product(): void
    {
        $this->openForm('12')
            ->set('costCorrectionReason', 'still high')
            ->call('submitCostCorrection')
            ->assertHasNoErrors();

        $this->assertEquals(6, $this->product->fresh()->cost_price);
    }

    public function test_changing_nothing_is_refused(): void
    {
        $this->openForm('20')
            ->set('costCorrectionReason', 'x')
            ->call('submitCostCorrection')
            ->assertHasErrors('costCorrectionReason');
    }
}
