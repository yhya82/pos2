<?php

namespace App\Livewire\Reports;

use App\Models\ModuleSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * SRS Sec. 20.14 / 13: a report picker over the reporting views already
 * built into the schema (Part E, Section 14) — this component is a thin
 * query wrapper per report, no new business logic. Which reports are
 * visible depends on the viewer's permissions and enabled modules, per
 * Sec. 20.14's own wording.
 */
class ReportViewer extends Component
{
    use WithPagination;

    public string $selectedReport = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $period = 'custom';

    public string $sortBy = 'total_revenue';

    public string $sortDirection = 'desc';

    public function mount(): void
    {
        $this->dateFrom = now()->subDays(29)->toDateString();
        $this->dateTo = now()->toDateString();
    }

    #[On('echo-private:settings,.ModuleSettingChanged')]
    public function onModuleSettingChanged(): void
    {
        // No-op — render() below re-checks ModuleSetting::enabled() fresh.
    }

    public function selectReport(string $key): void
    {
        if (! isset($this->availableReports()[$key])) {
            return;
        }

        $this->selectedReport = $key;
        $this->resetPage();
    }

    /**
     * Quick period presets (SRS Sec. 20.14) sitting alongside the free-form
     * date range — 'day' is today only, the rest are the current calendar
     * week/month/year rather than a trailing N-day window, since that's
     * what "filter by week/month/year" means for a sales report.
     */
    public function setPeriod(string $period): void
    {
        if ($period === 'custom') {
            $this->period = 'custom';
            $this->resetPage();

            return;
        }

        if ($period === 'all') {
            $this->period = 'all';
            $this->dateFrom = '';
            $this->dateTo = '';
            $this->resetPage();

            return;
        }

        $now = now();

        [$from, $to] = match ($period) {
            'day' => [$now->copy(), $now->copy()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => [null, null],
        };

        if (! $from) {
            return;
        }

        $this->period = $period;
        $this->dateFrom = $from->toDateString();
        $this->dateTo = $to->toDateString();
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->period = 'custom';
    }

    public function updatedDateTo(): void
    {
        $this->period = 'custom';
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'desc';
        }
    }

    public function render()
    {
        $reports = $this->availableReports();
        $grouped = collect($reports)->groupBy('group');

        return view('livewire.reports.report-viewer', [
            'groupedReports' => $grouped,
            'currentReport' => $this->selectedReport ? $reports[$this->selectedReport] : null,
            'rows' => $this->selectedReport ? $this->queryFor($this->selectedReport) : null,
        ]);
    }

    /**
     * @return array<string, array{label: string, group: string, dateColumn?: string, sortable?: bool, columns: array<int, array{key: string, label: string, align?: string, money?: bool}>}>
     */
    private function availableReports(): array
    {
        $user = auth()->user();
        $creditEnabled = ModuleSetting::enabled('customer_credit');
        $returnsEnabled = ModuleSetting::enabled('return_management');
        $purchasingEnabled = ModuleSetting::enabled('purchase_management') && $user->hasPermission('purchase_orders', 'view');

        $all = [
            'daily_sales' => [
                'label' => 'Daily Sales Summary', 'group' => 'Sales', 'visible' => $user->hasPermission('sales', 'view'),
                'dateColumn' => 'sale_day',
                'columns' => [
                    ['key' => 'sale_day', 'label' => 'Date'],
                    ['key' => 'transaction_count', 'label' => 'Transactions', 'align' => 'right'],
                    ['key' => 'gross_subtotal', 'label' => 'Subtotal', 'align' => 'right', 'money' => true],
                    ['key' => 'total_discounts', 'label' => 'Discounts', 'align' => 'right', 'money' => true],
                    ['key' => 'total_revenue', 'label' => 'Revenue', 'align' => 'right', 'money' => true],
                ],
            ],
            'sales_by_cashier' => [
                'label' => 'Sales by Cashier', 'group' => 'Sales', 'visible' => $user->hasPermission('sales', 'view'),
                'dateColumn' => 'sale_day',
                'columns' => [
                    ['key' => 'cashier_name', 'label' => 'Cashier'],
                    ['key' => 'sale_day', 'label' => 'Date'],
                    ['key' => 'transaction_count', 'label' => 'Transactions', 'align' => 'right'],
                    ['key' => 'total_revenue', 'label' => 'Revenue', 'align' => 'right', 'money' => true],
                ],
            ],
            'sales_by_payment_method' => [
                'label' => 'Sales by Payment Method', 'group' => 'Sales', 'visible' => $user->hasPermission('sales', 'view'),
                'dateColumn' => 'sale_day',
                'columns' => [
                    ['key' => 'payment_method_name', 'label' => 'Payment Method'],
                    ['key' => 'sale_day', 'label' => 'Date'],
                    ['key' => 'transaction_count', 'label' => 'Transactions', 'align' => 'right'],
                    ['key' => 'total_amount', 'label' => 'Amount', 'align' => 'right', 'money' => true],
                ],
            ],
            // Same audience as the dashboard's revenue/profit tiles — a
            // cashier can process sales but doesn't see store-wide profit.
            'realized_profit' => [
                'label' => 'Profit Report', 'group' => 'Sales', 'visible' => $user->hasPermission('sales', 'view') && $user->canSeeFinancials(),
                'columns' => [
                    ['key' => 'product_name', 'label' => 'Product'],
                    ['key' => 'quantity_sold', 'label' => 'Qty Sold', 'align' => 'right'],
                    ['key' => 'net_revenue', 'label' => 'Revenue (net)', 'align' => 'right', 'money' => true],
                    ['key' => 'net_cost', 'label' => 'Cost', 'align' => 'right', 'money' => true],
                    ['key' => 'profit', 'label' => 'Profit', 'align' => 'right', 'money' => true],
                    ['key' => 'margin_pct', 'label' => 'Margin %', 'align' => 'right'],
                ],
            ],
            'discounts' => [
                'label' => 'Discount Report', 'group' => 'Sales', 'visible' => $user->hasPermission('sales', 'view'),
                'dateColumn' => 'sale_date',
                'columns' => [
                    ['key' => 'receipt_number', 'label' => 'Receipt #'],
                    ['key' => 'sale_date', 'label' => 'Date'],
                    ['key' => 'header_discount_type', 'label' => 'Type'],
                    ['key' => 'header_discount_amount', 'label' => 'Header Discount', 'align' => 'right', 'money' => true],
                    ['key' => 'total_line_discounts', 'label' => 'Line Discounts', 'align' => 'right', 'money' => true],
                ],
            ],
            'refunds' => [
                'label' => 'Refund Report', 'group' => 'Sales', 'visible' => $returnsEnabled && $user->hasPermission('returns', 'view'),
                'dateColumn' => 'created_at',
                'columns' => [
                    ['key' => 'return_number', 'label' => 'Return #'],
                    ['key' => 'original_receipt_number', 'label' => 'Original Sale'],
                    ['key' => 'created_at', 'label' => 'Date'],
                    ['key' => 'processed_by', 'label' => 'Processed By'],
                    ['key' => 'refund_amount', 'label' => 'Refund', 'align' => 'right', 'money' => true],
                ],
            ],
            'low_stock' => [
                'label' => 'Low Stock', 'group' => 'Inventory', 'visible' => $user->hasPermission('inventory', 'view'),
                'columns' => [
                    ['key' => 'product_name', 'label' => 'Product'],
                    ['key' => 'qty_on_hand', 'label' => 'Qty On Hand', 'align' => 'right'],
                    ['key' => 'min_stock_level', 'label' => 'Min Level', 'align' => 'right'],
                ],
            ],
            'out_of_stock' => [
                'label' => 'Out of Stock', 'group' => 'Inventory', 'visible' => $user->hasPermission('inventory', 'view'),
                'columns' => [
                    ['key' => 'product_name', 'label' => 'Product'],
                    ['key' => 'qty_on_hand', 'label' => 'Qty On Hand', 'align' => 'right'],
                ],
            ],
            'inventory_valuation' => [
                'label' => 'Inventory Valuation', 'group' => 'Inventory', 'visible' => $user->hasPermission('inventory', 'view'),
                'columns' => [
                    ['key' => 'product_name', 'label' => 'Product'],
                    ['key' => 'qty_on_hand', 'label' => 'Qty', 'align' => 'right'],
                    ['key' => 'value_at_cost', 'label' => 'Value (Cost)', 'align' => 'right', 'money' => true],
                    ['key' => 'value_at_selling_price', 'label' => 'Value (Selling)', 'align' => 'right', 'money' => true],
                    ['key' => 'estimated_gross_profit', 'label' => 'Est. Profit', 'align' => 'right', 'money' => true],
                ],
            ],
            'product_performance' => [
                'label' => 'Product Performance', 'group' => 'Inventory', 'visible' => $user->hasPermission('products', 'view'),
                'sortable' => true,
                'columns' => [
                    ['key' => 'product_name', 'label' => 'Product'],
                    ['key' => 'total_qty_sold', 'label' => 'Qty Sold', 'align' => 'right', 'sort' => true],
                    ['key' => 'total_revenue', 'label' => 'Revenue', 'align' => 'right', 'money' => true, 'sort' => true],
                    ['key' => 'transaction_count', 'label' => 'Transactions', 'align' => 'right'],
                    ['key' => 'last_sold_at', 'label' => 'Last Sold'],
                ],
            ],
            // Goods that arrived damaged or never arrived — the loss at cost,
            // and what each supplier still owes for it.
            'receiving_losses' => [
                'label' => 'Receiving Losses', 'group' => 'Inventory', 'visible' => $purchasingEnabled,
                'dateColumn' => 'created_at',
                'columns' => [
                    ['key' => 'created_at', 'label' => 'Date'],
                    ['key' => 'po_number', 'label' => 'PO #'],
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'product_name', 'label' => 'Product'],
                    ['key' => 'issue_type', 'label' => 'Issue'],
                    ['key' => 'qty_label', 'label' => 'Qty', 'align' => 'right'],
                    ['key' => 'loss_value', 'label' => 'Loss (cost)', 'align' => 'right', 'money' => true],
                    ['key' => 'claim_status', 'label' => 'Claim'],
                    ['key' => 'credited_amount', 'label' => 'Credited', 'align' => 'right', 'money' => true],
                ],
            ],
            'supplier_claims' => [
                'label' => 'Supplier Claims', 'group' => 'Financial', 'visible' => $purchasingEnabled,
                'dateColumn' => 'created_at',
                'columns' => [
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'open_claims', 'label' => 'Open Claims', 'align' => 'right'],
                    ['key' => 'owed', 'label' => 'Still Owed', 'align' => 'right', 'money' => true],
                    ['key' => 'credited', 'label' => 'Credited', 'align' => 'right', 'money' => true],
                    ['key' => 'waived', 'label' => 'Waived', 'align' => 'right', 'money' => true],
                    ['key' => 'total_claimed', 'label' => 'Total Claimed', 'align' => 'right', 'money' => true],
                ],
            ],
            'credit_balances' => [
                'label' => 'Outstanding Customer Balances', 'group' => 'Financial', 'visible' => $creditEnabled && $user->hasPermission('customers', 'view'),
                'columns' => [
                    ['key' => 'customer_name', 'label' => 'Customer'],
                    ['key' => 'credit_limit', 'label' => 'Credit Limit', 'align' => 'right', 'money' => true],
                    ['key' => 'outstanding_balance', 'label' => 'Outstanding', 'align' => 'right', 'money' => true],
                    ['key' => 'available_credit', 'label' => 'Available', 'align' => 'right', 'money' => true],
                ],
            ],
        ];

        // groupBy() (used in render()) re-indexes each group's items
        // numerically, discarding these array keys — stamping the key onto
        // each item first is what lets the picker still call
        // selectReport('daily_sales') instead of selectReport('0').
        foreach ($all as $key => &$definition) {
            $definition['reportKey'] = $key;
        }

        return collect($all)->filter(fn ($r) => $r['visible'])->all();
    }

    private function queryFor(string $key)
    {
        $report = $this->availableReports()[$key];

        // Row-per-sold-line view, rolled up per product for the chosen date
        // range — every other report reads its view as-is.
        if ($key === 'realized_profit') {
            return DB::table('v_realized_profit_lines')
                ->when($this->dateFrom && $this->dateTo, fn ($q) => $q->whereBetween('sale_date', [$this->dateFrom, $this->dateTo.' 23:59:59']))
                ->selectRaw('product_id, product_name,
                    SUM(quantity_sold) AS quantity_sold,
                    ROUND(SUM(net_revenue), 2) AS net_revenue,
                    ROUND(SUM(net_cost), 2) AS net_cost,
                    ROUND(SUM(profit), 2) AS profit,
                    CASE WHEN SUM(net_revenue) > 0 THEN ROUND(SUM(profit) / SUM(net_revenue) * 100, 1) ELSE NULL END AS margin_pct')
                ->groupBy('product_id', 'product_name')
                ->orderByDesc('profit')
                ->paginate(15);
        }

        if ($key === 'receiving_losses') {
            return DB::table('receiving_issues as ri')
                ->join('purchase_orders as po', 'po.id', '=', 'ri.purchase_order_id')
                ->join('suppliers as s', 's.id', '=', 'ri.supplier_id')
                ->join('products as p', 'p.id', '=', 'ri.product_id')
                ->join('units as su', 'su.id', '=', 'p.selling_unit_id')
                ->when($this->dateFrom && $this->dateTo, fn ($q) => $q->whereBetween('ri.created_at', [$this->dateFrom, $this->dateTo.' 23:59:59']))
                ->selectRaw("ri.created_at, po.po_number, s.name AS supplier_name, p.name AS product_name, ri.issue_type, CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM ri.qty)), ' ', su.name) AS qty_label, ri.loss_value, ri.claim_status, ri.credited_amount")
                ->orderByDesc('ri.created_at')
                ->orderByDesc('ri.id')
                ->paginate(15);
        }

        // One row per supplier: what they still owe, and what's been settled.
        if ($key === 'supplier_claims') {
            return DB::table('receiving_issues as ri')
                ->join('suppliers as s', 's.id', '=', 'ri.supplier_id')
                ->when($this->dateFrom && $this->dateTo, fn ($q) => $q->whereBetween('ri.created_at', [$this->dateFrom, $this->dateTo.' 23:59:59']))
                ->selectRaw("s.name AS supplier_name,
                    SUM(ri.claim_status = 'owed') AS open_claims,
                    ROUND(SUM(CASE WHEN ri.claim_status = 'owed' THEN ri.loss_value ELSE 0 END), 2) AS owed,
                    ROUND(SUM(ri.credited_amount), 2) AS credited,
                    ROUND(SUM(CASE WHEN ri.claim_status = 'waived' THEN ri.loss_value ELSE 0 END), 2) AS waived,
                    ROUND(SUM(ri.loss_value), 2) AS total_claimed")
                ->groupBy('ri.supplier_id', 's.name')
                ->orderByDesc('owed')
                ->orderBy('s.name')
                ->paginate(15);
        }

        $view = match ($key) {
            'daily_sales' => 'v_daily_sales_summary',
            'sales_by_cashier' => 'v_sales_by_cashier',
            'sales_by_payment_method' => 'v_sales_by_payment_method',
            'discounts' => 'v_discount_report',
            'refunds' => 'v_refund_report',
            'low_stock' => 'v_low_stock',
            'out_of_stock' => 'v_out_of_stock',
            'inventory_valuation' => 'v_inventory_valuation',
            'product_performance' => 'v_product_sales_summary',
            'credit_balances' => 'v_credit_outstanding_balances',
        };

        $query = DB::table($view);

        if (isset($report['dateColumn']) && $this->dateFrom && $this->dateTo) {
            $query->whereBetween($report['dateColumn'], [$this->dateFrom, $this->dateTo.' 23:59:59']);
        }

        if ($key === 'product_performance') {
            $query->orderBy($this->sortBy, $this->sortDirection);
        } elseif (isset($report['dateColumn'])) {
            $query->orderByDesc($report['dateColumn']);
        }

        return $query->paginate(15);
    }
}
