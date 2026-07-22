<?php

namespace App\View\Components;

use App\Models\ModuleSetting;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * SRS Sec. 20.3 — nav items shown depend on the logged-in user's role and
 * permissions, and disabled modules are hidden entirely. Most of the
 * routes below don't exist yet (they land in Phases 02–11); each item only
 * renders as a link once `Route::has()` is true for it, and as inactive,
 * unlabelled-as-"soon" plain text otherwise — so this list doesn't need
 * editing again as later phases add their routes.
 */
class Sidebar extends Component
{
    public function render(): View
    {
        $user = auth()->user();

        $items = collect($this->navItems())
            ->filter(fn (array $item) => $this->visibleTo($user, $item))
            ->values();

        return view('components.sidebar', ['items' => $items]);
    }

    /**
     * @return array<int, array{label: string, route: string, icon: string, permission?: array{string, string}, module?: string}>
     */
    private function navItems(): array
    {
        return [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home'],
            ['label' => 'POS', 'route' => 'pos.index', 'icon' => 'shopping-cart', 'permission' => ['sales', 'create']],
            ['label' => 'Products', 'route' => 'products.index', 'icon' => 'cube', 'permission' => ['products', 'view']],
            ['label' => 'Categories', 'route' => 'categories.index', 'icon' => 'tag', 'permission' => ['categories', 'view']],
            ['label' => 'Suppliers', 'route' => 'suppliers.index', 'icon' => 'truck', 'permission' => ['suppliers', 'view']],
            ['label' => 'Inventory', 'route' => 'inventory.index', 'icon' => 'archive-box', 'permission' => ['inventory', 'view']],
            ['label' => 'Customers', 'route' => 'customers.index', 'icon' => 'users', 'permission' => ['customers', 'view']],
            ['label' => 'Sales', 'route' => 'sales.index', 'icon' => 'banknotes', 'permission' => ['sales', 'view']],
            ['label' => 'Purchase Management', 'route' => 'purchase-orders.index', 'icon' => 'clipboard-check', 'permission' => ['purchase_orders', 'view'], 'module' => 'purchase_management'],
            ['label' => 'Return Management', 'route' => 'returns.index', 'icon' => 'arrow-uturn-left', 'permission' => ['returns', 'view'], 'module' => 'return_management'],
            ['label' => 'Reports', 'route' => 'reports.index', 'icon' => 'chart-bar', 'permission' => ['reports', 'view']],
            ['label' => 'Users', 'route' => 'users.index', 'icon' => 'user-circle', 'permission' => ['users', 'view']],
            ['label' => 'Roles & Permissions', 'route' => 'roles.index', 'icon' => 'shield-check', 'permission' => ['roles', 'view']],
            ['label' => 'Audit Logs', 'route' => 'audit-logs.index', 'icon' => 'document-text', 'permission' => ['audit_logs', 'view']],
            ['label' => 'Notifications', 'route' => 'notifications.index', 'icon' => 'bell', 'module' => 'notifications'],
            ['label' => 'Settings', 'route' => 'settings.index', 'icon' => 'cog', 'permission' => ['settings', 'view']],
        ];
    }

    private function visibleTo($user, array $item): bool
    {
        if (isset($item['permission']) && ! $user->hasPermission(...$item['permission'])) {
            return false;
        }

        if (isset($item['module']) && ! ModuleSetting::enabled($item['module'])) {
            return false;
        }

        return true;
    }
}
