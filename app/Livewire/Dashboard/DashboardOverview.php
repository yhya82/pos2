<?php

namespace App\Livewire\Dashboard;

use App\Models\BatchExpiry;
use App\Models\CurrentStock;
use App\Models\InventorySetting;
use App\Models\ModuleSetting;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * SRS Sec. 20.8 / Sec. 12: summary cards, a chart, and a table — every
 * number here is a thin read of an existing reporting view or a simple
 * aggregate, no new business logic. Each card only queries (and renders)
 * if the viewer actually has permission to see that data.
 */
class DashboardOverview extends Component
{
    public function render()
    {
        $user = auth()->user();
        $canViewSales = $user->hasPermission('sales', 'view');
        $canViewInventory = $user->hasPermission('inventory', 'view');
        $canViewCustomers = $user->hasPermission('customers', 'view') && ModuleSetting::enabled('customer_credit');

        return view('livewire.dashboard.dashboard-overview', [
            'canViewSales' => $canViewSales,
            'canViewInventory' => $canViewInventory,
            'canViewCustomers' => $canViewCustomers,
            'todaySales' => $canViewSales ? $this->todaySales() : null,
            'lowStockCount' => $canViewInventory ? CurrentStock::where('is_low_stock', 1)->where('qty_on_hand', '>', 0)->count() : null,
            'expiringSoonCount' => $canViewInventory ? $this->expiringSoonCount() : null,
            'inventoryValue' => $canViewInventory ? (float) DB::table('v_inventory_valuation')->sum('value_at_selling_price') : null,
            'outstandingCredit' => $canViewCustomers ? (float) DB::table('v_credit_outstanding_balances')->sum('outstanding_balance') : null,
            'salesTrend' => $canViewSales ? $this->salesTrend() : [],
            'topProducts' => $canViewSales ? DB::table('v_product_sales_summary')->orderByDesc('total_revenue')->limit(5)->get() : collect(),
        ]);
    }

    private function todaySales(): object
    {
        return Sale::whereDate('sale_date', today())
            ->where('status', 'completed')
            ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(total_amount), 0) as revenue')
            ->first();
    }

    private function expiringSoonCount(): int
    {
        $withinDays = InventorySetting::current()->expiry_alert_days_2;

        return BatchExpiry::where('days_to_expiry', '<=', $withinDays)->where('days_to_expiry', '>=', 0)->count();
    }

    /**
     * @return array<int, array{day: string, label: string, revenue: float}>
     */
    private function salesTrend(): array
    {
        $rows = DB::table('v_daily_sales_summary')
            ->where('sale_day', '>=', now()->subDays(6)->toDateString())
            ->get()
            ->keyBy(fn ($row) => (string) $row->sale_day);

        $days = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $key = $date->toDateString();

            $days[] = [
                'day' => $key,
                'label' => $date->format('D'),
                'revenue' => (float) ($rows[$key]->total_revenue ?? 0),
            ];
        }

        return $days;
    }
}
