<div class="space-y-6">
    @if ($canViewRevenue)
        <div class="flex flex-wrap items-center justify-end gap-3">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Sales period:</span>
            <div class="inline-flex rounded-md shadow-sm" role="group">
                @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year', 'all' => 'All', 'custom' => 'Custom'] as $value => $label)
                    <button
                        type="button"
                        wire:click="setPeriod('{{ $value }}')"
                        @class([
                            'px-3 py-1.5 text-xs font-medium border first:rounded-l-md last:rounded-r-md -ml-px first:ml-0',
                            'bg-indigo-600 text-white border-indigo-600 z-10' => $period === $value,
                            'bg-white text-gray-600 border-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:border-gray-700 dark:hover:bg-gray-800' => $period !== $value,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>

            @if ($period === 'custom')
                <div class="flex items-center gap-2">
                    <input type="date" wire:model.live="dateFrom" class="text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <span class="text-gray-400 text-sm">to</span>
                    <input type="date" wire:model.live="dateTo" class="text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                </div>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
        @if ($canViewRevenue)
            <x-dashboard-stat-card
                icon="banknotes"
                icon-class="bg-emerald-50 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400"
                accent="bg-emerald-500"
                :label="$periodLabel"
            >
                {{ number_format($periodSales->revenue, 2) }}
                <x-slot name="footer">{{ $periodSales->transaction_count }} transaction(s)</x-slot>
            </x-dashboard-stat-card>
        @endif

        @if ($canViewInventory)
            <x-dashboard-stat-card
                icon="exclamation-triangle"
                icon-class="bg-amber-50 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400"
                accent="bg-amber-500"
                label="Low Stock Products"
                :value-class="$lowStockCount > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-gray-100'"
            >
                {{ $lowStockCount }}
                @if ($lowStockNames->isNotEmpty())
                    <x-slot name="details">
                        @foreach ($lowStockNames as $name)
                            <div class="truncate">{{ $name }}</div>
                        @endforeach
                    </x-slot>
                @endif
                <x-slot name="footer">
                    <a href="{{ route('inventory.index', ['tab' => 'stock', 'low_stock' => 1]) }}" wire:navigate class="inline-flex items-center gap-1 font-medium text-gray-500 hover:text-indigo-600 dark:text-gray-400 dark:hover:text-indigo-400">
                        View low-stock items <span aria-hidden="true">&rarr;</span>
                    </a>
                </x-slot>
            </x-dashboard-stat-card>

            <x-dashboard-stat-card
                icon="clock"
                icon-class="bg-red-50 dark:bg-red-900/40 text-red-600 dark:text-red-400"
                accent="bg-red-500"
                label="Batches Expiring Soon"
                :value-class="$expiringSoonCount > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100'"
            >
                {{ $expiringSoonCount }}
                <x-slot name="footer">
                    <a href="{{ route('inventory.index', ['tab' => 'expiry']) }}" wire:navigate class="inline-flex items-center gap-1 font-medium text-gray-500 hover:text-indigo-600 dark:text-gray-400 dark:hover:text-indigo-400">
                        View expiry tracking <span aria-hidden="true">&rarr;</span>
                    </a>
                </x-slot>
            </x-dashboard-stat-card>

            <x-dashboard-stat-card
                icon="cube"
                icon-class="bg-red-50 dark:bg-red-900/40 text-red-600 dark:text-red-400"
                accent="bg-red-500"
                label="Out of Stock"
                :value-class="$outOfStockCount > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100'"
            >
                {{ $outOfStockCount }}
                @if ($outOfStockNames->isNotEmpty())
                    <x-slot name="details">
                        @foreach ($outOfStockNames as $name)
                            <div class="truncate">{{ $name }}</div>
                        @endforeach
                    </x-slot>
                @endif
                <x-slot name="footer">
                    <a href="{{ route('inventory.index', ['tab' => 'stock']) }}" wire:navigate class="inline-flex items-center gap-1 font-medium text-gray-500 hover:text-indigo-600 dark:text-gray-400 dark:hover:text-indigo-400">
                        View stock <span aria-hidden="true">&rarr;</span>
                    </a>
                </x-slot>
            </x-dashboard-stat-card>
        @endif

        @if ($canViewInventoryValue)
            <x-dashboard-stat-card
                icon="archive-box"
                icon-class="bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400"
                accent="bg-indigo-500"
                label="Inventory Value"
            >
                {{ number_format($inventoryValue, 2) }}
                <x-slot name="footer">at selling price</x-slot>
            </x-dashboard-stat-card>

            <x-dashboard-stat-card
                icon="chart-bar"
                icon-class="bg-emerald-50 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400"
                accent="bg-emerald-500"
                label="Estimated Gross Profit"
            >
                {{ number_format($estimatedGrossProfit, 2) }}
                <x-slot name="footer">current stock, at selling price</x-slot>
            </x-dashboard-stat-card>
        @endif

        @if ($canViewPurchaseOrders)
            <x-dashboard-stat-card
                icon="clipboard-check"
                icon-class="bg-amber-50 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400"
                accent="bg-amber-500"
                label="Pending Purchase Orders"
            >
                {{ $pendingPurchaseOrders }}
                <x-slot name="footer">
                    <a href="{{ route('purchase-orders.index') }}" wire:navigate class="inline-flex items-center gap-1 font-medium text-gray-500 hover:text-indigo-600 dark:text-gray-400 dark:hover:text-indigo-400">
                        View purchase orders <span aria-hidden="true">&rarr;</span>
                    </a>
                </x-slot>
            </x-dashboard-stat-card>
        @endif

        @if ($canViewReturns)
            <x-dashboard-stat-card
                icon="arrow-uturn-left"
                icon-class="bg-red-50 dark:bg-red-900/40 text-red-600 dark:text-red-400"
                accent="bg-red-500"
                label="Refunds"
            >
                {{ number_format($refundsTotal, 2) }}
                <x-slot name="footer">this period</x-slot>
            </x-dashboard-stat-card>
        @endif

        @if ($canViewCustomers)
            <x-dashboard-stat-card
                icon="credit-card"
                icon-class="bg-blue-50 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400"
                accent="bg-blue-500"
                label="Outstanding Credit"
            >
                {{ number_format($outstandingCredit, 2) }}
                @if ($outstandingCreditNames->isNotEmpty())
                    <x-slot name="details">
                        @foreach ($outstandingCreditNames as $name)
                            <div class="truncate">{{ $name }}</div>
                        @endforeach
                    </x-slot>
                @endif
                <x-slot name="footer">
                    <a href="{{ route('customers.index') }}" wire:navigate class="inline-flex items-center gap-1 font-medium text-gray-500 hover:text-indigo-600 dark:text-gray-400 dark:hover:text-indigo-400">
                        View customers <span aria-hidden="true">&rarr;</span>
                    </a>
                </x-slot>
            </x-dashboard-stat-card>
        @endif
    </div>

    @if ($canViewRevenue)
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-900/5 dark:ring-white/10 p-5">
                <div class="flex items-center gap-2 mb-5">
                    <x-icon name="arrow-trending-up" class="h-5 w-5 text-indigo-500" />
                    <h3 class="font-semibold text-gray-800 dark:text-gray-100">
                        {{ match ($period) {
                            'week' => 'Sales, This Week',
                            'month' => 'Sales, This Month',
                            'year' => 'Sales, This Year (by Month)',
                            'all' => 'Sales, All Time (by Month)',
                            'custom' => 'Sales, Selected Range',
                            default => 'Sales, Today (by Hour)',
                        } }}
                    </h3>
                </div>

                @php $max = max(1, collect($salesTrend)->max('revenue')); @endphp
                <div class="flex items-end {{ count($salesTrend) > 15 ? 'gap-1' : 'gap-3' }} h-40 overflow-x-auto">
                    @foreach ($salesTrend as $day)
                        <div class="group/bar flex-1 min-w-0 flex flex-col items-center gap-1.5 h-full justify-end">
                            <span class="text-xs text-gray-500 dark:text-gray-400 tabular-nums opacity-0 group-hover/bar:opacity-100 transition-opacity">{{ $day['revenue'] > 0 ? number_format($day['revenue'], 0) : '' }}</span>
                            <div
                                class="w-full rounded-t-md bg-gradient-to-t from-indigo-600 to-indigo-400 dark:from-indigo-700 dark:to-indigo-500 group-hover/bar:from-indigo-500 group-hover/bar:to-indigo-300 transition-all"
                                style="height: {{ max(2, round($day['revenue'] / $max * 100)) }}%"
                                title="{{ $day['day'] }}: {{ number_format($day['revenue'], 2) }}"
                            ></div>
                            <span class="text-xs font-medium text-gray-400 dark:text-gray-500">{{ $day['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-900/5 dark:ring-white/10 p-5">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100 mb-4">Top Products</h3>
                <div class="space-y-1">
                    @forelse ($topProducts as $index => $product)
                        <div class="flex items-center gap-3 text-sm py-2 {{ ! $loop->last ? 'border-b border-gray-100 dark:border-gray-700/60' : '' }}">
                            <span class="flex items-center justify-center h-5 w-5 rounded-full bg-gray-100 dark:bg-gray-700 text-[11px] font-semibold text-gray-500 dark:text-gray-400 shrink-0">{{ $index + 1 }}</span>
                            <span class="text-gray-700 dark:text-gray-300 truncate flex-1">{{ $product->product_name }}</span>
                            <span class="text-gray-900 dark:text-gray-100 font-medium tabular-nums shrink-0">{{ number_format($product->total_revenue, 2) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">No sales yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    @if ($canViewRevenue || ! auth()->user()->isCashier())
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            @if ($canViewRevenue)
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-900/5 dark:ring-white/10 p-5">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-100 mb-4">Payment Methods</h3>
                    <div class="space-y-1">
                        @forelse ($paymentMethodBreakdown as $method)
                            <div class="flex items-center gap-3 text-sm py-2 {{ ! $loop->last ? 'border-b border-gray-100 dark:border-gray-700/60' : '' }}">
                                <span class="text-gray-700 dark:text-gray-300 truncate flex-1">{{ $method->payment_method_name }}</span>
                                <span class="text-gray-900 dark:text-gray-100 font-medium tabular-nums shrink-0">{{ number_format($method->total, 2) }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500 dark:text-gray-400">No sales yet.</p>
                        @endforelse
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-900/5 dark:ring-white/10 p-5">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-100 mb-4">Sales by Cashier</h3>
                    <div class="space-y-1">
                        @forelse ($cashierLeaderboard as $index => $cashier)
                            <div class="flex items-center gap-3 text-sm py-2 {{ ! $loop->last ? 'border-b border-gray-100 dark:border-gray-700/60' : '' }}">
                                <span class="flex items-center justify-center h-5 w-5 rounded-full bg-gray-100 dark:bg-gray-700 text-[11px] font-semibold text-gray-500 dark:text-gray-400 shrink-0">{{ $index + 1 }}</span>
                                <span class="text-gray-700 dark:text-gray-300 truncate flex-1">{{ $cashier->cashier_name }}</span>
                                <span class="text-gray-900 dark:text-gray-100 font-medium tabular-nums shrink-0">{{ number_format($cashier->total_revenue, 2) }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500 dark:text-gray-400">No sales yet.</p>
                        @endforelse
                    </div>
                </div>
            @endif

            @unless (auth()->user()->isCashier())
                <livewire:dashboard.online-users />
            @endunless
        </div>
    @endif

    @unless ($canViewRevenue || $canViewInventory || $canViewCustomers)
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-900/5 dark:ring-white/10 p-6 text-center text-sm text-gray-500 dark:text-gray-400">
            You're logged in — your role doesn't have visibility into any dashboard data yet.
        </div>
    @endunless
</div>
