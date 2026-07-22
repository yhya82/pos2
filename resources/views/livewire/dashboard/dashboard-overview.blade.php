<div class="space-y-6">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @if ($canViewSales)
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-4 flex items-start gap-3">
                <div class="rounded-lg bg-emerald-50 dark:bg-emerald-900/40 p-2 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="banknotes" class="h-5 w-5" />
                </div>
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Today's Revenue</div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($todaySales->revenue, 2) }}</div>
                    <div class="text-xs text-gray-400">{{ $todaySales->transaction_count }} transaction(s)</div>
                </div>
            </div>
        @endif

        @if ($canViewInventory)
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-4 flex items-start gap-3">
                <div class="rounded-lg bg-amber-50 dark:bg-amber-900/40 p-2 text-amber-600 dark:text-amber-400">
                    <x-icon name="exclamation-triangle" class="h-5 w-5" />
                </div>
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Low Stock Products</div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $lowStockCount }}</div>
                    <div class="text-xs text-gray-400">
                        <a href="{{ route('inventory.index') }}" wire:navigate class="hover:text-indigo-600 dark:hover:text-indigo-400">View inventory →</a>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-4 flex items-start gap-3">
                <div class="rounded-lg bg-red-50 dark:bg-red-900/40 p-2 text-red-600 dark:text-red-400">
                    <x-icon name="clock" class="h-5 w-5" />
                </div>
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Batches Expiring Soon</div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $expiringSoonCount }}</div>
                    <div class="text-xs text-gray-400">
                        <a href="{{ route('inventory.index') }}" wire:navigate class="hover:text-indigo-600 dark:hover:text-indigo-400">View expiry tracking →</a>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-4 flex items-start gap-3">
                <div class="rounded-lg bg-indigo-50 dark:bg-indigo-900/40 p-2 text-indigo-600 dark:text-indigo-400">
                    <x-icon name="archive-box" class="h-5 w-5" />
                </div>
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Inventory Value</div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($inventoryValue, 2) }}</div>
                    <div class="text-xs text-gray-400">at selling price</div>
                </div>
            </div>
        @endif

        @if ($canViewCustomers)
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-4 flex items-start gap-3">
                <div class="rounded-lg bg-blue-50 dark:bg-blue-900/40 p-2 text-blue-600 dark:text-blue-400">
                    <x-icon name="credit-card" class="h-5 w-5" />
                </div>
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Outstanding Credit</div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($outstandingCredit, 2) }}</div>
                    <div class="text-xs text-gray-400">
                        <a href="{{ route('customers.index') }}" wire:navigate class="hover:text-indigo-600 dark:hover:text-indigo-400">View customers →</a>
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if ($canViewSales)
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 bg-white dark:bg-gray-800 shadow sm:rounded-lg p-4">
                <div class="flex items-center gap-2 mb-4">
                    <x-icon name="arrow-trending-up" class="h-5 w-5 text-gray-400" />
                    <h3 class="font-semibold text-gray-800 dark:text-gray-100">Sales, Last 7 Days</h3>
                </div>

                @php $max = max(1, collect($salesTrend)->max('revenue')); @endphp
                <div class="flex items-end gap-3 h-40">
                    @foreach ($salesTrend as $day)
                        <div class="flex-1 flex flex-col items-center gap-1.5 h-full justify-end">
                            <span class="text-xs text-gray-500 dark:text-gray-400 tabular-nums">{{ $day['revenue'] > 0 ? number_format($day['revenue'], 0) : '' }}</span>
                            <div
                                class="w-full rounded-t bg-indigo-500 dark:bg-indigo-600 transition-all"
                                style="height: {{ max(2, round($day['revenue'] / $max * 100)) }}%"
                                title="{{ $day['day'] }}: {{ number_format($day['revenue'], 2) }}"
                            ></div>
                            <span class="text-xs text-gray-400">{{ $day['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-4">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100 mb-4">Top Products</h3>
                <div class="space-y-3">
                    @forelse ($topProducts as $product)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-700 dark:text-gray-300 truncate mr-2">{{ $product->product_name }}</span>
                            <span class="text-gray-500 dark:text-gray-400 tabular-nums shrink-0">{{ number_format($product->total_revenue, 2) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">No sales yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    @unless ($canViewSales || $canViewInventory || $canViewCustomers)
        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6 text-center text-sm text-gray-500 dark:text-gray-400">
            You're logged in — your role doesn't have visibility into any dashboard data yet.
        </div>
    @endunless
</div>
