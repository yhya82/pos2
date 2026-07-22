<?php

namespace App\Livewire\Reports;

use App\Models\ModuleSetting;
use Illuminate\Support\Facades\DB;
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

    public string $sortBy = 'total_revenue';

    public string $sortDirection = 'desc';

    public function mount(): void
    {
        $this->dateFrom = now()->subDays(29)->toDateString();
        $this->dateTo = now()->toDateString();
    }

    public function selectReport(string $key): void
    {
        if (! isset($this->availableReports()[$key])) {
            return;
        }

        $this->selectedReport = $key;
        $this->resetPage();
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
