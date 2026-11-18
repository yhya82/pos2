<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSessionNotExpired;
use App\Livewire\Layout\Sidebar;
use App\Models\ModuleSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * The sidebar restyled into the POS/Dashboard visual language (flat,
 * boxy, red accent on the active item) — every bit of its real behavior
 * stays exactly as it was: permission/module-gated items, the Route::has()
 * fallback for routes that don't exist yet, active-route highlighting,
 * and the mobile/collapsed Alpine bindings.
 */
class SidebarRedesignTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    public function test_the_skin_is_scoped_and_loaded_on_every_page_not_just_one(): void
    {
        // Unlike pos-terminal.css/dashboard.css, this has to load
        // unconditionally — the sidebar is part of the shared layout, on
        // every page, not one page's own pushed stylesheet.
        $layout = file_get_contents(resource_path('views/components/layouts/app.blade.php'));

        $this->assertStringContainsString("resources/css/sidebar.css", $layout);
        $this->assertStringNotContainsString("@push('head')\n        @vite(['resources/css/sidebar.css'])", $layout);
    }

    public function test_the_palette_is_scoped_to_the_sidebar_not_the_whole_body(): void
    {
        // pos-terminal.css/dashboard.css put --acc etc. on body because
        // each only ever loads on its own one page. This stylesheet loads
        // everywhere, so putting the same vars on body would leak them
        // onto every other page's own content by the same names.
        $css = file_get_contents(resource_path('css/sidebar.css'));

        $this->assertMatchesRegularExpression('/\.side-skin \{[^}]*--acc: #a81c26/s', $css);
        $this->assertStringNotContainsString('body {', $css);
    }

    public function test_section_headings_are_bold(): void
    {
        $css = file_get_contents(resource_path('css/sidebar.css'));

        $this->assertMatchesRegularExpression('/\.nav-section-label \{[^}]*font-weight: 700/s', $css);
    }

    public function test_the_icon_sits_in_its_own_chip_not_a_bare_stroke(): void
    {
        $css = file_get_contents(resource_path('css/sidebar.css'));
        $blade = file_get_contents(resource_path('views/livewire/layout/sidebar.blade.php'));

        $this->assertMatchesRegularExpression('/\.icon-chip \{[^}]*background: var\(--soft\)/s', $css);
        $this->assertStringContainsString('<span class="icon-chip"><x-icon', $blade);
    }

    public function test_the_logo_has_a_light_red_border(): void
    {
        $css = file_get_contents(resource_path('css/sidebar.css'));

        $this->assertMatchesRegularExpression('/\.brand-mark \{[^}]*border: 1px solid var\(--acc-soft\)/s', $css);
        $this->assertMatchesRegularExpression('/\.brand-logo \{[^}]*border: 1px solid var\(--acc-soft\)/s', $css);
    }

    public function test_the_active_item_gets_a_solid_accent_fill_not_a_soft_tint(): void
    {
        // Same convention as the segmented controls on the POS/Dashboard
        // pages (payment method, period selector): the active state is a
        // solid accent fill with inverted text, not a lighter tint.
        $css = file_get_contents(resource_path('css/sidebar.css'));

        $this->assertMatchesRegularExpression('/\.nav-item\.active \{[^}]*background: var\(--acc\)/', $css);
    }

    public function test_nav_items_are_grouped_under_real_section_labels(): void
    {
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(Sidebar::class)
            ->assertSeeHtml('class="nav-section-label"')
            ->assertSee('Operations')
            ->assertSee('Catalog & Stock')
            ->assertSee('Administration');
    }

    public function test_an_item_filtered_out_by_permission_does_not_leave_its_label_text_elsewhere(): void
    {
        // Sections are purely presentational grouping, not a new
        // permission layer — hiding every item under one still hides
        // that section's own label too (it's only printed in front of a
        // visible item).
        $role = Role::create(['name' => 'No Ops '.uniqid(), 'description' => 'test', 'status' => 'active']);
        $user = User::factory()->create(['role_id' => $role->id]);

        Livewire::actingAs($user)->test(Sidebar::class)
            ->assertDontSee('Operations')
            ->assertDontSee('POS')
            ->assertSee('Dashboard');
    }

    public function test_the_active_route_is_still_highlighted(): void
    {
        // Needs a real page visit, not Livewire::test() in isolation —
        // request()->routeIs() has nothing to match against unless the
        // current request actually is that route. title="Products" (not
        // the href) anchors this to the actual nav item.
        $admin = User::factory()->create();

        $html = $this->withoutMiddleware(EnsureSessionNotExpired::class)
            ->actingAs($admin)
            ->get(route('products.index'))
            ->getContent();

        $this->assertMatchesRegularExpression('/title="Products"[^>]*class="nav-item active"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/title="Dashboard"[^>]*class="nav-item active"/s', $html);
    }

    public function test_a_route_that_does_not_exist_yet_still_renders_as_inert_not_a_link(): void
    {
        // Every real nav route happens to exist in this app already, so
        // this branch never actually fires for a logged-in user today —
        // it's still live in the markup for whenever a future item's
        // route isn't registered yet (the whole reason the component
        // checks Route::has() instead of assuming every item resolves).
        $blade = file_get_contents(resource_path('views/livewire/layout/sidebar.blade.php'));

        $this->assertStringContainsString('class="nav-item disabled"', $blade);
        $this->assertStringContainsString('$isLinkable = Route::has(', $blade);
    }

    public function test_the_module_gated_audit_log_link_is_still_administrator_only(): void
    {
        $role = Role::create(['name' => 'Auditor '.uniqid(), 'description' => 'test', 'status' => 'active']);
        $role->permissions()->attach(Permission::where('module', 'audit_logs')->where('action', 'view')->value('id'));
        $auditor = User::factory()->create(['role_id' => $role->id]);
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(Sidebar::class)->assertSee('Audit Logs');
        Livewire::actingAs($auditor)->test(Sidebar::class)->assertDontSee('Audit Logs');
    }

    public function test_a_disabled_module_still_hides_its_nav_item(): void
    {
        ModuleSetting::where('module_name', 'purchase_management')->update(['is_enabled' => false]);
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(Sidebar::class)->assertDontSee('Purchase Management');
    }

    public function test_the_mobile_and_collapse_alpine_bindings_are_still_there(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Sidebar::class)
            ->assertSeeHtml('x-show="$store.sidebar.open"')
            ->assertSeeHtml(":class=\"[\$store.sidebar.open ? 'translate-x-0' : '-translate-x-full', \$store.sidebar.collapsed ? 'md:w-16' : 'md:w-64']\"")
            ->assertSeeHtml('x-on:click="$store.sidebar.open = false"');
    }

    public function test_the_real_business_name_and_fallback_logo_mark_still_render(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Sidebar::class)
            ->assertSee('My Mini Market')
            ->assertSeeHtml('class="brand-mark"');
    }
}
