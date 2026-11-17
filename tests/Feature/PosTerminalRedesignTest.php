<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSessionNotExpired;
use App\Livewire\Pos\Terminal;
use App\Livewire\Pos\TillHeader;
use App\Models\Batch;
use App\Models\HardwareSetting;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * The redesigned till: real data wired into the mockup's exact look and
 * interactions — payment-method buttons, a real persisted receipt behind
 * the printer animation, a header widget scoped the same way the rest of
 * the app scopes "my own sales", and a real low-stock threshold driving
 * the bell and the tile styling.
 */
class PosTerminalRedesignTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->cashier()->create();
    }

    private function sell(User $cashier, Product $product, float $qty = 1): Sale
    {
        return app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => $qty]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            referenceNumber: null,
            discountType: 'none', discountValue: 0, discountReason: null,
            cashier: $cashier,
        );
    }

    // ------------------------------------------------------------ products

    public function test_a_products_own_minimum_stock_is_sent_to_the_till(): void
    {
        $product = Product::factory()->create(['min_stock_level' => 5]);
        Batch::factory()->for($product)->remaining(20)->create();

        $products = Livewire::actingAs($this->cashier)->test(Terminal::class)->viewData('products');

        $this->assertEquals(5.0, $products->firstWhere('id', $product->id)['min_stock_level']);
    }

    // --------------------------------------------------------- paper size

    /** @dataProvider paperSizes */
    public function test_the_hardware_paper_size_is_mapped_to_a_real_print_width(string $configured, string $expectedWidth): void
    {
        HardwareSetting::current()->update(['paper_size' => $configured]);

        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSeeHtml('--paper-width: '.$expectedWidth);
    }

    public static function paperSizes(): array
    {
        return [
            '58mm roll' => ['58mm', '58mm'],
            '80mm roll' => ['80mm', '80mm'],
            'A4 sheet' => ['A4', '210mm'],
        ];
    }

    public function test_paper_size_is_included_in_the_live_refresh_payload(): void
    {
        HardwareSetting::current()->update(['paper_size' => '58mm']);

        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->call('onLiveDataChanged')
            ->assertDispatched('pos-live-update', paperSize: '58mm');
    }

    // ------------------------------------------------------ real receipt

    public function test_checkout_returns_the_real_persisted_receipt_snapshot(): void
    {
        $product = Product::factory()->create(['selling_price' => 10, 'cost_price' => 6]);
        Batch::factory()->for($product)->remaining(20)->create(['unit_cost' => 5]);

        $result = null;

        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->call('checkout', [['product_id' => $product->id, 'quantity' => 2]], null, PaymentMethod::where('code', 'cash')->firstOrFail()->id, null)
            ->assertReturned(function ($returned) use (&$result) {
                $result = $returned;

                return true;
            });

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['receipt']);

        $sale = Sale::findOrFail($result['saleId']);
        $receipt = $sale->receipt;

        $this->assertSame($receipt->business_snapshot, $result['receipt']['business']);
        $this->assertSame($receipt->line_items_snapshot, $result['receipt']['lines']);
        $this->assertSame($receipt->totals_snapshot, $result['receipt']['totals']);
        $this->assertEquals(20.00, $result['receipt']['totals']['total_amount']);

        // Receipt number/date/cashier aren't part of the receipt's own
        // frozen snapshot (those columns live on the sale itself), so this
        // is the one piece of the payload built straight from $sale rather
        // than copied off $receipt.
        $this->assertSame($sale->receipt_number, $result['receipt']['meta']['receipt_number']);
        $this->assertSame($sale->sale_date->toIso8601String(), $result['receipt']['meta']['sale_date']);
        $this->assertSame($this->cashier->name, $result['receipt']['meta']['cashier_name']);
        $this->assertNull($result['receipt']['meta']['customer_name']);
    }

    public function test_the_terminal_root_is_wire_ignored_so_a_live_broadcast_cannot_close_the_receipt(): void
    {
        // Completing a sale deducts stock, which broadcasts StockChanged
        // right back to this same terminal (it listens for it too, to
        // keep other cashiers' screens live) — without wire:ignore, the
        // re-render that listener triggers restores the literal x-cloak
        // marker from the freshly rendered HTML (Alpine only strips it
        // once, at first paint; it isn't a binding morph knows to leave
        // alone), snapping [x-cloak]{display:none} back onto the receipt
        // dialog mid-animation and closing it a couple of seconds after
        // checkout. Nothing in this component's markup ever needs a
        // fresh server render after mount anyway — every later update
        // already flows through refreshLiveData() via a browser event.
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSeeHtml('wire:ignore');
    }

    // -------------------------------------------------------- till header

    public function test_the_tills_own_sales_total_counts_only_the_cashiers_own_sales(): void
    {
        $other = User::factory()->cashier()->create();
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(20)->create();

        $this->sell($this->cashier, $product, 2);   // 20.00
        $this->sell($other, $product, 3);           // 30.00, not this cashier's

        $this->assertEquals(20.00, Livewire::actingAs($this->cashier)->test(TillHeader::class)->viewData('todaysSalesTotal'));
    }

    public function test_the_view_sales_widget_is_a_real_link_to_the_sales_page_not_a_modal(): void
    {
        // It used to open a little in-page sales-log modal of its own —
        // now it's a plain navigation to the real Sales page, which
        // already has its own full history, filters, and permission
        // gate; no reason for the till to duplicate any of that.
        Livewire::actingAs($this->cashier)->test(TillHeader::class)
            ->assertSeeHtml('href="' . route('sales.index') . '"')
            ->assertDontSeeHtml('wire:click="openSalesLog"')
            ->assertDontSeeHtml('till-sales-log');
    }

    public function test_the_view_sales_link_needs_the_sales_view_permission(): void
    {
        $role = Role::create(['name' => 'Till Only', 'description' => 'test', 'status' => 'active']);
        $role->permissions()->attach(Permission::where('module', 'sales')->where('action', 'create')->value('id'));
        $noSalesView = User::factory()->create(['role_id' => $role->id]);

        Livewire::actingAs($noSalesView)->test(TillHeader::class)
            ->assertDontSee('View sales');

        // The Cashier role is already granted sales,view by default.
        Livewire::actingAs($this->cashier)->test(TillHeader::class)
            ->assertSee('View sales');
    }

    public function test_the_header_has_no_second_bell_stock_alerts_already_go_through_the_apps_own_notifications(): void
    {
        // One notification system, not a POS-only duplicate of it — the
        // real NotificationBell (already in the shared header on every
        // page, low stock included) is what this defers to.
        Livewire::actingAs($this->cashier)->test(TillHeader::class)
            ->assertDontSee('Stock alerts')
            ->assertDontSeeHtml('wire:click="toggleAlerts"')
            ->assertSeeHtml('href="' . route('sales.index') . '"');
    }

    public function test_the_header_widgets_get_the_mockups_accent_styling(): void
    {
        Livewire::actingAs($this->cashier)->test(TillHeader::class)
            ->assertSeeHtml('class="header-widgets"')
            ->assertSeeHtml('class="brand"')
            ->assertSeeHtml('class="link"')
            ->assertSeeHtml('class="today"');
    }

    public function test_the_whole_shared_header_bar_turns_red_on_this_page(): void
    {
        $css = file_get_contents(resource_path('css/pos-terminal.css'));

        // The real bar itself (#app-header, every page's shared header),
        // not just a chip floating on top of it.
        $this->assertMatchesRegularExpression('/#app-header \{[^}]*background: var\(--acc\)/', $css);
    }

    public function test_the_headers_own_dropdown_panel_is_excluded_from_the_recolour(): void
    {
        $css = file_get_contents(resource_path('css/pos-terminal.css'));

        // The account-menu/dropdown panel the header already opens is a
        // normal light popover wherever it's used — it isn't part of the
        // bar itself, so the blanket white-text rule explicitly carves it
        // (and the real notification bell's own dropdown) back out.
        $this->assertStringContainsString(':not(.absolute, .absolute *)', $css);
    }

    public function test_the_cart_is_titled_current_sale(): void
    {
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSee('Current Sale')
            ->assertDontSeeHtml('<h2 @click="if (window.innerWidth <= 960) cartOpen = false">Cart</h2>');
    }

    public function test_the_current_sale_status_line_says_empty_or_the_item_count(): void
    {
        $product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($product)->remaining(20)->create();

        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSee('Cart is empty')
            ->assertSeeHtml('cart.length ? cartCount()');
    }

    public function test_an_empty_cart_shows_no_separate_image_and_text_block_inside_the_lines_area(): void
    {
        // The status line already says "Cart is empty" — this would only be
        // a second, redundant copy of the same message, with a cube icon.
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertDontSeeHtml('class="emptycart"')
            ->assertDontSee('Tap a product to add it.');
    }

    public function test_the_customer_field_renders_above_the_lines_when_the_cart_is_empty_and_below_once_it_has_items(): void
    {
        $html = Livewire::actingAs($this->cashier)->test(Terminal::class)->html();

        $this->assertMatchesRegularExpression(
            '/cart\.length === 0.*id="pos_customer".*class="lines".*cart\.length > 0.*id="pos_customer_bottom"/s',
            $html
        );
    }

    public function test_the_two_customer_fields_use_different_ids_not_a_duplicate(): void
    {
        // Only one is ever actually mounted at a time (x-if), but the
        // static source must not declare the same id twice regardless.
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSeeHtml('id="pos_customer"')
            ->assertSeeHtml('id="pos_customer_bottom"')
            ->assertSeeHtml('for="pos_customer_bottom"');
    }

    public function test_the_lines_area_collapses_when_the_cart_is_empty_leaving_no_gap(): void
    {
        // Alpine's x-for keeps its own <template> tag as a permanent DOM
        // anchor inside .lines, so the element is never actually
        // CSS-:empty — a :class binding tied to the real cart length is
        // what has to drive the collapse instead.
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSeeHtml(':class="cart.length === 0 ? \'lines-empty\' : \'\'"');

        $css = file_get_contents(resource_path('css/pos-terminal.css'));
        $this->assertStringContainsString('.lines.lines-empty', $css);
        $this->assertStringNotContainsString('.lines:empty', $css);
    }

    public function test_an_out_of_stock_tile_is_given_a_light_neutral_border_not_the_accent_red(): void
    {
        // A solid accent-red border reads as active/selected — the
        // opposite of "can't be added" — so a disabled tile needs the
        // same muted border used elsewhere for low-emphasis elements.
        $css = file_get_contents(resource_path('css/pos-terminal.css'));

        $this->assertMatchesRegularExpression(
            '/\.p:disabled \{[^}]*border-color: var\(--line\)/',
            $css
        );
    }

    public function test_the_print_rule_removes_the_rest_of_the_page_instead_of_just_hiding_it(): void
    {
        // visibility:hidden leaves every other element's box in the flow,
        // so the print job paginates against the whole app's height
        // instead of just the receipt's — display:none removes it
        // outright, so the printed page is exactly the receipt's height.
        $css = file_get_contents(resource_path('css/pos-terminal.css'));

        $this->assertStringContainsString("body *:not(.pos-skin .rcpt):not(.pos-skin .rcpt *) { display: none !important; }", $css);
        $this->assertStringNotContainsString('visibility: hidden !important', $css);
        $this->assertStringContainsString('@page { margin: 0; }', $css);
    }

    public function test_the_receipt_paper_gets_its_torn_perforated_edge(): void
    {
        // The on-screen look, not just the print-mode override that
        // cancels it — without this the paper is a plain rectangle.
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSeeHtml('paperClip')
            ->assertSee("return 'polygon(0 0, 100% 0, 100% calc(100% - 4px), '", false)
            ->assertSeeHtml(":style=\"'clip-path: ' + paperClip\"");
    }

    public function test_the_receipt_preview_shows_the_stores_own_receipt_business_info_setting(): void
    {
        // Settings → "Receipt Business Info" ("printed at the top of every
        // receipt") is already in the business snapshot checkout() returns
        // — the real printed receipt (sales/receipt.blade.php) shows it
        // grouped with the address/phone, so the animated preview has to
        // read and render the same field instead of silently dropping it.
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSee("[b.address, b.contact_phone, b.receipt_business_info]", false);
    }

    public function test_the_receipt_preview_shows_receipt_number_date_and_cashier(): void
    {
        // These three (plus the customer, if any) were missing from the
        // preview entirely — the real printed receipt has always shown
        // them. Grouped into their own <br>-joined block, separated from
        // the business-name/address block above by an <hr>, rather than
        // another bare div glued on with no gap.
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSee("'Receipt: ' + esc(m.receipt_number)", false)
            ->assertSee("'Date: ' + this.receiptDate(m.sale_date)", false)
            ->assertSee("'Cashier: ' + esc(m.cashier_name)", false)
            ->assertSee("m.customer_name ? 'Customer: ' + esc(m.customer_name) : null", false)
            ->assertSeeHtml('receiptDate(iso)');
    }

    public function test_the_printer_housing_has_breathing_room_above_its_status_row(): void
    {
        // 12px left the spinner/status row crowding the very top edge of
        // the dark printer housing graphic — bumped up, while the bottom
        // (32px) stays as-is since it has to clear the paper-slot cutout.
        $css = file_get_contents(resource_path('css/pos-terminal.css'));

        $this->assertStringContainsString('.mach { position: relative; isolation: isolate; width: 100%; background: var(--pm); border: 1px solid #111; padding: 20px 12px 32px;', $css);
    }

    public function test_the_receipt_dialog_shrinks_to_fit_instead_of_scrolling(): void
    {
        // zoom, not transform:scale — scale only changes how an element
        // paints, not the layout space it reserves, so the dialog would
        // still overflow (and need a scrollbar) by the unscaled height
        // even though it visually looked shrunk down.
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSeeHtml('fitReceipt()')
            ->assertSee('wrap.style.zoom = 1;', false)
            ->assertSee('wrap.style.zoom = Math.max(.5, available / natural);', false)
            ->assertSeeHtml('x-on:resize.window="if (receiptOpen) fitReceipt()"');

        $css = file_get_contents(resource_path('css/pos-terminal.css'));
        $this->assertMatchesRegularExpression('/@media print \{.*\.rwrap \{ zoom: 1 !important; \}/s', $css);
    }

    public function test_the_tendered_field_highlights_and_focuses_itself_when_the_amount_is_short(): void
    {
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSeeHtml('x-ref="tendered"')
            ->assertSeeHtml(':class="tenderedError ? \'err\' : \'\'"')
            ->assertSee("this.tenderedError = true", false)
            ->assertSee('this.$refs.tendered?.focus()', false);

        $css = file_get_contents(resource_path('css/pos-terminal.css'));
        $this->assertStringContainsString('.f input.err', $css);
    }

    public function test_the_product_tiles_price_and_stock_stay_on_one_line(): void
    {
        $css = file_get_contents(resource_path('css/pos-terminal.css'));

        // nowrap (not the mockup's own wrap) so the two can never break
        // onto separate lines; the stock label is what yields space
        // (ellipsizing) rather than the price, which always stays whole.
        $this->assertMatchesRegularExpression('/\.p \.row \{[^}]*flex-wrap: nowrap/', $css);
        $this->assertMatchesRegularExpression('/\.p \.pc \{[^}]*flex-shrink: 0/', $css);
        $this->assertMatchesRegularExpression('/\.p \.s \{[^}]*text-overflow: ellipsis/', $css);
    }

    // --------------------------------------------------------- interactions

    public function test_payment_method_is_a_button_segmented_control_not_a_dropdown(): void
    {
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSeeHtml('class="seg"')
            ->assertSeeHtml(':aria-pressed="method.id === paymentMethodId"')
            ->assertDontSeeHtml('x-model.number="paymentMethodId"');
    }

    public function test_tendered_amount_has_the_quick_cash_chips(): void
    {
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSeeHtml('quickCashAmounts()')
            ->assertSee("i === 0 && total() > 0 ? 'Exact'", false);
    }

    public function test_the_complete_sale_button_reacts_to_credit_without_a_customer(): void
    {
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSeeHtml('goLabel()')
            ->assertSee("return 'Choose a customer for credit'", false)
            ->assertSee("return 'Complete sale'", false);
    }

    public function test_the_layout_breakpoints_track_this_pages_own_width_not_the_raw_viewport(): void
    {
        // @media (min/max-width) only ever sees the viewport — expanding
        // the shared sidebar shrinks this page's actual available width
        // without changing the viewport at all, so a plain @media query
        // kept forcing the same column count / desktop cart layout even
        // once the expanded sidebar had squeezed this page down to
        // something that layout no longer fit. @container, scoped to
        // .pos-skin itself (container-type: inline-size), responds to
        // the page's own rendered width instead, which does shrink when
        // the sidebar expands.
        $css = file_get_contents(resource_path('css/pos-terminal.css'));

        $this->assertMatchesRegularExpression('/\.pos-skin \{[^}]*container-type: inline-size/s', $css);
        $this->assertStringContainsString('@container (min-width: 640px)', $css);
        $this->assertStringContainsString('@container (min-width: 961px)', $css);
        $this->assertStringContainsString('@container (max-width: 960px)', $css);
        $this->assertStringNotContainsString('@media (min-width: 640px)', $css);
        $this->assertStringNotContainsString('@media (min-width: 961px)', $css);
        $this->assertStringNotContainsString('@media (max-width: 960px)', $css);

        // The old forced-exactly-4-columns rule had no minimum width per
        // column (minmax(0, 1fr)) — nothing stopped it from squeezing
        // every tile down to a sliver once the sidebar ate into the
        // available space. auto-fill with a real floor shows fewer,
        // properly-sized columns instead.
        $this->assertStringNotContainsString('repeat(4, minmax(0, 1fr))', $css);
    }

    public function test_the_cart_panel_scrolls_as_one_whole_unit_not_a_split_inner_lane(): void
    {
        $css = file_get_contents(resource_path('css/pos-terminal.css'));
        [$desktop, $mobile] = explode('@container (max-width: 960px)', $css, 2);

        // The whole aside is the one scrolling container (bounded height +
        // overflow on the element itself) — not a layout split into a
        // pinned header/footer around a separately-scrolling inner .body.
        $this->assertMatchesRegularExpression('/\.pos-skin aside \{[^}]*max-height: calc\(100vh[^}]*overflow-y: auto/', $desktop);
        $this->assertDoesNotMatchRegularExpression('/\.pos-skin \.body \{[^}]*overflow-y/', $desktop);
        // On the mobile full-screen sheet the aside itself is the full
        // height already, so the max-height constraint is lifted there.
        $this->assertStringContainsString('max-height: none', $mobile);
    }

    public function test_the_skin_is_scoped_and_the_stylesheet_is_loaded(): void
    {
        Livewire::actingAs($this->cashier)->test(Terminal::class)
            ->assertSeeHtml('class="pos-skin"');

        $this->withoutMiddleware(EnsureSessionNotExpired::class)
            ->actingAs($this->cashier)
            ->get(route('pos.index'))
            ->assertSee('pos-terminal.css', false);
    }
}
