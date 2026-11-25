<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSessionNotExpired;
use App\Livewire\Sales\SalesHistory;
use App\Models\Batch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * The Sales index redesigned into the POS/Dashboard/Sidebar visual
 * language — same real data, filters and void flow the Tailwind-table
 * version already had, just restyled: a status select becomes a
 * segmented control, text-link row actions become small flat buttons,
 * and the default pagination partial becomes a custom pager built off
 * the same paginator the old one used.
 */
class SalesIndexRedesignTest extends TestCase
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

    private function sell(User $cashier): Sale
    {
        return app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $this->product->id, 'quantity' => 2]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            paymentConfirmed: true,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: $cashier,
        );
    }

    public function test_the_real_shared_header_turns_red_same_as_the_dashboard(): void
    {
        $css = file_get_contents(resource_path('css/sales.css'));

        $this->assertMatchesRegularExpression('/#app-header \{[^}]*background: var\(--acc\)/', $css);
    }

    public function test_the_total_shows_the_real_configured_currency_code(): void
    {
        \App\Models\GeneralSetting::current()->update(['currency_code' => 'GMD']);
        $sale = $this->sell($this->admin);

        Livewire::actingAs($this->admin)->test(SalesHistory::class)
            // Smaller, in its own span, same as the Dashboard's money
            // figures — not plain "GMD 20.00" text.
            ->assertSeeHtml('<span class="ccy">GMD</span> '.number_format($sale->total_amount, 2));
    }

    public function test_the_time_shows_am_or_pm_styled_small(): void
    {
        $sale = $this->sell($this->admin);

        Livewire::actingAs($this->admin)->test(SalesHistory::class)
            ->assertSeeHtml('<span class="ampm">'.$sale->sale_date->format('A').'</span>')
            ->assertSee($sale->sale_date->format('Y-m-d h:i'));
    }

    public function test_the_skin_is_scoped_and_the_stylesheet_is_loaded(): void
    {
        $response = $this->withoutMiddleware(EnsureSessionNotExpired::class)->actingAs($this->admin)->get(route('sales.index'));

        $response->assertOk();
        $response->assertSeeHtml('class="sales-skin"', false);
        $response->assertSee('sales.css', false);
    }

    public function test_the_palette_is_scoped_to_this_page_not_loaded_globally(): void
    {
        // Page-scoped like pos-terminal.css/dashboard.css (pushed only on
        // this page) rather than global like sidebar.css (which has to
        // load everywhere since the sidebar is on every page) — this
        // page's own content isn't.
        $css = file_get_contents(resource_path('css/sales.css'));
        $layout = file_get_contents(resource_path('views/components/layouts/app.blade.php'));

        $this->assertStringContainsString('body {', $css);
        $this->assertStringNotContainsString('sales.css', $layout);
    }

    public function test_status_filter_is_a_segmented_control_not_a_select_and_still_filters(): void
    {
        $open = $this->sell($this->admin);
        $void = $this->sell($this->admin);
        app(SaleService::class)->voidSale(Sale::find($void->id), 'test void', $this->admin);

        Livewire::actingAs($this->admin)->test(SalesHistory::class)
            ->assertDontSeeHtml('<select')
            ->assertSeeHtml("wire:click=\"setStatusFilter('completed')\"")
            ->assertSeeHtml("wire:click=\"setStatusFilter('voided')\"")
            ->call('setStatusFilter', 'voided')
            ->assertSee($void->receipt_number)
            ->assertDontSee($open->receipt_number);
    }

    public function test_the_active_status_filter_is_marked_pressed(): void
    {
        Livewire::actingAs($this->admin)->test(SalesHistory::class)
            ->call('setStatusFilter', 'completed')
            ->assertSeeHtml("wire:click=\"setStatusFilter('completed')\" aria-pressed=\"true\"");
    }

    public function test_search_and_date_range_still_filter_the_real_data(): void
    {
        $sale = $this->sell($this->admin);

        Livewire::actingAs($this->admin)->test(SalesHistory::class)
            ->set('search', $sale->receipt_number)
            ->assertSee($sale->receipt_number)
            ->set('search', 'no-such-receipt')
            ->assertDontSee($sale->receipt_number);

        Livewire::actingAs($this->admin)->test(SalesHistory::class)
            ->set('dateFrom', now()->addDay()->toDateString())
            ->assertDontSee($sale->receipt_number);
    }

    public function test_row_actions_are_flat_buttons_not_text_links(): void
    {
        $sale = $this->sell($this->admin);

        Livewire::actingAs($this->admin)->test(SalesHistory::class)
            ->assertSeeHtml('class="actionbtn"')
            ->assertSeeHtml('class="actionbtn void"')
            ->assertDontSeeHtml('class="rowlink');
    }

    public function test_void_button_still_opens_the_real_confirmation_modal(): void
    {
        $sale = $this->sell($this->admin);

        Livewire::actingAs($this->admin)->test(SalesHistory::class)
            ->assertSeeHtml("wire:click=\"confirmVoid({$sale->id})\"")
            ->call('confirmVoid', $sale->id)
            ->assertSet('saleIdPendingVoid', $sale->id)
            ->assertDispatched('open-modal', 'confirm-void-sale');
    }

    public function test_status_chips_map_to_the_right_color_token(): void
    {
        $completed = $this->sell($this->admin);
        $voided = $this->sell($this->admin);
        app(SaleService::class)->voidSale(Sale::find($voided->id), 'test void', $this->admin);

        $html = Livewire::actingAs($this->admin)->test(SalesHistory::class)->html();

        $this->assertMatchesRegularExpression('/<span class="status ok">Completed<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span class="status acc">Voided<\/span>/', $html);
    }

    public function test_the_pager_uses_the_real_paginator_not_the_default_partial(): void
    {
        // Built off the same LengthAwarePaginator the old {{ $sales->links() }}
        // used — gotoPage() is Livewire's own WithPagination method, not
        // new code — just a custom look instead of the default partial.
        $this->sell($this->admin);

        Livewire::actingAs($this->admin)->test(SalesHistory::class)
            ->assertSeeHtml('class="pager"')
            ->assertSee('Showing 1')
            ->assertDontSeeHtml('aria-label="Pagination Navigation"');
    }

    public function test_go_to_pos_moved_into_the_page_body_gated_by_the_same_permission(): void
    {
        Livewire::actingAs($this->admin)->test(SalesHistory::class)
            ->assertSeeHtml('class="btn p"')
            ->assertSee('Go to POS');

        $response = $this->withoutMiddleware(EnsureSessionNotExpired::class)->actingAs($this->admin)->get(route('sales.index'));
        $response->assertDontSee('Go to POS →', false);
    }
}
