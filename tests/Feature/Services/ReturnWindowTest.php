<?php

namespace Tests\Feature\Services;

use App\Models\Batch;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\ReturnService;
use App\Services\SaleService;
use RuntimeException;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * sales_settings.return_window_days (0 = no limit, same convention as
 * security_settings.password_expiry_days): past that many days since
 * sale_date, a regular return is refused — but someone holding
 * returns,update (the same permission that already widens which sales
 * someone can refund) can still process one.
 */
class ReturnWindowTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private ReturnService $service;

    private SaleService $saleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ReturnService::class);
        $this->saleService = app(SaleService::class);
    }

    private function oldSale(int $daysAgo): array
    {
        // Not a plain factory()->create() — that defaults to the
        // Administrator role, which already has returns,update and would
        // bypass the window regardless, defeating the point of this test.
        $cashier = User::factory()->cashier()->create();
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(20)->create();

        $sale = $this->saleService->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => 2]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            referenceNumber: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: $cashier,
        );

        $sale->update(['sale_date' => now()->subDays($daysAgo)]);

        return [$sale->fresh(), $cashier];
    }

    private function returnLinesFor(Sale $sale): array
    {
        return [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 1, 'condition_type' => 'sellable']];
    }

    public function test_default_window_of_zero_means_no_limit(): void
    {
        [$sale, $cashier] = $this->oldSale(400);

        $this->assertSame(0, SalesSetting::current()->return_window_days);
        $this->assertFalse($this->service->isPastReturnWindow($sale));

        $this->assertNotNull($this->service->processReturn($sale, $this->returnLinesFor($sale), 'test', $cashier)->id);
    }

    public function test_a_sale_past_the_configured_window_is_refused_for_a_regular_cashier(): void
    {
        SalesSetting::current()->update(['return_window_days' => 30]);

        [$sale, $cashier] = $this->oldSale(45);

        $this->assertTrue($this->service->isPastReturnWindow($sale));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This sale is outside the 30-day return window.');

        $this->service->processReturn($sale, $this->returnLinesFor($sale), 'test', $cashier);
    }

    public function test_a_sale_within_the_window_is_still_fine(): void
    {
        SalesSetting::current()->update(['return_window_days' => 30]);

        [$sale, $cashier] = $this->oldSale(10);

        $this->assertNotNull($this->service->processReturn($sale, $this->returnLinesFor($sale), 'test', $cashier)->id);
    }

    public function test_someone_with_returns_update_can_still_process_a_return_past_the_window(): void
    {
        SalesSetting::current()->update(['return_window_days' => 30]);

        [$sale] = $this->oldSale(45);

        $role = Role::create(['name' => 'Returns Manager', 'description' => 'test', 'status' => 'active']);
        $role->permissions()->attach(Permission::where('module', 'returns')->whereIn('action', ['create', 'update'])->pluck('id'));
        $manager = User::factory()->create(['role_id' => $role->id]);

        $this->assertNotNull($this->service->processReturn($sale, $this->returnLinesFor($sale), 'test', $manager)->id);
    }

    public function test_the_returns_screen_refuses_to_even_load_a_sale_past_the_window(): void
    {
        SalesSetting::current()->update(['return_window_days' => 30]);

        [$sale, $cashier] = $this->oldSale(45);

        \Livewire\Livewire::actingAs($cashier)->test(\App\Livewire\Returns\ReturnManager::class)
            ->call('startProcessing')
            ->set('saleSearch', $sale->receipt_number)
            ->call('findSale')
            ->assertSet('foundSaleId', null)
            ->assertSee('outside the return window');
    }

    public function test_the_refund_modal_refuses_to_open_for_a_sale_past_the_window(): void
    {
        SalesSetting::current()->update(['return_window_days' => 30]);

        [$sale, $cashier] = $this->oldSale(45);

        \Livewire\Livewire::actingAs($cashier)->test(\App\Livewire\Sales\RefundModal::class)
            ->call('open', $sale->id)
            ->assertSet('saleId', null)
            ->assertSee('outside the return window');
    }

    public function test_the_settings_form_rejects_a_negative_window(): void
    {
        $admin = User::factory()->create();

        \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Settings\SettingsManager::class)
            ->set('activeSection', 'sales')
            ->set('sales.return_window_days', -1)
            ->call('saveSales')
            ->assertHasErrors('sales.return_window_days');
    }

    public function test_the_settings_form_saves_a_valid_window(): void
    {
        $admin = User::factory()->create();

        \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Settings\SettingsManager::class)
            ->set('activeSection', 'sales')
            ->set('sales.return_window_days', 14)
            ->call('saveSales')
            ->assertHasNoErrors();

        $this->assertSame(14, SalesSetting::current()->return_window_days);
    }
}
