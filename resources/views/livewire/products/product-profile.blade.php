<div>
    <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-4 mb-4">
        <div class="flex flex-wrap items-start gap-4">
            <div class="h-24 w-24 rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center overflow-hidden shrink-0">
                @if ($product->imageUrl())
                    <img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}" class="h-full w-full object-cover">
                @else
                    <x-icon name="cube" class="h-10 w-10 text-gray-400 dark:text-gray-500" />
                @endif
            </div>

            <div class="flex-1 min-w-[12rem]">
                <div class="flex items-center gap-2">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $product->name }}</h2>
                    <span @class([
                        'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $product->status === 'active',
                        'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $product->status === 'inactive',
                    ])>{{ ucfirst($product->status) }}</span>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ $product->category?->name ?? 'Uncategorized' }} @if ($product->supplier) · {{ $product->supplier->name }} @endif
                    @if ($product->barcode) · <span class="font-mono">{{ $product->barcode }}</span> @endif
                </p>
                @if ($product->description)
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-2 max-w-xl">{{ $product->description }}</p>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-x-6 gap-y-2 text-right">
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Stock on Hand</div>
                    <div @class(['text-lg font-semibold tabular-nums', 'text-red-600 dark:text-red-400' => $stockOnHand <= (float) $product->min_stock_level, 'text-gray-900 dark:text-gray-100' => $stockOnHand > (float) $product->min_stock_level])>
                        {{ rtrim(rtrim(number_format($stockOnHand, 3), '0'), '.') ?: '0' }} {{ $product->sellingUnit->name }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Selling Price</div>
                    <div class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($product->selling_price, 2) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="border-b border-gray-200 dark:border-gray-700 mb-4">
        <nav class="-mb-px flex gap-6">
            @foreach (['overview' => 'Overview', 'inventory' => 'Inventory & Batches', 'movements' => 'Stock Movements', 'discounts' => 'Discount History'] as $tab => $label)
                <button
                    wire:click="setTab('{{ $tab }}')"
                    @class(['py-3 px-1 border-b-2 text-sm font-medium', 'border-indigo-500 text-indigo-600 dark:text-indigo-400' => $activeTab === $tab, 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400' => $activeTab !== $tab])
                >{{ $label }}</button>
            @endforeach
        </nav>
    </div>

    @if ($activeTab === 'overview')
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ([
                'Cost Price' => number_format($product->cost_price, 2),
                'Purchase Unit' => $product->purchaseUnit->name,
                'Selling Unit' => $product->sellingUnit->name,
                'Conversion' => '1 '.$product->purchaseUnit->name.' = '.rtrim(rtrim(number_format($product->conversion_qty, 3), '0'), '.').' '.$product->sellingUnit->name,
                'Minimum Stock Level' => rtrim(rtrim(number_format($product->min_stock_level, 3), '0'), '.') ?: '0',
                'Created' => $product->created_at->format('Y-m-d'),
            ] as $label => $value)
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100 mt-1">{{ $value }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($activeTab === 'inventory')
        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Batch</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Received</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Remaining</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Unit Cost</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Received Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Expiry</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($batches as $batch)
                        <tr wire:key="batch-{{ $batch->id }}">
                            <td class="px-4 py-3 text-sm font-mono text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ $batch->batch_code ?? '#'.$batch->id }}</td>
                            <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ rtrim(rtrim(number_format($batch->qty_received, 3), '0'), '.') }}</td>
                            <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-900 dark:text-gray-100 whitespace-nowrap">{{ rtrim(rtrim(number_format($batch->qty_remaining, 3), '0'), '.') ?: '0' }}</td>
                            <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ number_format($batch->unit_cost, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $batch->received_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $batch->expiry_date?->format('Y-m-d') ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm whitespace-nowrap">
                                <span @class([
                                    'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $batch->status === 'active',
                                    'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $batch->status === 'depleted',
                                    'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => $batch->status === 'expired',
                                    'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' => $batch->status === 'written_off',
                                ])>{{ ucfirst(str_replace('_', ' ', $batch->status)) }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No batches received yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">{{ $batches->links() }}</div>
        </div>
    @endif

    @if ($activeTab === 'movements')
        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Type</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Before &rarr; After</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Reason</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">By</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($movements as $movement)
                        <tr wire:key="movement-{{ $movement->id }}">
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $movement->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3 text-sm whitespace-nowrap">
                                <span @class([
                                    'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $movement->movement_type === 'stock_received',
                                    'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' => $movement->movement_type === 'sale',
                                    'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/40 dark:text-cyan-300' => $movement->movement_type === 'return',
                                    'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' => $movement->movement_type === 'damaged',
                                    'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => $movement->movement_type === 'expired',
                                    'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $movement->movement_type === 'adjustment',
                                ])>{{ ucfirst($movement->movement_type) }}</span>
                            </td>
                            <td @class(['px-4 py-3 text-sm text-right tabular-nums whitespace-nowrap', 'text-emerald-600 dark:text-emerald-400' => $movement->quantity > 0, 'text-red-600 dark:text-red-400' => $movement->quantity < 0])>
                                {{ $movement->quantity > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($movement->quantity, 3), '0'), '.') }}
                            </td>
                            <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                {{ rtrim(rtrim(number_format($movement->previous_qty, 3), '0'), '.') ?: '0' }} &rarr; {{ rtrim(rtrim(number_format($movement->new_qty, 3), '0'), '.') ?: '0' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 max-w-xs truncate">{{ $movement->reason ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $movement->user?->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No stock movements recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">{{ $movements->links() }}</div>
        </div>
    @endif

    @if ($activeTab === 'discounts')
        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Receipt</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Unit Price</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Discount</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Reason</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($discountedLines as $line)
                        <tr wire:key="discount-{{ $line->id }}">
                            <td class="px-4 py-3 text-sm font-mono whitespace-nowrap">
                                <a href="{{ route('sales.receipt', $line->sale) }}" target="_blank" class="text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400">{{ $line->sale->receipt_number }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $line->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ rtrim(rtrim(number_format($line->quantity, 3), '0'), '.') }}</td>
                            <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ number_format($line->unit_price, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-right tabular-nums text-red-600 dark:text-red-400 whitespace-nowrap">
                                -{{ number_format($line->line_discount_amount, 2) }}
                                @if ($line->line_discount_type === 'percentage')
                                    <span class="text-xs text-gray-400">({{ rtrim(rtrim(number_format($line->line_discount_amount / max($line->unit_price * $line->quantity, 0.01) * 100, 1), '0'), '.') }}%)</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 max-w-xs truncate">{{ $line->line_discount_reason ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No discounted sales for this product yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">{{ $discountedLines->links() }}</div>
        </div>
    @endif
</div>
