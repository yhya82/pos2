<?php

namespace App\Livewire\Dashboard;

use App\Models\BatchExpiry;
use App\Models\CurrentStock;
use App\Models\InventorySetting;
use App\Models\ModuleSetting;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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
    /**
     * Drives the revenue card, the trend chart, and Top Products together —
     * same day/week/month/year presets as the Reports page (SRS Sec. 20.14),
     * so "filter the sales" means one control that scopes all three.
     */
    public string $period = 'day';

    public function setPeriod(string $period): void
    {
        if (! in_array($period, ['day', 'week', 'month', 'year'], true)) {
            return;
        }

        $this->period = $period;
    }

    public function render()
    {
        $user = auth()->user();
        $canViewSales = $user->hasPermission('sales', 'view');
        $canViewInventory = $user->hasPermission('inventory', 'view');
        $canViewCustomers = $user->hasPermission('customers', 'view') && ModuleSetting::enabled('customer_credit');

        [$from, $to] = $this->periodRange();

        return view('livewire.dashboard.dashboard-overview', [
            'canViewSales' => $canViewSales,
            'canViewInventory' => $canViewInventory,
            'canViewCustomers' => $canViewCustomers,
            'period' => $this->period,
            'periodLabel' => match ($this->period) {
                'week' => "This Week's Revenue",
                'month' => "This Month's Revenue",
                'year' => "This Year's Revenue",
                default => "Today's Revenue",
            },
            'periodSales' => $canViewSales ? $this->periodSales($from, $to) : null,
            'lowStockCount' => $canViewInventory ? CurrentStock::where('is_low_stock', 1)->where('qty_on_hand', '>', 0)->count() : null,
            'expiringSoonCount' => $canViewInventory ? $this->expiringSoonCount() : null,
            'inventoryValue' => $canViewInventory ? (float) DB::table('v_inventory_valuation')->sum('value_at_selling_price') : null,
            'outstandingCredit' => $canViewCustomers ? (float) DB::table('v_credit_outstanding_balances')->sum('outstanding_balance') : null,
            'salesTrend' => $canViewSales ? $this->salesTrend($from, $to) : [],
            'topProducts' => $canViewSales ? $this->topProducts($from, $to) : collect(),
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodRange(): array
    {
        $now = now();

        return match ($this->period) {
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    private function periodSales(Carbon $from, Carbon $to): object
    {
        return Sale::whereBetween('sale_date', [$from, $to])
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
    private function salesTrend(Carbon $from, Carbon $to): array
    {
        return match ($this->period) {
            'day' => $this->hourlyTrend($from),
            'year' => $this->monthlyTrend($from),
            default => $this->dailyTrend($from, $to),
        };
    }

    private function hourlyTrend(Carbon $day): array
    {
        $rows = Sale::whereBetween('sale_date', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->where('status', 'completed')
            ->selectRaw('HOUR(sale_date) as hour, SUM(total_amount) as revenue')
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        $hours = [];

        foreach (range(0, 23) as $hour) {
            $hours[] = [
                'day' => sprintf('%02d:00', $hour),
                'label' => sprintf('%02d', $hour),
                'revenue' => (float) ($rows[$hour]->revenue ?? 0),
            ];
        }

        return $hours;
    }

    private function dailyTrend(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('v_daily_sales_summary')
            ->whereBetween('sale_day', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn ($row) => (string) $row->sale_day);

        $days = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $key = $cursor->toDateString();

            $days[] = [
                'day' => $key,
                'label' => $this->period === 'month' ? $cursor->format('j') : $cursor->format('D'),
                'revenue' => (float) ($rows[$key]->total_revenue ?? 0),
            ];

            $cursor->addDay();
        }

        return $days;
    }

    private function monthlyTrend(Carbon $yearStart): array
    {
        $rows = DB::table('v_daily_sales_summary')
            ->whereBetween('sale_day', [$yearStart->copy()->startOfYear()->toDateString(), $yearStart->copy()->endOfYear()->toDateString()])
            ->get()
            ->groupBy(fn ($row) => Carbon::parse($row->sale_day)->format('n'));

        $months = [];

        for ($m = 1; $m <= 12; $m++) {
            $revenue = ($rows[(string) $m] ?? collect())->sum('total_revenue');

            $months[] = [
                'day' => sprintf('%04d-%02d', $yearStart->year, $m),
                'label' => Carbon::create($yearStart->year, $m, 1)->format('M'),
                'revenue' => (float) $revenue,
            ];
        }

        return $months;
    }

    private function topProducts(Carbon $from, Carbon $to): Collection
    {
        return DB::table('sale_line_items as sli')
            ->join('sales as s', 's.id', '=', 'sli.sale_id')
            ->join('products as p', 'p.id', '=', 'sli.product_id')
            ->where('s.status', 'completed')
            ->whereBetween('s.sale_date', [$from, $to])
            ->selectRaw('sli.product_id, p.name as product_name, SUM(sli.subtotal) as total_revenue')
            ->groupBy('sli.product_id', 'p.name')
            ->orderByDesc('total_revenue')
            ->limit(5)
            ->get();
    }
}
