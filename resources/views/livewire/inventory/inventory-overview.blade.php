<div>
    <div class="border-b border-gray-200 dark:border-gray-700 mb-4">
        <nav class="-mb-px flex gap-6">
            @foreach (['stock' => 'Stock Overview', 'movements' => 'Movement History', 'adjust' => 'Stock Adjustments', 'expiry' => 'Expiry Tracking'] as $tab => $label)
                <button
                    wire:click="setTab('{{ $tab }}')"
                    @class([
                        'py-3 px-1 border-b-2 text-sm font-medium',
                        'border-indigo-500 text-indigo-600 dark:text-indigo-400' => $activeTab === $tab,
                        'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200' => $activeTab !== $tab,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- ============================== STOCK OVERVIEW ============================== --}}
    @if ($activeTab === 'stock')
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <div class="w-full max-w-xs">
                <x-text-input wire:model.live.debounce.300ms="stockSearch" type="search" placeholder="Search product..." class="w-full" />
            </div>
            <select wire:model.live="stockCategoryId" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="stockSupplierId" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                <option value="">All suppliers</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                @endforeach
            </select>
            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" wire:model.live="lowStockOnly" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 focus:ring-indigo-500">
                Low stock only
            </label>
        </div>

        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Product</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Qty On Hand</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Min Level</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($stock as $row)
                        <tr wire:key="stock-{{ $row->product_id }}">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $row->product_name }}</td>
                            <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-600 dark:text-gray-400">{{ rtrim(rtrim(number_format($row->qty_on_hand, 3), '0'), '.') ?: '0' }}</td>
                            <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-600 dark:text-gray-400">{{ rtrim(rtrim(number_format($row->min_stock_level, 3), '0'), '.') ?: '0' }}</td>
                            <td class="px-4 py-3 text-sm">
                                @if ($row->qty_on_hand <= 0)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300">Out of Stock</span>
                                @elseif ($row->is_low_stock)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">Low Stock</span>
                                @else
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">OK</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No products found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                {{ $stock->links() }}
            </div>
        </div>
    @endif

    {{-- ============================== MOVEMENT HISTORY ============================== --}}
    @if ($activeTab === 'movements')
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <select wire:model.live="movementProductId" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                <option value="">All products</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}">{{ $product->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="movementType" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                <option value="">All movement types</option>
                @foreach ($movementTypes as $type)
                    <option value="{{ $type }}">{{ str($type)->headline() }}</option>
                @endforeach
            </select>
            <input type="date" wire:model.live="movementDateFrom" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
            <span class="text-gray-400 text-sm">to</span>
            <input type="date" wire:model.live="movementDateTo" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
        </div>

        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Product</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Type</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Before → After</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Reason</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">User</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($movements as $movement)
                        <tr wire:key="movement-{{ $movement->id }}">
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $movement->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100 whitespace-nowrap">{{ $movement->product->name }}</td>
                            <td class="px-4 py-3 text-sm whitespace-nowrap">
                                <span @class([
                                    'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $movement->movement_type === 'stock_received',
                                    'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' => $movement->movement_type === 'sale',
                                    'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => $movement->movement_type === 'return',
                                    'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' => in_array($movement->movement_type, ['damaged', 'expired']),
                                    'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $movement->movement_type === 'adjustment',
                                ])>
                                    {{ str($movement->movement_type)->headline() }}
                                </span>
                            </td>
                            <td @class(['px-4 py-3 text-sm text-right tabular-nums whitespace-nowrap', 'text-emerald-600' => $movement->quantity > 0, 'text-red-600' => $movement->quantity < 0])>
                                {{ $movement->quantity > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($movement->quantity, 3), '0'), '.') }}
                            </td>
                            <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                {{ rtrim(rtrim(number_format($movement->previous_qty, 3), '0'), '.') ?: '0' }} → {{ rtrim(rtrim(number_format($movement->new_qty, 3), '0'), '.') ?: '0' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $movement->reason }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $movement->user?->name }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No movements found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                {{ $movements->links() }}
            </div>
        </div>
    @endif

    {{-- ============================== STOCK ADJUSTMENTS ============================== --}}
    @if ($activeTab === 'adjust')
        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6 max-w-xl">
            @if (auth()->user()->hasPermission('inventory', 'update'))
                <form wire:submit="submitAdjustment" class="space-y-4">
                    <div>
                        <x-input-label for="adjust_product" value="Product" />
                        <select wire:model.live="adjustProductId" id="adjust_product" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Select a product...</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <x-input-label for="adjust_batch" value="Batch" />
                        <select wire:model="adjustBatchId" id="adjust_batch" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Select a batch...</option>
                            @foreach ($availableBatches as $batch)
                                <option value="{{ $batch->id }}">
                                    {{ $batch->batch_code ?: "Batch #{$batch->id}" }} — {{ rtrim(rtrim(number_format($batch->qty_remaining, 3), '0'), '.') }} remaining
                                    @if ($batch->expiry_date) (expires {{ $batch->expiry_date->format('Y-m-d') }}) @endif
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('adjustBatchId')" class="mt-2" />
                        @if ($adjustProductId && $availableBatches->isEmpty())
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">This product has no active batches to adjust.</p>
                        @endif
                    </div>

                    <div>
                        <x-input-label for="adjust_type" value="Adjustment Type" />
                        <select wire:model="adjustType" id="adjust_type" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="correction_add">Correction — Add</option>
                            <option value="correction_remove">Correction — Remove</option>
                            <option value="damaged">Damaged</option>
                            <option value="expired">Expired</option>
                        </select>
                        <x-input-error :messages="$errors->get('adjustType')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="adjust_qty" value="Quantity" />
                        <x-text-input wire:model="adjustQty" id="adjust_qty" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('adjustQty')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="adjust_reason" value="Reason" />
                        <textarea wire:model="adjustReason" id="adjust_reason" rows="2" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                        <x-input-error :messages="$errors->get('adjustReason')" class="mt-2" />
                    </div>

                    <div class="flex justify-end">
                        <x-primary-button type="submit">Record Adjustment</x-primary-button>
                    </div>
                </form>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">You don't have permission to record stock adjustments.</p>
            @endif
        </div>
    @endif

    {{-- ============================== EXPIRY TRACKING ============================== --}}
    @if ($activeTab === 'expiry')
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <div class="w-full max-w-xs">
                <x-text-input wire:model.live.debounce.300ms="expirySearch" type="search" placeholder="Search product..." class="w-full" />
            </div>
            <select wire:model.live="expiryWithinDays" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                <option value="">All upcoming expiries</option>
                <option value="7">Within 7 days</option>
                <option value="30">Within 30 days</option>
                <option value="60">Within 60 days</option>
            </select>
        </div>

        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Product</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Qty Remaining</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Expiry Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Days Left</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($expiring as $batch)
                        <tr wire:key="expiry-{{ $batch->batch_id }}">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $batch->product_name }}</td>
                            <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-600 dark:text-gray-400">{{ rtrim(rtrim(number_format($batch->qty_remaining, 3), '0'), '.') }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $batch->expiry_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3 text-sm">
                                @if ($batch->days_to_expiry < 0)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300">Expired</span>
                                @elseif ($batch->days_to_expiry <= 7)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300">{{ $batch->days_to_expiry }} days</span>
                                @elseif ($batch->days_to_expiry <= 30)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">{{ $batch->days_to_expiry }} days</span>
                                @else
                                    <span class="text-gray-600 dark:text-gray-400">{{ $batch->days_to_expiry }} days</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No batches with an expiry date found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                {{ $expiring->links() }}
            </div>
        </div>
    @endif
</div>
