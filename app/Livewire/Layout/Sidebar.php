<?php

namespace App\Livewire\Layout;

use App\Models\GeneralSetting;
use App\Models\ModuleSetting;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * SRS Sec. 20.3 — nav items shown depend on the logged-in user's role and
 * permissions, and disabled modules are hidden entirely. Most of the
 * routes below don't exist yet (they land in Phases 02–11); each item only
 * renders as a link once `Route::has()` is true for it, and as inactive,
 * unlabelled-as-"soon" plain text otherwise — so this list doesn't need
 * editing again as later phases add their routes.
 *
 * A Livewire component rather than the plain Blade View\Component it
 * started as: the store name/logo (GeneralSettingChanged) and the
 * module-gated nav items (ModuleSettingChanged) both need to update on an
 * already-open tab without a full page navigation, which only a Livewire
 * component listening for those broadcasts can do — a static Blade
 * component only ever re-renders on the next full page load/wire:navigate.
 */
class Sidebar extends Component
{
    #[On('echo-private:settings,.ModuleSettingChanged')]
    #[On('echo-private:settings,.GeneralSettingChanged')]
    public function onSettingsChanged(): void
    {
        // No-op — render() below re-derives nav items and general settings fresh.
    }

    public function render()
    {
        $user = auth()->user();

        $items = collect($this->navItems())
            ->filter(fn (array $item) => $this->visibleTo($user, $item))
            ->values();

        return view('livewire.layout.sidebar', [
            'items' => $items,
            'generalSettings' => GeneralSetting::current(),
        ]);
    }

    /**
     * `section` is presentational only — it groups the nav list under a
     * small heading (SRS doesn't assign one, this doesn't gate anything);
     * a hidden item just leaves its heading out when every item under it
     * is filtered out too.
     *
     * @return array<int, array{label: string, route: string, icon: string, section?: string, permission?: array{string, string}, module?: string}>
     */
    private function navItems(): array
    {
        return [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home'],
            ['label' => 'POS', 'route' => 'pos.index', 'icon' => 'shopping-cart', 'section' => 'Operations', 'permission' => ['sales', 'create']],
            ['label' => 'Sales', 'route' => 'sales.index', 'icon' => 'banknotes', 'section' => 'Operations', 'permission' => ['sales', 'view']],
            ['label' => 'Return Management', 'route' => 'returns.index', 'icon' => 'arrow-uturn-left', 'section' => 'Operations', 'permission' => ['returns', 'view'], 'module' => 'return_management'],
            ['label' => 'Customers', 'route' => 'customers.index', 'icon' => 'users', 'section' => 'Operations', 'permission' => ['customers', 'view']],
            ['label' => 'Products', 'route' => 'products.index', 'icon' => 'cube', 'section' => 'Catalog & Stock', 'permission' => ['products', 'view']],
            ['label' => 'Categories', 'route' => 'categories.index', 'icon' => 'tag', 'section' => 'Catalog & Stock', 'permission' => ['categories', 'view']],
            ['label' => 'Suppliers', 'route' => 'suppliers.index', 'icon' => 'truck', 'section' => 'Catalog & Stock', 'permission' => ['suppliers', 'view']],
            ['label' => 'Inventory', 'route' => 'inventory.index', 'icon' => 'archive-box', 'section' => 'Catalog & Stock', 'permission' => ['inventory', 'view']],
            ['label' => 'Purchase Management', 'route' => 'purchase-orders.index', 'icon' => 'clipboard-check', 'section' => 'Catalog & Stock', 'permission' => ['purchase_orders', 'view'], 'module' => 'purchase_management'],
            ['label' => 'Reports', 'route' => 'reports.index', 'icon' => 'chart-bar', 'section' => 'Insights', 'permission' => ['reports', 'view']],
            ['label' => 'Users', 'route' => 'users.index', 'icon' => 'user-circle', 'section' => 'Administration', 'permission' => ['users', 'view']],
            ['label' => 'Roles & Permissions', 'route' => 'roles.index', 'icon' => 'shield-check', 'section' => 'Administration', 'permission' => ['roles', 'view']],
            ['label' => 'Audit Logs', 'route' => 'audit-logs.index', 'icon' => 'document-text', 'section' => 'Administration', 'permission' => ['audit_logs', 'view'], 'adminOnly' => true],
            ['label' => 'Notifications', 'route' => 'notifications.index', 'icon' => 'bell', 'section' => 'Administration', 'module' => 'notifications'],
            ['label' => 'Settings', 'route' => 'settings.index', 'icon' => 'cog', 'section' => 'Administration', 'permission' => ['settings', 'view']],
        ];
    }

    private function visibleTo($user, array $item): bool
    {
        if (! empty($item['adminOnly']) && ! $user->isAdministrator()) {
            return false;
        }

        if (isset($item['permission']) && ! $user->hasPermission(...$item['permission'])) {
            return false;
        }

        if (isset($item['module']) && ! ModuleSetting::enabled($item['module'])) {
            return false;
        }

        return true;
    }
}
