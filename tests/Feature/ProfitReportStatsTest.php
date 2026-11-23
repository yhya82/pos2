<?php

namespace Tests\Feature;

use App\Livewire\Reports\ReportViewer;
use App\Models\Batch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\SaleService;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * Every other report already shows stat tiles above its table — Profit
 * Report didn't, because its config was missing the 'dateColumn' entry
 * statsFor() gates on, even though the table itself was already date-
 * filterable. These pin the tiles now that it has one.
 */
class ProfitReportStatsTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'Finance Viewer '.uniqid(), 'description' => 'test', 'status' => 'active']);
        $role->permissions()->attach(\App\Models\Permission::where(fn ($q) => $q
            ->where(fn ($p) => $p->where('module', 'sales')->where('action', 'view'))
            ->orWhere(fn ($p) => $p->where('module', 'financials')->where('action', 'view')))->pluck('id'));

        $this->user = User::factory()->create(['role_id' => $role->id]);
    }

    private function sell(Product $product, float $qty): void
    {
        app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => $qty]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            referenceNumber: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: $this->user,
        );
    }

    public function test_the_profit_report_shows_revenue_cost_profit_and_units_sold(): void
    {
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(50)->create(['unit_cost' => 6]);

        $this->sell($product, 5);

        Livewire::actingAs($this->user)->test(ReportViewer::class)
            ->call('selectReport', 'realized_profit')
            ->assertSee('Revenue')
            ->assertSee('50.00')
            ->assertSee('Cost')
            ->assertSee('30.00')
            ->assertSee('Profit')
            ->assertSee('20.00')
            ->assertSee('40% margin', false)
            ->assertSee('Units sold')
            ->assertSee('5');
    }

    public function test_a_loss_making_range_tones_the_profit_tile_as_a_warning(): void
    {
        $product = Product::factory()->create(['selling_price' => 5]);
        Batch::factory()->for($product)->remaining(50)->create(['unit_cost' => 8]);

        $this->sell($product, 2);

        $component = Livewire::actingAs($this->user)->test(ReportViewer::class)
            ->call('selectReport', 'realized_profit');

        $component->assertSee('-6.00');
        $this->assertStringContainsString('val warn', $component->html());
    }

    public function test_no_sales_in_range_shows_zero_tiles_without_a_margin_sub_line(): void
    {
        Livewire::actingAs($this->user)->test(ReportViewer::class)
            ->call('selectReport', 'realized_profit')
            ->set('dateFrom', '2020-01-01')
            ->set('dateTo', '2020-01-02')
            ->assertDontSee('% margin', false);
    }
}
