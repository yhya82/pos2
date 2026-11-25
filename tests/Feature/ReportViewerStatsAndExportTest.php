<?php

namespace Tests\Feature;

use App\Livewire\Reports\ReportViewer;
use App\Models\Batch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\SaleService;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class ReportViewerStatsAndExportTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private function sell(User $cashier, Product $product, int $qty = 1): void
    {
        app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => $qty]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            paymentConfirmed: true,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: $cashier,
        );
    }

    // ------------------------------------------------------------- stats

    public function test_low_stock_report_shows_stats_despite_having_no_date_range(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create(['min_stock_level' => 10]);
        Batch::factory()->for($product)->remaining(3)->create();

        Livewire::actingAs($admin)->test(ReportViewer::class)
            ->call('selectReport', 'low_stock')
            ->assertViewHas('stats', fn ($stats) => $stats !== null && $stats[0]['label'] === 'Low stock items');
    }

    public function test_out_of_stock_report_shows_stats(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create();
        Batch::factory()->for($product)->remaining(0)->create();

        Livewire::actingAs($admin)->test(ReportViewer::class)
            ->call('selectReport', 'out_of_stock')
            ->assertViewHas('stats', fn ($stats) => $stats !== null && $stats[0]['label'] === 'Out of stock items');
    }

    public function test_inventory_valuation_stats_match_the_dashboards_own_figures(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(5)->create(['unit_cost' => 4]);

        $component = Livewire::actingAs($admin)->test(ReportViewer::class)
            ->call('selectReport', 'inventory_valuation');

        $stats = $component->viewData('stats');

        $this->assertNotNull($stats);
        $this->assertEqualsWithDelta(20.0, collect($stats)->firstWhere('label', 'Value (cost)')['value'], 0.01);
        $this->assertEqualsWithDelta(50.0, collect($stats)->firstWhere('label', 'Value (selling)')['value'], 0.01);
    }

    public function test_product_performance_stats_are_all_time_not_just_the_current_page(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(20)->create();

        $this->sell($admin, $product, 3);

        $component = Livewire::actingAs($admin)->test(ReportViewer::class)
            ->call('selectReport', 'product_performance');

        $stats = $component->viewData('stats');

        $this->assertNotNull($stats);
        $this->assertEqualsWithDelta(3, collect($stats)->firstWhere('label', 'Units sold')['value'], 0.01);
    }

    public function test_a_report_with_no_stats_logic_still_returns_none_cleanly(): void
    {
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(ReportViewer::class)
            ->call('selectReport', 'credit_balances')
            ->assertViewHas('stats', null);
    }

    // ------------------------------------------------------------- export

    /**
     * Both export buttons open a confirm modal (Alpine, client-side) rather
     * than calling exportCsv()/exportAllCsv() directly — the actual export
     * only happens from the modal's own Confirm button.
     */
    public function test_the_export_buttons_open_a_confirmation_modal_not_the_export_itself(): void
    {
        $admin = User::factory()->create();
        Product::factory()->create();

        Livewire::actingAs($admin)->test(ReportViewer::class)
            ->call('selectReport', 'product_performance')
            ->assertSeeHtml("\$dispatch('open-modal', 'confirm-export-csv')")
            ->assertSeeHtml("\$dispatch('open-modal', 'confirm-export-all-csv')")
            ->assertSee('Export this report?')
            ->assertSee('Export every report?')
            ->assertDontSeeHtml('wire:click="exportCsv" class="export-btn"');
    }

    public function test_exporting_with_no_report_selected_is_forbidden(): void
    {
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(ReportViewer::class)
            ->call('exportCsv')
            ->assertForbidden();
    }

    public function test_exporting_a_report_downloads_a_csv_with_the_same_columns_and_rows(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create(['name' => 'Exported Widget', 'selling_price' => 10]);
        Batch::factory()->for($product)->remaining(20)->create();

        $this->sell($admin, $product, 2);

        $component = Livewire::actingAs($admin)->test(ReportViewer::class)
            ->call('selectReport', 'product_performance')
            ->call('exportCsv');

        $component->assertFileDownloaded();

        $content = base64_decode(data_get($component->effects, 'download.content'));

        $this->assertStringContainsString('Product', $content);
        $this->assertStringContainsString('Qty Sold', $content);
        $this->assertStringContainsString('Exported Widget', $content);
        $this->assertStringContainsString('2', $content);
    }

    public function test_export_respects_the_current_date_range_filter(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create(['name' => 'Old Sale Widget']);
        Batch::factory()->for($product)->remaining(20)->create();

        $sale = $this->sell($admin, $product, 1) ?? null;
        \App\Models\Sale::query()->update(['sale_date' => now()->subDays(60)]);

        $component = Livewire::actingAs($admin)->test(ReportViewer::class)
            ->call('selectReport', 'daily_sales')
            ->set('dateFrom', now()->subDays(5)->toDateString())
            ->set('dateTo', now()->toDateString())
            ->call('exportCsv');

        $content = base64_decode(data_get($component->effects, 'download.content'));
        $lines = array_filter(explode("\n", trim($content)));

        // Header only — the sale is outside the filtered range.
        $this->assertCount(1, $lines);
    }

    // --------------------------------------------------------- export all

    public function test_export_all_downloads_a_zip_with_one_csv_per_visible_report(): void
    {
        $admin = User::factory()->create();
        $product = Product::factory()->create(['name' => 'Zipped Widget', 'selling_price' => 10]);
        Batch::factory()->for($product)->remaining(20)->create();

        $this->sell($admin, $product, 1);

        $component = Livewire::actingAs($admin)->test(ReportViewer::class)
            ->call('exportAllCsv');

        $component->assertFileDownloaded();

        $zipPath = tempnam(sys_get_temp_dir(), 'test_reports_').'.zip';
        file_put_contents($zipPath, base64_decode(data_get($component->effects, 'download.content')));

        $zip = new \ZipArchive();
        $zip->open($zipPath);

        // Every report an Administrator can see gets its own entry.
        $this->assertGreaterThan(5, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('product-performance.csv'));
        $this->assertNotFalse($zip->locateName('low-stock.csv'));

        $productPerformance = $zip->getFromName('product-performance.csv');
        $this->assertStringContainsString('Zipped Widget', $productPerformance);

        $zip->close();
        unlink($zipPath);
    }

    public function test_export_all_only_includes_reports_the_viewer_can_actually_see(): void
    {
        // products,view alone makes exactly one report visible
        // (product_performance) — none of the others key off it.
        $role = \App\Models\Role::create(['name' => 'Products Viewer Only', 'description' => 'test', 'status' => 'active']);
        $role->permissions()->attach(\App\Models\Permission::where('module', 'products')->where('action', 'view')->value('id'));
        $user = User::factory()->create(['role_id' => $role->id]);

        $component = Livewire::actingAs($user)->test(ReportViewer::class)
            ->call('exportAllCsv');

        $zipPath = tempnam(sys_get_temp_dir(), 'test_reports_').'.zip';
        file_put_contents($zipPath, base64_decode(data_get($component->effects, 'download.content')));

        $zip = new \ZipArchive();
        $zip->open($zipPath);

        $this->assertSame(1, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('product-performance.csv'));
        $this->assertFalse($zip->locateName('low-stock.csv'));

        $zip->close();
        unlink($zipPath);
    }

    public function test_export_all_is_forbidden_when_nothing_is_visible(): void
    {
        $role = \App\Models\Role::create(['name' => 'No Report Access', 'description' => 'test', 'status' => 'active']);
        $user = User::factory()->create(['role_id' => $role->id]);

        Livewire::actingAs($user)->test(ReportViewer::class)
            ->call('exportAllCsv')
            ->assertForbidden();
    }
}
