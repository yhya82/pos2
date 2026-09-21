<div>
    <div class="flex items-center justify-between gap-4 mb-4">
        <div class="flex items-center gap-3">
            <div class="w-full max-w-xs">
                <x-text-input wire:model.live.debounce.300ms="search" type="search" placeholder="Search PO # or supplier..." class="w-full" />
            </div>
            <select wire:model.live="statusFilter" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                <option value="">All statuses</option>
                <option value="draft">Draft</option>
                <option value="ordered">Ordered</option>
                <option value="partially_received">Partially Received</option>
                <option value="received">Received</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>

        @if (auth()->user()->hasPermission('purchase_orders', 'create'))
            <x-primary-button wire:click="create">
                Create Purchase Order
            </x-primary-button>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl ring-1 ring-gray-900/5 dark:ring-white/10 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-900/40">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">PO #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Supplier</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Order Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Items</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Created By</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($purchaseOrders as $po)
                    <tr wire:key="po-{{ $po->id }}">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100 whitespace-nowrap font-mono"><button type="button" wire:click="view({{ $po->id }})" class="hover:text-indigo-600 dark:hover:text-indigo-400 hover:underline">{{ $po->po_number }}</button></td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $po->supplier->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $po->order_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 max-w-xs">
                            @php $shown = $po->lineItems->take(3); @endphp
                            <div class="space-y-0.5">
                                @foreach ($shown as $item)
                                    <div class="truncate" title="{{ $item->product->name }}">
                                        <span class="text-gray-800 dark:text-gray-200">{{ $item->product->name }}</span>
                                        <span class="text-xs text-gray-400 tabular-nums">
                                            × {{ rtrim(rtrim(number_format((float) $item->qty_ordered, 3), '0'), '.') }} {{ $item->purchaseUnit->name }}@if ((float) $item->qty_damaged > 0)
                                                · <span class="text-amber-600 dark:text-amber-400">{{ rtrim(rtrim(number_format((float) $item->qty_damaged, 3), '0'), '.') }} damaged</span>
                                            @endif
                                            @if ((float) $item->qty_closed_short > 0)
                                                · <span class="text-red-600 dark:text-red-400">{{ rtrim(rtrim(number_format((float) $item->qty_closed_short, 3), '0'), '.') }} short</span>
                                            @endif
                                            @if ((float) $item->qty_received > 0 && $po->status !== 'draft')
                                                · {{ rtrim(rtrim(number_format((float) $item->qty_received, 3), '0'), '.') }} received
                                            @endif
                                        </span>
                                    </div>
                                @endforeach
                                @if ($po->line_items_count > $shown->count())
                                    <div class="text-xs text-gray-400" title="{{ $po->lineItems->pluck('product.name')->implode(', ') }}">+ {{ $po->line_items_count - $shown->count() }} more</div>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $po->creator->name }}</td>
                        <td class="px-4 py-3 text-sm whitespace-nowrap">
                            <span @class([
                                'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $po->status === 'draft',
                                'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' => $po->status === 'ordered',
                                'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => $po->status === 'partially_received',
                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $po->status === 'received',
                                'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' => $po->status === 'cancelled',
                            ])>
                                {{ str($po->status)->headline() }}
                            </span>
                            @if ($po->approved_at)
                                <span class="ml-1 text-xs text-gray-400" title="Approved by {{ $po->approver?->name }} on {{ $po->approved_at->format('Y-m-d') }}">✓ approved</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-right whitespace-nowrap space-x-3">
                            <button wire:click="view({{ $po->id }})" class="text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white font-medium">View</button>
                            @if (auth()->user()->hasPermission('purchase_orders', 'update'))
                                @if ($po->status === 'draft')
                                    <button wire:click="edit({{ $po->id }})" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium">Edit</button>
                                    @unless ($po->approved_at)
                                        <button wire:click="approve({{ $po->id }})" class="text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white font-medium">Approve</button>
                                    @endunless
                                    <button wire:click="markAsOrdered({{ $po->id }})" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-medium">Mark Ordered</button>
                                @endif
                                @if (in_array($po->status, ['ordered', 'partially_received']))
                                    <button wire:click="openReceive({{ $po->id }})" class="text-emerald-600 hover:text-emerald-800 dark:text-emerald-400 dark:hover:text-emerald-300 font-medium">Receive</button>
                                @endif
                                @if (in_array($po->status, ['draft', 'ordered']))
                                    <button wire:click="confirmCancel({{ $po->id }})" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 font-medium">Cancel</button>
                                @endif
                            @endif
                            @if ($po->status === 'draft' && auth()->user()->hasPermission('purchase_orders', 'delete'))
                                <button wire:click="confirmDelete({{ $po->id }})" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 font-medium">Delete</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            No purchase orders found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
            {{ $purchaseOrders->links() }}
        </div>
    </div>

    <x-slide-over name="po-form" :title="$editingPoId ? 'Edit Purchase Order' : 'Create Purchase Order'" max-width="2xl">
        <form wire:submit="save" id="po-form" class="space-y-6">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="po_supplier" value="Supplier" />
                    <select wire:model="supplierId" id="po_supplier" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select...</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('supplierId')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="po_order_date" value="Order Date" />
                    <x-text-input wire:model="orderDate" id="po_order_date" type="date" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('orderDate')" class="mt-2" />
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-2">
                    <x-input-label value="Line Items" />
                    <button type="button" wire:click="addLine" class="text-sm text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 font-medium">+ Add Line</button>
                </div>
                <x-input-error :messages="$errors->get('lines')" class="mb-2" />

                <div class="space-y-3">
                    @foreach ($lines as $index => $line)
                        <div class="grid grid-cols-2 sm:grid-cols-12 gap-2 items-start border border-gray-200 dark:border-gray-700 rounded-md p-3" wire:key="line-{{ $index }}">
                            <div class="col-span-2 sm:col-span-4">
                                <select wire:model.live="lines.{{ $index }}.product_id" class="block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Product...</option>
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('lines.'.$index.'.product_id')" class="mt-1" />
                            </div>

                            <div class="col-span-1 sm:col-span-2">
                                <input type="text" wire:model.live.debounce.300ms="lines.{{ $index }}.qty_ordered" placeholder="Qty" class="block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @php
                                    $convProduct = $products->firstWhere('id', $line['product_id']);
                                    $convDiffers = $convProduct && $convProduct->purchase_unit_id !== $convProduct->selling_unit_id && (float) $convProduct->conversion_qty > 0;
                                @endphp
                                @if ($convDiffers && is_numeric($line['qty_ordered']) && (float) $line['qty_ordered'] > 0)
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">= {{ rtrim(rtrim(number_format((float) $line['qty_ordered'] * (float) $convProduct->conversion_qty, 3), '0'), '.') }} {{ $convProduct->sellingUnit->name }}</p>
                                @endif
                                <x-input-error :messages="$errors->get('lines.'.$index.'.qty_ordered')" class="mt-1" />
                            </div>

                            <div class="col-span-1 sm:col-span-3">
                                {{-- Read-only: always the product's own purchase unit (auto-filled below when a
                                     product is picked), since conversion_qty is only meaningful for that one
                                     pairing — letting a line override it would silently break unit conversion
                                     at receiving time. --}}
                                <div class="flex items-center h-[38px] px-3 text-sm text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/40 rounded-md border border-gray-200 dark:border-gray-700">
                                    {{ $products->firstWhere('id', $line['product_id'])?->purchaseUnit->name ?? '—' }}
                                </div>
                                @php
                                    $rateProduct = $products->firstWhere('id', $line['product_id']);
                                    $rateShown = $rateProduct && $rateProduct->purchase_unit_id !== $rateProduct->selling_unit_id && (float) $rateProduct->conversion_qty > 0;
                                @endphp
                                @if ($rateShown)
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">1 {{ $rateProduct->purchaseUnit->name }} = {{ rtrim(rtrim(number_format((float) $rateProduct->conversion_qty, 3), '0'), '.') }} {{ $rateProduct->sellingUnit->name }}</p>
                                @endif
                                <x-input-error :messages="$errors->get('lines.'.$index.'.purchase_unit_id')" class="mt-1" />
                            </div>

                            <div class="col-span-1 sm:col-span-2">
                                @php
                                    $lineProduct = $products->firstWhere('id', $line['product_id']);
                                    $lineCostPerSellingUnit = $lineProduct
                                        && $lineProduct->purchase_unit_id !== $lineProduct->selling_unit_id
                                        && is_numeric($line['cost_price'])
                                        ? $lineProduct->toSellingUnitCost((float) $line['cost_price'], 'purchase')
                                        : null;
                                @endphp
                                @if ($canEditPrices)
                                    <input type="text" wire:model.live.debounce.300ms="lines.{{ $index }}.cost_price" placeholder="{{ $lineProduct ? 'Cost per '.$lineProduct->purchaseUnit->name : 'Cost' }}" class="block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @else
                                    <div class="flex items-center h-[38px] px-3 text-sm text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/40 rounded-md border border-gray-200 dark:border-gray-700" title="Only users who can edit products can change costs">{{ is_numeric($line['cost_price']) ? number_format((float) $line['cost_price'], 2) : '—' }}</div>
                                @endif
                                @if ($lineCostPerSellingUnit !== null)
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">= {{ number_format($lineCostPerSellingUnit, 2) }} / {{ $lineProduct->sellingUnit->name }}</p>
                                @endif
                                @if (is_numeric($line['qty_ordered']) && is_numeric($line['cost_price']) && (float) $line['qty_ordered'] > 0)
                                    <p class="text-xs font-medium text-gray-700 dark:text-gray-300 mt-1">Line total: {{ number_format((float) $line['qty_ordered'] * (float) $line['cost_price'], 2) }}</p>
                                @endif
                                <x-input-error :messages="$errors->get('lines.'.$index.'.cost_price')" class="mt-1" />
                            </div>

                            <div class="col-span-1 flex justify-end">
                                @if (count($lines) > 1)
                                    <button type="button" wire:click="removeLine({{ $index }})" class="text-red-500 hover:text-red-700 text-sm">✕</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                @php
                    $formTotal = collect($lines)->sum(fn ($l) => is_numeric($l['qty_ordered'] ?? null) && is_numeric($l['cost_price'] ?? null) ? (float) $l['qty_ordered'] * (float) $l['cost_price'] : 0);
                @endphp
                <div class="mt-3 flex items-center justify-between rounded-md bg-gray-50 dark:bg-gray-900/40 ring-1 ring-gray-200 dark:ring-gray-700 px-3 py-2">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Order total <span class="text-xs font-normal text-gray-400">({{ count($lines) }} {{ \Illuminate\Support\Str::plural('line', count($lines)) }})</span></span>
                    <span class="text-base font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($formTotal, 2) }}</span>
                </div>
                @unless ($canEditPrices)
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Costs come from the product and can only be changed by someone who can edit products.</p>
                @endunless
            </div>
        </form>

        <x-slot name="footer">
            <x-secondary-button x-on:click="show = false">Cancel</x-secondary-button>
            <x-primary-button type="submit" form="po-form">Save</x-primary-button>
        </x-slot>
    </x-slide-over>

    <x-slide-over name="receive-form" title="Receive Stock">
        <form wire:submit="receive" id="receive-form" class="space-y-4">
            @forelse ($receivingLines as $index => $line)
                <div class="border border-gray-200 dark:border-gray-700 rounded-md p-3" wire:key="receiving-line-{{ $index }}">
                    <div class="flex items-center justify-between text-sm font-medium text-gray-800 dark:text-gray-100">
                        <span>{{ $line['product_name'] }}</span>
                        <span class="text-gray-500 dark:text-gray-400 font-normal text-right">
                            {{ rtrim(rtrim(number_format((float) $line['ordered'], 3), '0'), '.') ?: '0' }} {{ $line['unit_name'] }} ordered
                            @if ($line['units_differ'] && $line['conversion_qty'] > 0)
                                <span class="block text-xs">= {{ rtrim(rtrim(number_format((float) $line['ordered'] * $line['conversion_qty'], 3), '0'), '.') ?: '0' }} {{ $line['selling_unit_name'] }}</span>
                            @endif
                            @if ($line['already_in'] > 0.0000005)
                                <span class="block text-xs text-amber-700 dark:text-amber-300">{{ rtrim(rtrim(number_format((float) $line['already_in'], 3), '0'), '.') ?: '0' }} already in · {{ rtrim(rtrim(number_format((float) $line['remaining'], 3), '0'), '.') ?: '0' }} left</span>
                            @endif
                        </span>
                    </div>

                    @php
                        // Judged in selling units (pieces) — the exact figure — whichever
                        // unit each quantity was typed in.
                        $conv = $line['conversion_qty'] > 0 ? $line['conversion_qty'] : 1;
                        $goodNow = is_numeric($line['qty']) ? (float) $line['qty'] * ($line['qty_unit'] === 'selling' ? 1 : $conv) : 0;
                        $badNow = is_numeric($line['damaged_qty']) ? (float) $line['damaged_qty'] * ($line['damaged_unit'] === 'selling' ? 1 : $conv) : 0;
                        $remainingPieces = round((float) $line['remaining'] * $conv, 3);
                        $leftAfter = max(0, round($remainingPieces - $goodNow - $badNow, 3));
                        $pieceCost = is_numeric($line['unit_cost']) ? (float) $line['unit_cost'] / $conv : 0;
                        $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3), '0'), '.') ?: '0';
                        $over = $goodNow + $badNow > $remainingPieces + 0.0005;
                    @endphp

                    <div class="grid grid-cols-2 gap-2 mt-2">
                        <div>
                            <x-input-label value="Qty received now" class="text-xs" />
                            <div class="flex gap-2">
                                <input type="text" wire:model.live.debounce.300ms="receivingLines.{{ $index }}.qty" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @if ($line['units_differ'])
                                    <select wire:model.live="receivingLines.{{ $index }}.qty_unit" class="mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                        <option value="purchase">{{ $line['unit_name'] }}</option>
                                        <option value="selling">{{ $line['selling_unit_name'] }}</option>
                                    </select>
                                @else
                                    <span class="inline-flex items-center mt-1 px-2 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $line['unit_name'] }}</span>
                                @endif
                            </div>
                            @if ($line['units_differ'] && $line['conversion_qty'] > 0)
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    1 {{ $line['unit_name'] }} = {{ rtrim(rtrim(number_format($line['conversion_qty'], 3), '0'), '.') }} {{ $line['selling_unit_name'] }}
                                    @if (is_numeric($line['qty']) && (float) $line['qty'] > 0)
                                        <br><span class="text-gray-700 dark:text-gray-300">Adds {{ rtrim(rtrim(number_format((float) $line['qty'] * ($line['qty_unit'] === 'selling' ? 1 : $line['conversion_qty']), 3), '0'), '.') }} {{ $line['selling_unit_name'] }} to stock</span>
                                    @endif
                                </p>
                            @endif
                        </div>
                        <div>
                            <x-input-label value="Batch code" class="text-xs" />
                            <input type="text" wire:model="receivingLines.{{ $index }}.batch_code" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <x-input-label value="Expiry date" class="text-xs" />
                            <input type="date" wire:model="receivingLines.{{ $index }}.expiry_date" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <x-input-label value="Received date" class="text-xs" />
                            <input type="date" wire:model="receivingLines.{{ $index }}.received_date" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <x-input-label :value="'Cost paid (per '.$line['unit_name'].')'" class="text-xs" />
                            @if ($canEditPrices)
                                <input type="text" wire:model.live.debounce.300ms="receivingLines.{{ $index }}.unit_cost" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @else
                                <div class="flex items-center h-[38px] mt-1 px-3 text-sm text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/40 rounded-md border border-gray-200 dark:border-gray-700">{{ is_numeric($line['unit_cost']) ? number_format((float) $line['unit_cost'], 2) : '—' }}</div>
                            @endif
                        </div>
                        <div>
                            <x-input-label :value="'Selling price (per '.$line['selling_unit_name'].')'" class="text-xs" />
                            @if ($canEditPrices)
                                <input type="text" wire:model.live.debounce.300ms="receivingLines.{{ $index }}.selling_price" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @else
                                <div class="flex items-center h-[38px] mt-1 px-3 text-sm text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/40 rounded-md border border-gray-200 dark:border-gray-700">{{ number_format($line['current_price'], 2) }}</div>
                            @endif
                        </div>
                    </div>

                    @if ($over)
                        <div class="mt-2 rounded-md border border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-900/20 px-3 py-2 text-xs text-red-700 dark:text-red-300">
                            You ordered <strong>{{ $fmt($line['ordered']) }} {{ $line['unit_name'] }}</strong>@if ($line['already_in'] > 0.0000005), and {{ $fmt($line['already_in']) }} {{ $line['already_in'] == 1 ? 'is' : 'are' }} already in @endif
                            — so no more than <strong>{{ $fmt($remainingPieces) }} {{ $line['selling_unit_name'] }}</strong>@if ($line['units_differ']) ({{ $fmt($line['remaining']) }} {{ $line['unit_name'] }})@endif can be received now.
                            You've entered {{ $fmt($goodNow + $badNow) }} {{ $line['selling_unit_name'] }}@if ($badNow > 0) ({{ $fmt($goodNow) }} good + {{ $fmt($badNow) }} damaged)@endif.
                            Please lower the quantity — receiving is blocked until it fits.
                        </div>
                    @endif

                    <div x-data="{ open: {{ ($line['damaged_qty'] !== '' || $line['close_short']) ? 'true' : 'false' }} }" class="mt-3">
                        <button type="button" x-on:click="open = ! open" class="text-xs font-medium text-amber-700 dark:text-amber-300 hover:underline" x-text="open ? '− Hide damaged / missing' : '+ Some damaged or missing?'"></button>
                        <div x-show="open" x-cloak class="mt-2 space-y-2 rounded-md bg-amber-50 dark:bg-amber-900/20 ring-1 ring-amber-200 dark:ring-amber-800 p-3">
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <x-input-label value="Damaged on arrival" class="text-xs" />
                                    <div class="flex gap-2">
                                        <input type="text" wire:model.live.debounce.300ms="receivingLines.{{ $index }}.damaged_qty" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        @if ($line['units_differ'])
                                            <select wire:model.live="receivingLines.{{ $index }}.damaged_unit" class="mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                                <option value="purchase">{{ $line['unit_name'] }}</option>
                                                <option value="selling">{{ $line['selling_unit_name'] }}</option>
                                            </select>
                                        @endif
                                    </div>
                                </div>
                                <div>
                                    <x-input-label value="What's wrong with them?" class="text-xs" />
                                    <input type="text" wire:model="receivingLines.{{ $index }}.damaged_reason" placeholder="e.g. crushed, leaking" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                            </div>
                            <p class="text-xs text-gray-600 dark:text-gray-400">Damaged units don't go into stock. They're recorded as a loss at cost and a claim against the supplier.</p>

                            @if ($canWriteOff)
                                <label class="flex items-start gap-2 text-xs text-gray-700 dark:text-gray-300">
                                    <input type="checkbox" wire:model.live="receivingLines.{{ $index }}.close_short" class="mt-0.5 rounded border-gray-300 dark:border-gray-700 text-indigo-600 focus:ring-indigo-500">
                                    <span>The rest won't arrive — close this line short and claim it</span>
                                </label>
                                @if ($line['close_short'])
                                    <input type="text" wire:model="receivingLines.{{ $index }}.short_reason" placeholder="Why won't it arrive? (e.g. supplier out of stock)" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @endif
                            @endif

                            @if ($over)
                                {{-- The warning is shown right under the fields, above. --}}
                            @else
                                @if ($badNow > 0)
                                    <p class="text-xs text-gray-700 dark:text-gray-300">{{ $fmt($badNow) }} {{ $line['selling_unit_name'] }} damaged — claim on the supplier: <strong>{{ number_format($badNow * $pieceCost, 2) }}</strong></p>
                                @endif
                                @if ($canWriteOff && $line['close_short'] && $leftAfter > 0)
                                    <p class="text-xs text-gray-700 dark:text-gray-300">{{ $fmt($leftAfter) }} {{ $line['selling_unit_name'] }} closed as missing — claim: <strong>{{ number_format($leftAfter * $pieceCost, 2) }}</strong></p>
                                @elseif ($leftAfter > 0)
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Still expected after this delivery: {{ $fmt($leftAfter) }} {{ $line['selling_unit_name'] }}</p>
                                @endif
                            @endif
                        </div>
                    </div>
                    @php
                        $lineCost = is_numeric($line['unit_cost']) && $line['conversion_qty'] > 0 ? (float) $line['unit_cost'] / $line['conversion_qty'] : null;
                        $linePrice = is_numeric($line['selling_price']) && (float) $line['selling_price'] > 0 ? (float) $line['selling_price'] : null;
                    @endphp
                    @if ($linePrice !== null && abs($linePrice - $line['current_price']) >= 0.005)
                        <p class="text-xs text-amber-700 dark:text-amber-300 mt-2">This changes the selling price from {{ number_format($line['current_price'], 2) }} to {{ number_format($linePrice, 2) }}.</p>
                    @endif
                    @if ($lineCost !== null && $linePrice !== null)
                        @if ($lineCost >= $linePrice)
                            <p class="text-xs text-red-600 dark:text-red-400 mt-1">Cost {{ number_format($lineCost, 2) }} per {{ $line['selling_unit_name'] }} is not below this price — no profit. It will still be received; raise the price or check the cost.</p>
                        @else
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Profit per {{ $line['selling_unit_name'] }}: {{ number_format($linePrice - $lineCost, 2) }} ({{ number_format(($linePrice - $lineCost) / $linePrice * 100, 1) }}% margin)</p>
                        @endif
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">Nothing left to receive on this order.</p>
            @endforelse
        </form>

        <x-slot name="footer">
            <x-secondary-button x-on:click="show = false">Cancel</x-secondary-button>
            <x-primary-button type="submit" form="receive-form">Receive</x-primary-button>
        </x-slot>
    </x-slide-over>

    <x-slide-over name="po-details" title="Purchase Order" max-width="2xl">
        @if ($details)
            @php
                $po = $details['po'];
                $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 3), '0'), '.') ?: '0';
                $orderedTotal = $po->lineItems->sum(fn ($l) => (float) $l->qty_ordered * (float) $l->cost_price);
                $receivedTotal = $details['batches']->sum(fn ($b) => (float) $b->qty_received * (float) $b->unit_cost);
            @endphp

            <div class="space-y-6">
                <div>
                    <div class="flex items-center justify-between gap-3">
                        <div class="font-mono text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $po->po_number }}</div>
                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ str($po->status)->headline() }}</span>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <div><dt class="text-xs text-gray-500 dark:text-gray-400">Supplier</dt><dd class="text-gray-900 dark:text-gray-100">{{ $po->supplier->name }}</dd></div>
                        <div><dt class="text-xs text-gray-500 dark:text-gray-400">Order date</dt><dd class="text-gray-900 dark:text-gray-100">{{ $po->order_date->format('Y-m-d') }}</dd></div>
                        <div><dt class="text-xs text-gray-500 dark:text-gray-400">Created by</dt><dd class="text-gray-900 dark:text-gray-100">{{ $po->creator->name }}</dd></div>
                        <div><dt class="text-xs text-gray-500 dark:text-gray-400">Approved</dt><dd class="text-gray-900 dark:text-gray-100">{{ $po->approved_at ? $po->approver?->name.' · '.$po->approved_at->format('Y-m-d') : '—' }}</dd></div>
                    </dl>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">Items ordered ({{ $po->lineItems->count() }})</h3>
                    <div class="rounded-lg ring-1 ring-gray-200 dark:ring-gray-700 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium">Product</th>
                                    <th class="px-3 py-2 text-right font-medium">Ordered</th>
                                    <th class="px-3 py-2 text-right font-medium">Received</th>
                                    <th class="px-3 py-2 text-right font-medium">Left</th>
                                    <th class="px-3 py-2 text-right font-medium">Cost</th>
                                    <th class="px-3 py-2 text-right font-medium">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700 tabular-nums text-gray-700 dark:text-gray-300">
                                @foreach ($po->lineItems as $item)
                                    <tr wire:key="po-detail-line-{{ $item->id }}">
                                        <td class="px-3 py-2 text-gray-900 dark:text-gray-100">
                                            {{ $item->product->name }}
                                            @if ((float) $item->qty_damaged > 0 || (float) $item->qty_closed_short > 0)
                                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                                    @if ((float) $item->qty_damaged > 0) {{ $qty($item->qty_damaged) }} damaged @endif
                                                    @if ((float) $item->qty_closed_short > 0) {{ (float) $item->qty_damaged > 0 ? '·' : '' }} {{ $qty($item->qty_closed_short) }} closed short @endif
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-right whitespace-nowrap">{{ $qty($item->qty_ordered) }} {{ $item->purchaseUnit->name }}</td>
                                        <td class="px-3 py-2 text-right whitespace-nowrap">{{ $qty($item->qty_received) }}</td>
                                        <td @class(['px-3 py-2 text-right whitespace-nowrap', 'text-amber-700 dark:text-amber-300' => $item->remainingQty() > 0 && $po->status !== 'draft'])>{{ $qty($item->remainingQty()) }}</td>
                                        <td class="px-3 py-2 text-right whitespace-nowrap">{{ number_format((float) $item->cost_price, 2) }}</td>
                                        <td class="px-3 py-2 text-right whitespace-nowrap">{{ number_format((float) $item->qty_ordered * (float) $item->cost_price, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-gray-50 dark:bg-gray-900/40 text-gray-900 dark:text-gray-100 font-semibold tabular-nums">
                                <tr>
                                    <td colspan="5" class="px-3 py-2 text-right">Order total</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">{{ number_format($orderedTotal, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Cost is per unit as ordered. What was actually paid at delivery is listed below.</p>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">Deliveries received</h3>
                    @if ($details['batches']->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-400">Nothing has been received against this order yet.</p>
                    @else
                        <div class="rounded-lg ring-1 ring-gray-200 dark:ring-gray-700 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs text-gray-500 dark:text-gray-400">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-medium">Date</th>
                                        <th class="px-3 py-2 text-left font-medium">Product</th>
                                        <th class="px-3 py-2 text-left font-medium">Batch</th>
                                        <th class="px-3 py-2 text-right font-medium">Qty</th>
                                        <th class="px-3 py-2 text-right font-medium">Cost paid</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700 tabular-nums text-gray-700 dark:text-gray-300">
                                    @foreach ($details['batches'] as $batch)
                                        <tr wire:key="po-detail-batch-{{ $batch->id }}">
                                            <td class="px-3 py-2 whitespace-nowrap">{{ $batch->received_date->format('Y-m-d') }}</td>
                                            <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $batch->product->name }}</td>
                                            <td class="px-3 py-2 whitespace-nowrap">{{ $batch->batch_code ?: '#'.$batch->id }}</td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap">{{ $qty($batch->qty_received) }} {{ $batch->product->sellingUnit->name }}</td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap">{{ number_format((float) $batch->unit_cost, 2) }} <span class="text-xs text-gray-400">/ {{ $batch->product->sellingUnit->name }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-gray-50 dark:bg-gray-900/40 text-gray-900 dark:text-gray-100 font-semibold tabular-nums">
                                    <tr>
                                        <td colspan="4" class="px-3 py-2 text-right">Received value at cost paid</td>
                                        <td class="px-3 py-2 text-right whitespace-nowrap">{{ number_format($receivedTotal, 2) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </div>

                @if ($details['issues']->isNotEmpty())
                    @php
                        $owedTotal = $details['issues']->sum(fn ($i) => $i->outstanding());
                        $creditedTotal = $details['issues']->sum(fn ($i) => (float) $i->credited_amount);
                    @endphp
                    <div>
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">Damaged &amp; missing — claims on {{ $po->supplier->name }}</h3>
                        <div class="rounded-lg ring-1 ring-gray-200 dark:ring-gray-700 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs text-gray-500 dark:text-gray-400">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-medium">Product</th>
                                        <th class="px-3 py-2 text-left font-medium">Issue</th>
                                        <th class="px-3 py-2 text-right font-medium">Qty</th>
                                        <th class="px-3 py-2 text-right font-medium">Claim</th>
                                        <th class="px-3 py-2 text-left font-medium">Status</th>
                                        <th class="px-3 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700 tabular-nums text-gray-700 dark:text-gray-300">
                                    @foreach ($details['issues'] as $issue)
                                        <tr wire:key="po-issue-{{ $issue->id }}">
                                            <td class="px-3 py-2 text-gray-900 dark:text-gray-100">
                                                {{ $issue->product->name }}
                                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $issue->reason }} · {{ $issue->created_at->format('Y-m-d') }}</div>
                                            </td>
                                            <td class="px-3 py-2">{{ ucfirst($issue->issue_type) }}</td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap">{{ $qty($issue->qty) }} {{ $issue->product->sellingUnit->name }}</td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap">{{ number_format((float) $issue->loss_value, 2) }}</td>
                                            <td class="px-3 py-2 whitespace-nowrap">
                                                @if ($issue->claim_status === 'owed')
                                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">Owed</span>
                                                @elseif ($issue->claim_status === 'credited')
                                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300" title="{{ $issue->resolution_note }}">Credited {{ number_format((float) $issue->credited_amount, 2) }}</span>
                                                @else
                                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300" title="{{ $issue->resolution_note }}">Waived</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                                @if ($issue->claim_status === 'owed' && $canWriteOff)
                                                    <button type="button" wire:click="openResolve({{ $issue->id }})" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 font-medium">Settle</button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-gray-50 dark:bg-gray-900/40 text-gray-900 dark:text-gray-100 font-semibold tabular-nums">
                                    <tr>
                                        <td colspan="3" class="px-3 py-2 text-right">Supplier still owes for this order</td>
                                        <td class="px-3 py-2 text-right whitespace-nowrap">{{ number_format($owedTotal, 2) }}</td>
                                        <td colspan="2" class="px-3 py-2 text-xs font-normal text-gray-500 dark:text-gray-400">{{ $creditedTotal > 0 ? number_format($creditedTotal, 2).' credited so far' : '' }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        <x-slot name="footer">
            <x-secondary-button x-on:click="show = false">Close</x-secondary-button>
        </x-slot>
    </x-slide-over>

    <x-slide-over name="resolve-claim" title="Settle Supplier Claim">
        @if ($resolvingIssue)
            <form wire:submit="submitResolve" id="resolve-claim-form" class="space-y-4">
                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 ring-1 ring-gray-200 dark:ring-gray-700 p-3 text-sm">
                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ $resolvingIssue->product->name }} — {{ $resolvingIssue->issue_type }}</div>
                    <div class="text-gray-600 dark:text-gray-400">{{ rtrim(rtrim(number_format((float) $resolvingIssue->qty, 3), '0'), '.') }} {{ $resolvingIssue->product->sellingUnit->name }} · claim <strong>{{ number_format((float) $resolvingIssue->loss_value, 2) }}</strong> against {{ $resolvingIssue->supplier->name }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $resolvingIssue->reason }}</div>
                </div>

                <div>
                    <x-input-label value="Outcome" />
                    <div class="mt-1 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                        <label class="flex items-center gap-2"><input type="radio" wire:model.live="resolveAction" value="credited" class="text-indigo-600 focus:ring-indigo-500"> The supplier credited / refunded it</label>
                        <label class="flex items-center gap-2"><input type="radio" wire:model.live="resolveAction" value="waived" class="text-indigo-600 focus:ring-indigo-500"> Waive it — we won't claim this</label>
                    </div>
                </div>

                @if ($resolveAction === 'credited')
                    <div>
                        <x-input-label for="resolve_amount" value="Amount credited" />
                        <x-text-input wire:model="resolveAmount" id="resolve_amount" class="block mt-1 w-full" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Up to {{ number_format((float) $resolvingIssue->loss_value, 2) }}. If they gave back less, enter what they actually gave — the claim is closed either way.</p>
                    </div>
                @endif

                <div>
                    <x-input-label for="resolve_note" :value="$resolveAction === 'waived' ? 'Why is it being waived?' : 'Note (optional)'" />
                    <textarea wire:model="resolveNote" id="resolve_note" rows="2" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                    <x-input-error :messages="$errors->get('resolveNote')" class="mt-2" />
                </div>
            </form>
        @endif

        <x-slot name="footer">
            <x-secondary-button x-on:click="show = false">Cancel</x-secondary-button>
            <x-primary-button type="submit" form="resolve-claim-form">Save</x-primary-button>
        </x-slot>
    </x-slide-over>
    <x-confirm-modal
        name="confirm-cancel-po"
        title="Confirm"
        message="Are you sure? Cancelling a purchase order is permanent — it stays in the list for history but can no longer be ordered or received."
        confirm-label="Confirm"
    >
        <x-danger-button wire:click="cancel">Confirm</x-danger-button>
    </x-confirm-modal>

    <x-confirm-modal
        name="confirm-delete-po"
        title="Confirm"
        message="Are you sure? This draft purchase order will be permanently deleted — this only works for drafts that were never sent to the supplier."
        confirm-label="Delete"
    >
        <x-danger-button wire:click="delete">Delete</x-danger-button>
    </x-confirm-modal>
</div>
