<?php

namespace App\Livewire\Dashboard;

use App\Models\BatchExpiry;
use App\Models\CurrentStock;
use App\Models\InventorySetting;
use App\Models\ModuleSetting;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
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
     * same day/week/month/year/custom presets as the Reports page (SRS
     * Sec. 20.14), so "filter the sales" means one control that scopes all
     * three.
     */
    public string $period = 'day';

    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        $this->dateFrom = now()->toDateString();
        $this->dateTo = now()->toDateString();
    }

    public function setPeriod(string $period): void
    {
        if (! in_array($period, ['day', 'week', 'month', 'year', 'all', 'custom'], true)) {
            return;
        }

        $this->period = $period;

        if ($period !== 'custom') {
            [$from, $to] = $this->periodRange();
            $this->dateFrom = $from->toDateString();
            $this->dateTo = $to->toDateString();
        }
    }

    public function updatedDateFrom(): void
    {
        $this->period = 'custom';
    }

    public function updatedDateTo(): void
    {
        $this->period = 'custom';
    }

    /**
     * Real-time delivery for every card on this page: a completed sale, any
     * stock movement, or a credit payment all re-trigger render(), which
     * already recomputes every stat fresh from the DB on each pass — same
     * no-op-listener pattern as NotificationBell.
     */
    #[On('echo-private:dashboard,.SaleCompleted')]
    #[On('echo-private:dashboard,.CreditBalanceChanged')]
    #[On('echo-private:stock,.StockChanged')]
    #[On('echo-private:settings,.ModuleSettingChanged')]
    public function onLiveDataChanged(): void
    {
        // No-op — render() below re-queries everything fresh.
    }

    public function render()
    {
        $user = auth()->user();
        $canViewInventory = $user->hasPermission('inventory', 'view');
        $canViewCustomers = $user->hasPermission('customers', 'view') && ModuleSetting::enabled('customer_credit');

        // Store-wide revenue, the trend chart, and Top Products are a
        // manager-level view of the business — a Cashier can still process
        // sales (sales,create) and see their own history, but not this.
        $canViewRevenue = $user->hasPermission('sales', 'view') && $user->canSeeFinancials();

        // Same reasoning as revenue — the total money value of stock on
        // hand is a financial figure, not an operational one. Low Stock and
        // Batches Expiring Soon stay visible to a Cashier; this doesn't.
        $canViewInventoryValue = $canViewInventory && $user->canSeeFinancials();

        $canViewReturns = ModuleSetting::enabled('return_management') && $user->hasPermission('returns', 'view');
        $canViewPurchaseOrders = ModuleSetting::enabled('purchase_management') && $user->hasPermission('purchase_orders', 'view');

        [$from, $to] = $this->periodRange();

        return view('livewire.dashboard.dashboard-overview', [
            'canViewRevenue' => $canViewRevenue,
            'canViewInventory' => $canViewInventory,
            'canViewInventoryValue' => $canViewInventoryValue,
            'canViewCustomers' => $canViewCustomers,
            'canViewReturns' => $canViewReturns,
            'canViewPurchaseOrders' => $canViewPurchaseOrders,
            'period' => $this->period,
            'periodLabel' => match ($this->period) {
                'week' => "This Week's Revenue",
                'month' => "This Month's Revenue",
                'year' => "This Year's Revenue",
                'all' => 'All-Time Revenue',
                'custom' => 'Revenue (Selected Range)',
                default => "Today's Revenue",
            },
            'periodSales' => $canViewRevenue ? $this->periodSales($from, $to) : null,
            'periodProfit' => $canViewRevenue ? $this->periodProfit($from, $to) : null,
            'lowStockCount' => $canViewInventory ? CurrentStock::where('is_low_stock', 1)->where('qty_on_hand', '>', 0)->count() : null,
            'lowStockNames' => $canViewInventory
                ? CurrentStock::where('is_low_stock', 1)->where('qty_on_hand', '>', 0)->orderBy('qty_on_hand')->limit(3)->pluck('product_name')
                : collect(),
            'expiringSoonCount' => $canViewInventory ? $this->expiringSoonCount() : null,
            'outOfStockCount' => $canViewInventory ? (int) DB::table('v_out_of_stock')->count() : null,
            'outOfStockNames' => $canViewInventory
                ? DB::table('v_out_of_stock')->orderBy('product_name')->limit(3)->pluck('product_name')
                : collect(),
            'inventoryValue' => $canViewInventoryValue ? (float) DB::table('v_inventory_valuation')->sum('value_at_selling_price') : null,
            'estimatedGrossProfit' => $canViewInventoryValue ? (float) DB::table('v_inventory_valuation')->sum('estimated_gross_profit') : null,
            'outstandingCredit' => $canViewCustomers ? (float) DB::table('v_credit_outstanding_balances')->sum('outstanding_balance') : null,
            'outstandingCreditNames' => $canViewCustomers
                ? DB::table('v_credit_outstanding_balances')->orderByDesc('outstanding_balance')->limit(3)->pluck('customer_name')
                : collect(),
            'pendingPurchaseOrders' => $canViewPurchaseOrders ? PurchaseOrder::whereIn('status', ['draft', 'ordered', 'partially_received'])->count() : null,
            'refundsTotal' => $canViewReturns ? $this->refundsTotal($user, $from, $to) : null,
            'salesTrend' => $canViewRevenue ? $this->salesTrend($from, $to) : [],
            'topProducts' => $canViewRevenue ? $this->topProducts($from, $to) : collect(),
            'paymentMethodBreakdown' => $canViewRevenue ? $this->paymentMethodBreakdown($from, $to) : collect(),
            'cashierLeaderboard' => $canViewRevenue ? $this->cashierLeaderboard($from, $to) : collect(),
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
            'all' => [
                ($earliest = Sale::min('sale_date')) ? Carbon::parse($earliest)->startOfDay() : $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'custom' => [
                Carbon::parse($this->dateFrom ?: now()->toDateString())->startOfDay(),
                Carbon::parse($this->dateTo ?: now()->toDateString())->endOfDay(),
            ],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    /** Store-wide refunds — but a cashier only ever sees refunds on their own sales. */
    private function refundsTotal($user, Carbon $from, Carbon $to): float
    {
        $query = DB::table('sales_returns as sr')
            ->whereBetween('sr.created_at', [$from, $to]);

        if ($user->isCashier()) {
            $query->join('sales as s', 's.id', '=', 'sr.original_sale_id')->where('s.cashier_id', $user->id);
        }

        return (float) $query->sum('sr.refund_amount');
    }

    private function periodSales(Carbon $from, Carbon $to): object
    {
        return Sale::whereBetween('sale_date', [$from, $to])
            ->where('status', 'completed')
            ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(total_amount), 0) as revenue')
            ->first();
    }

    /**
     * Realized profit on sales made in the period (v_realized_profit_lines):
     * net of discounts and refunds, costed from the batches actually sold.
     * Distinct from the "Estimated Gross Profit" tile, which is a forecast
     * for stock still on hand.
     */
    private function periodProfit(Carbon $from, Carbon $to): float
    {
        return round((float) DB::table('v_realized_profit_lines')
            ->whereBetween('sale_date', [$from, $to])
            ->sum('profit'), 2);
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
            'all' => $this->allTimeMonthlyTrend($from, $to),
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
                'label' => in_array($this->period, ['month', 'custom'], true) ? $cursor->format('j') : $cursor->format('D'),
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

    /**
     * Monthly buckets spanning the full history, unlike monthlyTrend()
     * (locked to one calendar year) — a day-by-day bar chart across
     * potentially years of data would be both unreadable and slow to
     * generate, so "All" always renders as one bar per calendar month.
     */
    private function allTimeMonthlyTrend(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('v_daily_sales_summary')
            ->whereBetween('sale_day', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy(fn ($row) => Carbon::parse($row->sale_day)->format('Y-m'));

        $months = [];
        $cursor = $from->copy()->startOfMonth();
        $end = $to->copy()->startOfMonth();

        while ($cursor->lte($end)) {
            $key = $cursor->format('Y-m');
            $revenue = ($rows[$key] ?? collect())->sum('total_revenue');

            $months[] = [
                'day' => $key,
                'label' => $cursor->format('M Y'),
                'revenue' => (float) $revenue,
            ];

            $cursor->addMonth();
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

    private function paymentMethodBreakdown(Carbon $from, Carbon $to): Collection
    {
        return DB::table('v_sales_by_payment_method')
            ->whereBetween('sale_day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('payment_method_name, SUM(total_amount) as total')
            ->groupBy('payment_method_name')
            ->orderByDesc('total')
            ->get();
    }

    private function cashierLeaderboard(Carbon $from, Carbon $to): Collection
    {
        return DB::table('v_sales_by_cashier')
            ->whereBetween('sale_day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('cashier_name, SUM(total_revenue) as total_revenue, SUM(transaction_count) as transaction_count')
            ->groupBy('cashier_name')
            ->orderByDesc('total_revenue')
            ->limit(5)
            ->get();
    }
}
