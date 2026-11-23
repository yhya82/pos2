<?php

namespace App\Livewire\Dashboard;

use App\Models\BatchExpiry;
use App\Models\CurrentStock;
use App\Models\GeneralSetting;
use App\Models\InventorySetting;
use App\Models\ModuleSetting;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\StoreSetting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
     * same day/week/month/year presets as the Reports page (SRS Sec.
     * 20.14), so "filter the sales" means one control that scopes all
     * three.
     */
    public string $period = 'day';

    public function setPeriod(string $period): void
    {
        if (! in_array($period, ['day', 'week', 'month', 'year', 'all'], true)) {
            return;
        }

        $this->period = $period;
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
        $periodSales = $canViewRevenue ? $this->periodSales($from, $to) : null;
        $revenueChange = $canViewRevenue ? $this->revenueChange($periodSales->revenue) : ['percent' => null, 'isNew' => false];

        return view('livewire.dashboard.dashboard-overview', [
            'currencyCode' => GeneralSetting::current()->currency_code,
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
                default => "Today's Revenue",
            },
            'periodSales' => $periodSales,
            'revenueChangePercent' => $revenueChange['percent'],
            'revenueChangeIsNew' => $revenueChange['isNew'],
            'revenueChangeLabel' => match ($this->period) {
                'week' => 'vs last week',
                'month' => 'vs last month',
                'year' => 'vs last year',
                'all' => 'vs this time last year',
                default => 'vs yesterday',
            },
            'periodProfit' => $canViewRevenue ? $this->periodProfit($from, $to) : null,
            'lowStockCount' => $canViewInventory ? CurrentStock::where('is_low_stock', 1)->where('qty_on_hand', '>', 0)->count() : null,
            'lowStockItems' => $canViewInventory
                ? CurrentStock::where('is_low_stock', 1)->where('qty_on_hand', '>', 0)->orderBy('qty_on_hand')->limit(3)->get(['product_id', 'product_name', 'qty_on_hand'])
                : collect(),
            'expiringSoonCount' => $canViewInventory ? $this->expiringSoonCount() : null,
            'outOfStockCount' => $canViewInventory ? (int) DB::table('v_out_of_stock')->count() : null,
            'outOfStockItems' => $canViewInventory
                ? DB::table('v_out_of_stock')->orderBy('product_name')->limit(3)->get(['product_id', 'product_name'])
                : collect(),
            'canRestockFromHere' => auth()->user()->hasPermission('products', 'view') && auth()->user()->hasPermission('inventory', 'update'),
            'inventoryValue' => $canViewInventoryValue ? (float) DB::table('v_inventory_valuation')->sum('value_at_selling_price') : null,
            'estimatedGrossProfit' => $canViewInventoryValue ? (float) DB::table('v_inventory_valuation')->sum('estimated_gross_profit') : null,
            'outstandingCredit' => $canViewCustomers ? (float) DB::table('v_credit_outstanding_balances')->sum('outstanding_balance') : null,
            'outstandingCreditCustomers' => $canViewCustomers
                ? DB::table('v_credit_outstanding_balances')->orderByDesc('outstanding_balance')->limit(3)->get(['customer_name', 'outstanding_balance'])
                : collect(),
            'pendingPurchaseOrdersCount' => $canViewPurchaseOrders ? $this->pendingPurchaseOrdersQuery()->count() : null,
            'pendingPurchaseOrders' => $canViewPurchaseOrders ? $this->pendingPurchaseOrders() : collect(),
            'refundsTotal' => $canViewReturns ? $this->refundsTotal($user, $from, $to) : null,
            'salesTrend' => $canViewRevenue ? $this->salesTrend($from, $to) : [],
            // Which bar in salesTrend is "right now" — same key format each
            // trend method already uses for its own entries, so a plain
            // string match against the array is all the view needs to
            // highlight it, no re-deriving the format there too.
            'currentTrendKey' => match ($this->period) {
                'day' => now()->format('H:00'),
                'month', 'all' => now()->format('Y-m'),
                'year' => now()->format('Y'),
                default => now()->toDateString(),
            },
            'topProducts' => $canViewRevenue ? $this->topProducts($from, $to) : collect(),
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
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon} 'all' has no comparable "previous
     *                                     all-time" total, so it compares
     *                                     year-to-date against the same
     *                                     stretch last year instead — handled
     *                                     separately in revenueChange(),
     *                                     this covers every other period.
     */
    private function previousPeriodRange(): array
    {
        $now = now();

        return match ($this->period) {
            'week' => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            'month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'year' => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()],
            default => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
        };
    }

    /**
     * How the current period's revenue compares to the equivalent prior
     * one (today vs yesterday, this month vs last month, …). 'all' has no
     * real "previous all-time" to compare its own lifetime total against,
     * so it compares year-to-date against the same stretch last year
     * instead — a real, like-for-like comparison rather than a fabricated
     * one. 'percent' is null (not a made-up number) when the prior period
     * made no sales at all; 'isNew' then says whether this period itself
     * made sales anyway, so the view can still say something true (a
     * neutral "first sales" note) instead of showing nothing.
     *
     * @return array{percent: ?float, isNew: bool}
     */
    private function revenueChange(float $currentRevenue): array
    {
        if ($this->period === 'all') {
            $currentRevenue = (float) Sale::whereBetween('sale_date', [now()->startOfYear(), now()])
                ->where('status', 'completed')->sum('total_amount');
            $previousRevenue = (float) Sale::whereBetween('sale_date', [now()->copy()->subYear()->startOfYear(), now()->copy()->subYear()])
                ->where('status', 'completed')->sum('total_amount');
        } else {
            $previousRevenue = (float) Sale::whereBetween('sale_date', $this->previousPeriodRange())
                ->where('status', 'completed')->sum('total_amount');
        }

        if ($previousRevenue > 0) {
            return ['percent' => round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 1), 'isNew' => false];
        }

        return ['percent' => null, 'isNew' => $currentRevenue > 0];
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
            'month' => $this->monthsOfYearTrend($from),
            'year' => $this->yearsTrend(),
            'all' => $this->allTimeMonthlyTrend($from, $to),
            default => $this->dailyTrend($from, $to),
        };
    }

    /**
     * Bounded to the store's own configured opening/closing time
     * (StoreSetting), not a bare 0–23 — otherwise every overnight hour the
     * store is closed shows up as a dead, empty bar cluttering the chart.
     * Falls back to the full day when the store hasn't configured hours.
     */
    private function hourlyTrend(Carbon $day): array
    {
        $store = StoreSetting::current();
        $openHour = $store?->opening_time ? Carbon::parse($store->opening_time)->hour : 0;
        $closeHour = $store?->closing_time ? Carbon::parse($store->closing_time)->hour : 23;

        $rows = Sale::whereBetween('sale_date', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->where('status', 'completed')
            ->selectRaw('HOUR(sale_date) as hour, SUM(total_amount) as revenue')
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        $hours = [];

        foreach (range($openHour, max($openHour, $closeHour)) as $hour) {
            $hours[] = [
                'day' => sprintf('%02d:00', $hour),
                'label' => sprintf('%02d', $hour),
                'revenue' => (float) ($rows[$hour]->revenue ?? 0),
            ];
        }

        return $hours;
    }

    /** Days of the selected week — the only period that still reaches this. */
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
                'label' => $cursor->format('D'),
                'revenue' => (float) ($rows[$key]->total_revenue ?? 0),
            ];

            $cursor->addDay();
        }

        return $days;
    }

    /**
     * Jan–Dec of the selected year — the chart's own context for "Month",
     * distinct from the headline figure above it, which stays just that
     * one month's own total.
     */
    private function monthsOfYearTrend(Carbon $yearStart): array
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
     * One bar per calendar year, from the earliest sale on record through
     * this year — "Year"'s own context, same relationship to its headline
     * figure as "Month" has to its own (the headline stays just this
     * year's total; the chart gives the longer view around it).
     */
    private function yearsTrend(): array
    {
        $earliest = Sale::min('sale_date');
        $startYear = $earliest ? Carbon::parse($earliest)->year : now()->year;
        $endYear = now()->year;

        $rows = DB::table('v_daily_sales_summary')
            ->whereBetween('sale_day', [Carbon::create($startYear, 1, 1)->toDateString(), now()->endOfYear()->toDateString()])
            ->get()
            ->groupBy(fn ($row) => Carbon::parse($row->sale_day)->format('Y'));

        $years = [];

        for ($y = $startYear; $y <= $endYear; $y++) {
            $revenue = ($rows[(string) $y] ?? collect())->sum('total_revenue');

            $years[] = [
                'day' => (string) $y,
                'label' => (string) $y,
                'revenue' => (float) $revenue,
            ];
        }

        return $years;
    }

    /**
     * Monthly buckets spanning the full history, unlike monthsOfYearTrend()
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
            ->selectRaw('sli.product_id, p.name as product_name, p.image_path, SUM(sli.quantity) as qty_sold, SUM(sli.subtotal) as total_revenue')
            ->groupBy('sli.product_id', 'p.name', 'p.image_path')
            ->orderByDesc('qty_sold')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $row->image_url = $row->image_path ? Storage::disk('public')->url($row->image_path) : null;

                return $row;
            });
    }

    /**
     * Draft/ordered/partially-received — the same statuses the Pending
     * Purchase Orders count has always meant, just as a real list now too.
     */
    private function pendingPurchaseOrdersQuery(): Builder
    {
        return PurchaseOrder::whereIn('status', ['draft', 'ordered', 'partially_received']);
    }

    private function pendingPurchaseOrders(): Collection
    {
        // Same "order total" calculation already used on the Purchase
        // Orders page itself (purchase-order-manager.blade.php) — ordered
        // quantity × cost price per line, nothing new invented here.
        return $this->pendingPurchaseOrdersQuery()
            ->with(['supplier:id,name', 'lineItems:id,purchase_order_id,qty_ordered,cost_price'])
            ->orderByDesc('order_date')
            ->limit(3)
            ->get()
            ->map(fn (PurchaseOrder $po) => (object) [
                'po_number' => $po->po_number,
                'supplier_name' => $po->supplier->name,
                'status' => $po->status,
                'total' => $po->lineItems->sum(fn ($l) => (float) $l->qty_ordered * (float) $l->cost_price),
            ]);
    }
}
