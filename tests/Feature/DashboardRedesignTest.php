<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSessionNotExpired;
use App\Livewire\Dashboard\DashboardOverview;
use App\Livewire\Dashboard\OnlineUsers;
use App\Livewire\Pos\Terminal;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\GeneralSetting;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StoreSetting;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * The redesigned dashboard: the new mockup's look wired to the same real
 * data and permission gates the old Tailwind-card version already used —
 * plus the handful of real gaps this round had to resolve (quantities
 * added to the low/out-of-stock lists, Pending Orders repurposed from a
 * bare count to a real list of purchase orders, invented mockup data —
 * credit due dates, an "away" presence state — left out rather than
 * fabricated).
 */
class DashboardRedesignTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
    }

    private function userWith(array $grants): User
    {
        $role = Role::create(['name' => 'Custom '.uniqid(), 'description' => 'test', 'status' => 'active']);
        $ids = collect($grants)->map(fn ($g) => Permission::where('module', $g[0])->where('action', $g[1])->value('id'));
        $role->permissions()->attach($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_the_skin_is_scoped_and_the_stylesheet_is_loaded(): void
    {
        $response = $this->withoutMiddleware(EnsureSessionNotExpired::class)->actingAs($this->admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSeeHtml('class="dash-skin"', false);
        $response->assertSee('dashboard.css', false);
    }

    public function test_the_whole_shared_header_bar_turns_red_on_this_page_too(): void
    {
        $css = file_get_contents(resource_path('css/dashboard.css'));

        $this->assertMatchesRegularExpression('/#app-header \{[^}]*background: var\(--acc\)/', $css);
    }

    public function test_the_layout_breakpoints_track_this_pages_own_width_not_the_raw_viewport(): void
    {
        // Same reasoning as the POS page's own layout breakpoints: the
        // shared sidebar's collapsed/expanded state changes this page's
        // real width without changing the viewport, so a plain @media
        // query can't see it.
        $css = file_get_contents(resource_path('css/dashboard.css'));

        $this->assertMatchesRegularExpression('/\.dash-skin \{[^}]*container-type: inline-size/s', $css);
        $this->assertStringContainsString('@container (max-width: 1150px)', $css);
        $this->assertStringContainsString('@container (max-width: 700px)', $css);
        $this->assertStringNotContainsString('@media (max-width: 1150px)', $css);
        $this->assertStringNotContainsString('@media (max-width: 700px)', $css);
    }

    public function test_the_header_has_no_duplicate_navigation_links(): void
    {
        // The real sidebar is already the navigation — this page doesn't
        // rebuild the mockup's own Dashboard/Sell/Stock links a second
        // time, same reason the POS page didn't rebuild a second bell.
        Livewire::actingAs($this->admin)->test(DashboardOverview::class)
            ->assertDontSee('Sell')
            ->assertDontSeeHtml('class="brand"');
    }

    public function test_the_period_selector_keeps_every_real_option_except_custom(): void
    {
        // Day/Week/Month/Year/All stay (Week and All aren't in the
        // mockup's own three-button .seg, but dropping real app features
        // to match it exactly would be a regression, not a redesign) —
        // Custom is explicitly dropped per request.
        Livewire::actingAs($this->admin)->test(DashboardOverview::class)
            ->assertSeeHtml("wire:click=\"setPeriod('day')\"")
            ->assertSeeHtml("wire:click=\"setPeriod('week')\"")
            ->assertSeeHtml("wire:click=\"setPeriod('month')\"")
            ->assertSeeHtml("wire:click=\"setPeriod('year')\"")
            ->assertSeeHtml("wire:click=\"setPeriod('all')\"")
            ->assertDontSeeHtml("wire:click=\"setPeriod('custom')\"")
            ->assertDontSee('Custom');
    }

    public function test_the_revenue_change_pill_compares_against_the_real_prior_period(): void
    {
        $user = $this->userWith([['sales', 'view'], ['sales', 'create'], ['financials', 'view']]);
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(100)->create();
        $cash = PaymentMethod::where('code', 'cash')->firstOrFail()->id;

        // Yesterday: 10.00. Today: 20.00 — a real 100% increase.
        Livewire::actingAs($user)->test(Terminal::class)->call('checkout', [['product_id' => $product->id, 'quantity' => 1]], null, $cash, true);
        Sale::latest('id')->first()->update(['sale_date' => now()->subDay()]);
        Livewire::actingAs($user)->test(Terminal::class)->call('checkout', [['product_id' => $product->id, 'quantity' => 2]], null, $cash, true);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->assertSee('+100% vs yesterday')
            ->assertSeeHtml('class="pill ok"');
    }

    public function test_the_revenue_change_pill_is_omitted_when_nothing_has_sold_ever(): void
    {
        // No sales anywhere, in any period — nothing real to compare,
        // and no "first sales" moment to note either, so no pill at all.
        $user = $this->userWith([['sales', 'view'], ['financials', 'view']]);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->call('setPeriod', 'all')
            ->assertDontSeeHtml('class="pill ok"')
            ->assertDontSeeHtml('class="pill "');
    }

    public function test_day_shows_a_no_prior_data_pill_rather_than_nothing_when_yesterday_had_no_sales(): void
    {
        // The prior period really did make no sales — not a bug, just
        // nothing to compare against. Rather than show nothing at all
        // (which reads as broken), this says so plainly instead of
        // inventing a percentage off a zero baseline.
        $user = $this->userWith([['sales', 'view'], ['sales', 'create'], ['financials', 'view']]);
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(10)->create();

        Livewire::actingAs($user)->test(Terminal::class)
            ->call('checkout', [['product_id' => $product->id, 'quantity' => 1]], null, PaymentMethod::where('code', 'cash')->firstOrFail()->id, true);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->assertSee('No prior data vs yesterday')
            ->assertDontSeeHtml('class="pill ok"');
    }

    public function test_all_compares_year_to_date_against_the_same_stretch_last_year(): void
    {
        // "All" has no real "previous all-time" to compare its own
        // lifetime total against, so it falls back to a real, like-for-
        // like comparison instead: this year so far vs the same stretch
        // last year — not a fabricated number, and not nothing either.
        $user = $this->userWith([['sales', 'view'], ['sales', 'create'], ['financials', 'view']]);
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(100)->create();
        $cash = PaymentMethod::where('code', 'cash')->firstOrFail()->id;

        // This year so far: 20.00.
        Livewire::actingAs($user)->test(Terminal::class)->call('checkout', [['product_id' => $product->id, 'quantity' => 2]], null, $cash, true);
        // Same stretch last year: 10.00 — a real 100% increase.
        Livewire::actingAs($user)->test(Terminal::class)->call('checkout', [['product_id' => $product->id, 'quantity' => 1]], null, $cash, true);
        Sale::latest('id')->first()->update(['sale_date' => now()->copy()->subYear()]);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->call('setPeriod', 'all')
            ->assertSee('+100% vs this time last year')
            ->assertSeeHtml('class="pill ok"');
    }

    public function test_every_money_figure_shows_the_real_configured_currency_code(): void
    {
        GeneralSetting::current()->update(['currency_code' => 'GMD']);
        $user = $this->userWith([['sales', 'view'], ['financials', 'view']]);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            // The code renders smaller, in its own span, so the number
            // stays what draws the eye — not plain "GMD 0.00" text.
            ->assertSeeHtml('<span class="ccy">GMD</span> 0.00');
    }

    public function test_month_view_shows_jan_through_dec_not_days_of_the_month(): void
    {
        $user = $this->userWith([['sales', 'view'], ['financials', 'view']]);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->call('setPeriod', 'month')
            ->assertSee('Jan')
            ->assertSee('Dec')
            // The headline figure still reflects just this month's own
            // total — only the chart's own context widens to the year.
            ->assertDontSeeHtml('>1<')
            ->assertDontSeeHtml('>31<');
    }

    public function test_year_view_shows_calendar_years_not_months(): void
    {
        $user = $this->userWith([['sales', 'view'], ['financials', 'view']]);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->call('setPeriod', 'year')
            ->assertSee((string) now()->year)
            ->assertDontSee('Jan')
            ->assertDontSee('Dec');
    }

    public function test_day_view_is_bounded_to_the_stores_configured_hours(): void
    {
        StoreSetting::current()->update(['opening_time' => '09:00:00', 'closing_time' => '17:00:00']);
        $user = $this->userWith([['sales', 'view'], ['financials', 'view']]);

        $html = Livewire::actingAs($user)->test(DashboardOverview::class)->html();

        $this->assertStringContainsString('>09<', $html);
        $this->assertStringContainsString('>17<', $html);
        $this->assertStringNotContainsString('>03<', $html);
    }

    public function test_top_products_ranks_by_quantity_sold_not_revenue(): void
    {
        $user = $this->userWith([['sales', 'view'], ['sales', 'create'], ['financials', 'view']]);
        $cash = PaymentMethod::where('code', 'cash')->firstOrFail()->id;

        // Expensive, bought once: higher revenue, fewer units.
        $expensive = Product::factory()->create(['name' => 'Pricey Item', 'selling_price' => 100]);
        Batch::factory()->for($expensive)->remaining(10)->create();
        // Cheap, bought in bulk: lower revenue, more units — this is the
        // real "most sales" product and has to rank first.
        $cheap = Product::factory()->create(['name' => 'Popular Item', 'selling_price' => 2]);
        Batch::factory()->for($cheap)->remaining(50)->create();

        Livewire::actingAs($user)->test(Terminal::class)
            ->call('checkout', [['product_id' => $expensive->id, 'quantity' => 1]], null, $cash, true);
        Livewire::actingAs($user)->test(Terminal::class)
            ->call('checkout', [['product_id' => $cheap->id, 'quantity' => 20]], null, $cash, true);

        $topProducts = Livewire::actingAs($user)->test(DashboardOverview::class)->viewData('topProducts');

        $this->assertSame('Popular Item', $topProducts->first()->product_name);
    }

    public function test_low_stock_and_out_of_stock_cards_show_a_real_quantity_not_just_a_name(): void
    {
        $user = $this->userWith([['inventory', 'view']]);

        $low = Product::factory()->create(['name' => 'Almost Gone', 'min_stock_level' => 10]);
        Batch::factory()->for($low)->remaining(2)->create();

        $out = Product::factory()->create(['name' => 'All Sold']);
        Batch::factory()->for($out)->remaining(0)->create(['qty_received' => 5]);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->assertSee('Almost Gone')
            ->assertSee('2 left')
            ->assertSee('All Sold')
            ->assertSee('Out of stock');
    }

    public function test_the_out_of_stock_card_does_not_invent_a_since_date(): void
    {
        // The mockup showed "Out since Oct 21" per item — nothing in this
        // app tracks when a product actually ran out, so that detail is
        // left out rather than fabricated.
        $user = $this->userWith([['inventory', 'view']]);

        $out = Product::factory()->create(['name' => 'Gone Product']);
        Batch::factory()->for($out)->remaining(0)->create(['qty_received' => 5]);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->assertDontSee('Out since');
    }

    public function test_top_products_shows_quantity_sold_and_falls_back_to_a_cube_icon(): void
    {
        $user = $this->userWith([['sales', 'view'], ['financials', 'view'], ['sales', 'create']]);

        $product = Product::factory()->create(['name' => 'Best Seller', 'selling_price' => 10]);
        Batch::factory()->for($product)->remaining(20)->create();

        Livewire::actingAs($user)->test(Terminal::class)
            ->call('checkout', [['product_id' => $product->id, 'quantity' => 3]], null, PaymentMethod::where('code', 'cash')->firstOrFail()->id, true);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->assertSee('Best Seller')
            ->assertSee('3 sold')
            // No real photo on this product, so the same fallback cube
            // icon the POS product tiles use, not an invented per-category
            // icon like the mockup's own head/watch/bag set. <x-icon> is
            // compiled away by the time ->html() sees it, so this checks
            // for the cube icon's own SVG path instead of the tag.
            ->assertSee('M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9', false);
    }

    public function test_pending_orders_is_a_real_purchase_order_list_not_the_mockups_fictional_customer_orders(): void
    {
        $user = $this->userWith([['purchase_orders', 'view']]);

        $supplier = Supplier::create(['name' => 'Acme Supplies', 'status' => 'active']);
        $po = PurchaseOrder::create([
            'po_number' => PurchaseOrder::generatePoNumber(),
            'supplier_id' => $supplier->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);
        $product = Product::factory()->create();
        $po->lineItems()->create([
            'product_id' => $product->id,
            'qty_ordered' => 10,
            'purchase_unit_id' => $product->purchase_unit_id,
            'cost_price' => 15,
        ]);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->assertSee($po->po_number)
            ->assertSee('Acme Supplies')
            ->assertSee(number_format(150, 2))
            ->assertSee('Ordered')
            // Not the mockup's own invented shape for this card (a
            // customer's name/avatar and a "Fulfill" button) — this is a
            // supplier order, so it reads as one.
            ->assertDontSeeHtml('class="av"');
    }

    public function test_outstanding_credit_shows_the_balance_only_not_an_invented_due_date_or_reminder(): void
    {
        // The mockup showed "Overdue 14d" / "Due in 3d" and a "Remind"
        // button per customer — nothing in this app tracks a due date or
        // sends reminders, so neither is fabricated here.
        $user = $this->userWith([['customers', 'view']]);

        Customer::factory()->create(['name' => 'Fatou Jallow', 'credit_enabled' => true, 'credit_limit' => 1000, 'outstanding_balance' => 250]);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->assertSee('Fatou Jallow')
            ->assertSee(number_format(250, 2))
            ->assertDontSee('Overdue')
            ->assertDontSee('Due in')
            ->assertDontSeeHtml('>Remind<');
    }

    public function test_online_users_card_has_no_invented_away_state_or_delta(): void
    {
        // The mockup's Online card showed an "away 12m" status and a
        // "+2 since last hour" delta — the real presence channel only
        // ever reports binary online/offline, with no history, so neither
        // is fabricated here.
        $html = Livewire::actingAs($this->admin)->test(DashboardOverview::class)->html();

        $this->assertStringNotContainsString('away', $html);
        $this->assertStringNotContainsString('since last hour', $html);
    }

    public function test_payment_methods_and_cashier_leaderboard_cards_are_dropped_to_match_the_new_mockup(): void
    {
        // The new mockup's bottom row only has four cards — Payment
        // Methods and Sales by Cashier aren't among them, so (per an
        // explicit decision) they're dropped rather than bolted on below.
        Livewire::actingAs($this->admin)->test(DashboardOverview::class)
            ->assertDontSee('Payment Methods')
            ->assertDontSee('Sales by Cashier');
    }

    public function test_a_role_with_no_dashboard_visibility_at_all_sees_the_fallback_message(): void
    {
        // Genuinely nothing — not even sales,create, which now earns its
        // own "Your activity today" section instead of this fallback.
        $user = $this->userWith([]);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->assertSee("doesn't have visibility into any dashboard data yet", false);
    }

    public function test_a_role_with_only_sales_create_sees_its_own_activity_instead_of_the_fallback(): void
    {
        $user = $this->userWith([['sales', 'create']]);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->assertDontSee("doesn't have visibility into any dashboard data yet", false)
            ->assertSee('Your sales total today');
    }

    public function test_the_header_shows_the_viewers_actual_role_name_not_a_generic_label(): void
    {
        $role = Role::create(['name' => 'Floor Manager', 'description' => 'test', 'status' => 'active']);
        $user = User::factory()->create(['role_id' => $role->id]);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->assertSee('Floor Manager');
    }

    private function sell(User $cashier, Product $product, int $qty = 1): Sale
    {
        return app(\App\Services\SaleService::class)->completeSale(
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

    public function test_own_activity_is_scoped_to_the_viewers_own_sales_only(): void
    {
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(20)->create();

        $me = $this->userWith([['sales', 'create']]);
        $other = User::factory()->cashier()->create();

        $this->sell($me, $product, 2);
        $this->sell($other, $product, 5);

        Livewire::actingAs($me)->test(DashboardOverview::class)
            ->assertSee('Your sales total today')
            ->assertViewHas('ownActivity', fn ($activity) => $activity['transactions'] === 1 && (float) $activity['itemsSold'] === 2.0);
    }

    public function test_own_activity_is_hidden_for_an_administrator_who_already_sees_store_wide_revenue(): void
    {
        Livewire::actingAs($this->admin)->test(DashboardOverview::class)
            ->assertDontSee('Your sales total today');
    }

    public function test_own_activity_is_hidden_for_a_role_without_sales_create(): void
    {
        $user = $this->userWith([['inventory', 'view']]);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->assertDontSee('Your sales total today');
    }

    /**
     * A role without financials.view never renders the top financial
     * section at all — Refunds/Outstanding Credit move into the .g4
     * row instead, alongside Low Stock/Out of Stock, so they land in
     * the same line rather than a section of their own.
     */
    public function test_the_top_financial_section_is_not_rendered_for_a_role_without_financials_view(): void
    {
        \App\Models\ModuleSetting::where('module_name', 'return_management')->update(['is_enabled' => true]);
        $user = $this->userWith([['returns', 'view']]);

        Livewire::actingAs($user)->test(DashboardOverview::class)
            ->assertDontSeeHtml('<section class="g">', false)
            ->assertSeeHtml('<section class="g4">', false)
            ->assertSeeHtml('Refunds', false);
    }

    public function test_the_top_financial_section_is_rendered_for_someone_who_can_see_financials(): void
    {
        Livewire::actingAs($this->admin)->test(DashboardOverview::class)
            ->assertSeeHtml('<section class="g">', false);
    }

    public function test_restock_links_to_the_product_profile_only_with_products_and_inventory_permission(): void
    {
        $low = Product::factory()->create(['name' => 'Almost Gone', 'min_stock_level' => 10]);
        Batch::factory()->for($low)->remaining(2)->create();

        $out = Product::factory()->create(['name' => 'All Sold']);
        Batch::factory()->for($out)->remaining(0)->create(['qty_received' => 5]);

        // Inventory view alone isn't enough — the product profile link
        // needs products,view (to open the page) and inventory,update
        // (to actually see the Add Stock button once there).
        $inventoryOnly = $this->userWith([['inventory', 'view']]);

        Livewire::actingAs($inventoryOnly)->test(DashboardOverview::class)
            ->assertDontSee(route('products.show', $low))
            ->assertDontSee(route('products.show', $out))
            ->assertSee(route('inventory.index', ['tab' => 'stock', 'low_stock' => 1]));

        $canRestock = $this->userWith([['inventory', 'view'], ['inventory', 'update'], ['products', 'view']]);

        Livewire::actingAs($canRestock)->test(DashboardOverview::class)
            ->assertSee(route('products.show', $low))
            ->assertSee(route('products.show', $out));
    }

    public function test_the_online_users_widget_renders_initials_once_the_presence_roster_syncs(): void
    {
        // The roster arrives from the browser's own Echo.join() callback,
        // forwarded in as a plain event — never populated on the initial
        // server render, which is why this only ever broke live, the
        // instant a real login's presence sync landed.
        $other = User::factory()->create(['name' => 'Jane Doe']);

        Livewire::actingAs($this->admin)->test(OnlineUsers::class)
            ->dispatch('online-users-synced', users: [
                ['id' => $other->id, 'name' => $other->name, 'role' => null],
            ])
            ->assertHasNoErrors()
            ->assertSee('JD')
            ->assertSee('Jane Doe');
    }
}
