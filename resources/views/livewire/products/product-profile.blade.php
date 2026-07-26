<div>
    <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl ring-1 ring-gray-900/5 dark:ring-white/10 p-4 mb-4">
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

                <div class="flex flex-wrap gap-2 mt-3">
                    @if (auth()->user()->hasPermission('inventory', 'update'))
                        <x-secondary-button wire:click="openAddStockForm" class="!py-1 !px-2.5 !text-xs !bg-emerald-50 dark:!bg-emerald-900/40 !text-emerald-700 dark:!text-emerald-300 !border-emerald-200 dark:!border-emerald-800 hover:!bg-emerald-100 dark:hover:!bg-emerald-900/60">Add Stock</x-secondary-button>
                        <x-secondary-button wire:click="openAdjustForm('correction_add')" class="!py-1 !px-2.5 !text-xs !bg-blue-50 dark:!bg-blue-900/40 !text-blue-700 dark:!text-blue-300 !border-blue-200 dark:!border-blue-800 hover:!bg-blue-100 dark:hover:!bg-blue-900/60">Adjust Stock</x-secondary-button>
                        <x-secondary-button wire:click="openAdjustForm('damaged')" class="!py-1 !px-2.5 !text-xs !bg-amber-50 dark:!bg-amber-900/40 !text-amber-700 dark:!text-amber-300 !border-amber-200 dark:!border-amber-800 hover:!bg-amber-100 dark:hover:!bg-amber-900/60">Mark Damaged</x-secondary-button>
                        <x-secondary-button wire:click="openAdjustForm('correction_remove')" class="!py-1 !px-2.5 !text-xs !bg-red-50 dark:!bg-red-900/40 !text-red-700 dark:!text-red-300 !border-red-200 dark:!border-red-800 hover:!bg-red-100 dark:hover:!bg-red-900/60">Remove Stock</x-secondary-button>
                    @endif
                    @if (auth()->user()->hasPermission('discounts', 'update'))
                        <x-secondary-button wire:click="openDiscountForm" class="!py-1 !px-2.5 !text-xs !bg-pink-50 dark:!bg-pink-900/40 !text-pink-700 dark:!text-pink-300 !border-pink-200 dark:!border-pink-800 hover:!bg-pink-100 dark:hover:!bg-pink-900/60">{{ $product->hasActivePromo() ? 'Edit Discount' : 'Set Discount' }}</x-secondary-button>
                    @endif
                </div>
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
                    @if ($product->hasActivePromo())
                        <div class="text-sm text-gray-400 dark:text-gray-500 line-through tabular-nums">{{ number_format($product->selling_price, 2) }}</div>
                        <div class="text-lg font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{{ number_format($product->effectiveSellingPrice(), 2) }}</div>
                        <div class="text-xs text-emerald-600 dark:text-emerald-400">
                            {{ $product->promo_discount_type === 'percentage' ? rtrim(rtrim(number_format($product->promo_discount_value, 2), '0'), '.').'% off' : number_format($product->promo_discount_value, 2).' off' }}
                            @if ($product->promo_ends_at) until {{ $product->promo_ends_at->format('Y-m-d') }} @endif
                        </div>
                    @else
                        <div class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($product->selling_price, 2) }}</div>
                    @endif
                </div>
            </div>
        </div>

        @if ($showAddStockForm)
            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 max-w-xl">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Add Stock</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2 mb-3">Creates a new batch directly — no purchase order needed. Use this for opening stock or deliveries you're not tracking through Purchase Orders.</p>
                <form wire:submit="submitAddStock" class="space-y-4">
                    @php
                        $hasDistinctUnits = $product->purchase_unit_id !== $product->selling_unit_id;
                        $addStockUnitLabel = $hasDistinctUnits
                            ? ($addStockQtyUnit === 'purchase' ? $product->purchaseUnit->name : $product->sellingUnit->name)
                            : $product->sellingUnit->name;
                    @endphp
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="add_stock_qty" value="Quantity Received" />
                            <div class="mt-1 flex gap-2">
                                <x-text-input wire:model="addStockQty" id="add_stock_qty" class="block w-full" />
                                @if ($hasDistinctUnits)
                                    <select wire:model.live="addStockQtyUnit" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                        <option value="purchase">{{ $product->purchaseUnit->name }}</option>
                                        <option value="selling">{{ $product->sellingUnit->name }}</option>
                                    </select>
                                @else
                                    <span class="inline-flex items-center px-3 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $addStockUnitLabel }}</span>
                                @endif
                            </div>
                            <x-input-error :messages="$errors->get('addStockQty')" class="mt-2" />
                            <x-input-error :messages="$errors->get('addStockQtyUnit')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="add_stock_cost" :value="'Unit Cost (per '.$addStockUnitLabel.')'" />
                            <x-text-input wire:model="addStockUnitCost" id="add_stock_cost" class="block mt-1 w-full" />
                            <x-input-error :messages="$errors->get('addStockUnitCost')" class="mt-2" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="add_stock_received" value="Received Date" />
                            <input type="date" wire:model="addStockReceivedDate" id="add_stock_received" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <x-input-error :messages="$errors->get('addStockReceivedDate')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="add_stock_expiry" value="Expiry Date (optional)" />
                            <input type="date" wire:model="addStockExpiryDate" id="add_stock_expiry" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <x-input-error :messages="$errors->get('addStockExpiryDate')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="add_stock_batch_code" value="Batch Code (optional)" />
                        <x-text-input wire:model="addStockBatchCode" id="add_stock_batch_code" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('addStockBatchCode')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="add_stock_reason" value="Reason / Note (optional)" />
                        <textarea wire:model="addStockReason" id="add_stock_reason" rows="2" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                        <x-input-error :messages="$errors->get('addStockReason')" class="mt-2" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <x-secondary-button type="button" wire:click="cancelAddStockForm">Cancel</x-secondary-button>
                        <x-primary-button type="submit">Add Stock</x-primary-button>
                    </div>
                </form>
            </div>
        @endif

        @if ($showAdjustForm)
            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 max-w-xl">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Stock Adjustment</h3>
                <form wire:submit="submitAdjustment" class="space-y-4">
                    <div>
                        <x-input-label for="adjust_batch" value="Batch" />
                        <select wire:model.live="adjustBatchId" id="adjust_batch" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Select a batch...</option>
                            @foreach ($availableBatches as $batch)
                                <option value="{{ $batch->id }}">
                                    {{ $batch->batch_code ?: "Batch #{$batch->id}" }} — {{ rtrim(rtrim(number_format($batch->qty_remaining, 3), '0'), '.') }} remaining
                                    @if ($batch->expiry_date) (expires {{ $batch->expiry_date->format('Y-m-d') }}) @endif
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('adjustBatchId')" class="mt-2" />
                        @if ($availableBatches->isEmpty())
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">This product has no active batches to adjust.</p>
                        @endif
                    </div>

                    <div>
                        <x-input-label for="adjust_type" value="Adjustment Type" />
                        <select wire:model.live="adjustType" id="adjust_type" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="correction_add">Correction — Add</option>
                            <option value="correction_remove">Correction — Remove</option>
                            <option value="damaged">Damaged</option>
                            <option value="expired">Expired</option>
                        </select>
                        <x-input-error :messages="$errors->get('adjustType')" class="mt-2" />
                        @if ($adjustType === 'correction_add' && $adjustBatchId)
                            @php $selectedBatch = $availableBatches->firstWhere('id', (int) $adjustBatchId); @endphp
                            @if ($selectedBatch)
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    This batch received {{ rtrim(rtrim(number_format($selectedBatch->qty_received, 3), '0'), '.') }} in total — you can add up to {{ rtrim(rtrim(number_format($selectedBatch->qty_received - $selectedBatch->qty_remaining, 3), '0'), '.') ?: '0' }} more before it would exceed that.
                                </p>
                            @endif
                        @endif
                    </div>

                    <div>
                        <x-input-label for="adjust_qty" :value="'Quantity ('.$product->sellingUnit->name.')'" />
                        <x-text-input wire:model="adjustQty" id="adjust_qty" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('adjustQty')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="adjust_reason" value="Reason" />
                        <textarea wire:model="adjustReason" id="adjust_reason" rows="2" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                        <x-input-error :messages="$errors->get('adjustReason')" class="mt-2" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <x-secondary-button type="button" wire:click="cancelAdjustForm">Cancel</x-secondary-button>
                        <x-primary-button type="submit">Record Adjustment</x-primary-button>
                    </div>
                </form>
            </div>
        @endif

        @if ($showDiscountForm)
            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 max-w-xl">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Promotional Discount</h3>
                <form wire:submit="saveDiscount" class="space-y-4">
                    <div>
                        <x-input-label for="promo_type" value="Discount Type" />
                        <select wire:model.live="promoDiscountType" id="promo_type" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="none">None</option>
                            <option value="fixed">Fixed Amount Off</option>
                            <option value="percentage">Percentage Off</option>
                        </select>
                        <x-input-error :messages="$errors->get('promoDiscountType')" class="mt-2" />
                    </div>

                    @if ($promoDiscountType !== 'none')
                        <div>
                            <x-input-label for="promo_value" :value="$promoDiscountType === 'percentage' ? 'Percentage (%)' : 'Amount Off'" />
                            <x-text-input wire:model="promoDiscountValue" id="promo_value" class="block mt-1 w-full" />
                            <x-input-error :messages="$errors->get('promoDiscountValue')" class="mt-2" />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="promo_starts" value="Starts (optional)" />
                                <input type="date" wire:model="promoStartsAt" id="promo_starts" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <x-input-error :messages="$errors->get('promoStartsAt')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="promo_ends" value="Ends (optional)" />
                                <input type="date" wire:model="promoEndsAt" id="promo_ends" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <x-input-error :messages="$errors->get('promoEndsAt')" class="mt-2" />
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Leave both dates blank for an always-on standing discount.</p>
                    @endif

                    <div class="flex justify-end gap-2">
                        @if ($product->hasActivePromo())
                            <x-secondary-button type="button" wire:click="clearDiscount">Remove Discount</x-secondary-button>
                        @endif
                        <x-secondary-button type="button" wire:click="cancelDiscountForm">Cancel</x-secondary-button>
                        <x-primary-button type="submit">Save</x-primary-button>
                    </div>
                </form>
            </div>
        @endif
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
                'Minimum Stock Level' => (rtrim(rtrim(number_format($product->min_stock_level, 3), '0'), '.') ?: '0').' '.$product->sellingUnit->name,
                'Promotional Discount' => $product->hasActivePromo()
                    ? ($product->promo_discount_type === 'percentage' ? rtrim(rtrim(number_format($product->promo_discount_value, 2), '0'), '.').'% off' : number_format($product->promo_discount_value, 2).' off')
                    : 'None',
                'Created' => $product->created_at->format('Y-m-d'),
            ] as $label => $value)
                <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl ring-1 ring-gray-900/5 dark:ring-white/10 p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100 mt-1">{{ $value }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($activeTab === 'inventory')
        <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl ring-1 ring-gray-900/5 dark:ring-white/10 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Batch</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Received ({{ $product->sellingUnit->name }})</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Remaining ({{ $product->sellingUnit->name }})</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Unit Cost (per {{ $product->sellingUnit->name }})</th>
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
        <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl ring-1 ring-gray-900/5 dark:ring-white/10 overflow-x-auto">
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
        <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl ring-1 ring-gray-900/5 dark:ring-white/10 overflow-x-auto">
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
