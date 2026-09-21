<div>
    @if ($mode === 'list')
        <div class="flex items-center justify-between gap-4 mb-4">
            <div class="w-full max-w-xs">
                <x-text-input wire:model.live.debounce.300ms="search" type="search" placeholder="Search return # or receipt #..." class="w-full" />
            </div>

            @if (auth()->user()->hasPermission('returns', 'create'))
                <x-primary-button wire:click="startProcessing">
                    Process Return
                </x-primary-button>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl ring-1 ring-gray-900/5 dark:ring-white/10 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Return #</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Original Sale</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Processed By</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Refund</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($returns as $return)
                        <tr wire:key="return-{{ $return->id }}">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100 font-mono whitespace-nowrap">{{ $return->return_number }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 font-mono whitespace-nowrap">{{ $return->originalSale->receipt_number }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $return->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $return->processedBy->name }}</td>
                            <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ number_format($return->refund_amount, 2) }}</td>
                            <td class="px-4 py-3 text-sm whitespace-nowrap">
                                <span @class([
                                    'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $return->status === 'completed',
                                    'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $return->status !== 'completed',
                                ])>{{ ucfirst($return->status) }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-right whitespace-nowrap">
                                <a href="{{ route('returns.receipt', $return) }}" target="_blank" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium">Receipt</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No returns found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                {{ $returns->links() }}
            </div>
        </div>
    @endif

    @if ($mode === 'process')
        @if ($foundSale)
            <div class="max-w-2xl space-y-4">
                <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl ring-1 ring-gray-900/5 dark:ring-white/10 p-4">
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        {{ $foundSale->receipt_number }} — {{ $foundSale->sale_date->format('Y-m-d H:i') }}
                        @if ($foundSale->customer) · {{ $foundSale->customer->name }} @endif
                    </p>

                    <form wire:submit="submitReturn" class="space-y-4">
                        @foreach ($returnLines as $index => $line)
                            <div class="border border-gray-200 dark:border-gray-700 rounded-md p-3" wire:key="return-line-{{ $index }}">
                                <div class="flex items-center justify-between text-sm font-medium text-gray-800 dark:text-gray-100">
                                    <span>{{ $line['product_name'] }}</span>
                                    <span class="text-gray-500 dark:text-gray-400 font-normal">{{ rtrim(rtrim($line['max_returnable'], '0'), '.') }} eligible @ {{ number_format($line['unit_price'], 2) }}</span>
                                </div>

                                <div class="grid grid-cols-3 gap-2 mt-2">
                                    <div>
                                        <x-input-label value="Qty to return" class="text-xs" />
                                        <input type="text" wire:model="returnLines.{{ $index }}.quantity" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                    </div>
                                    <div>
                                        <x-input-label value="Condition" class="text-xs" />
                                        <select wire:model="returnLines.{{ $index }}.condition_type" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                            <option value="sellable">Sellable</option>
                                            <option value="damaged">Damaged</option>
                                        </select>
                                    </div>
                                    <div>
                                        <x-input-label value="Reason" class="text-xs" />
                                        <input type="text" wire:model="returnLines.{{ $index }}.reason" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        <div>
                            <x-input-label for="overall_reason" value="Overall Reason (required)" />
                            <textarea wire:model="overallReason" id="overall_reason" rows="2" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                            <x-input-error :messages="$errors->get('overallReason')" class="mt-2" />
                        </div>

                        <div class="flex justify-end gap-3">
                            <x-secondary-button type="button" wire:click="cancelProcessing">Cancel</x-secondary-button>
                            <x-primary-button type="submit">Process Return</x-primary-button>
                        </div>
                    </form>
                </div>
            </div>
        @else
            <div class="flex items-center justify-between gap-4 mb-4">
                <div class="w-full max-w-xs">
                    <x-text-input wire:model.live.debounce.300ms="saleSearch" type="search" placeholder="Search receipt # or customer..." class="w-full" />
                </div>
                <x-secondary-button wire:click="cancelProcessing">Back to Returns</x-secondary-button>
            </div>

            @if ($saleSearchError)
                <p class="text-sm text-red-600 mb-4">{{ $saleSearchError }}</p>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl ring-1 ring-gray-900/5 dark:ring-white/10 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Receipt #</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Customer</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Cashier</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($returnableSales as $sale)
                            <tr wire:key="returnable-sale-{{ $sale->id }}">
                                <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100 font-mono whitespace-nowrap">{{ $sale->receipt_number }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $sale->sale_date->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $sale->customer?->name ?? 'Walk-in' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $sale->cashier->name }}</td>
                                <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ number_format($sale->total_amount, 2) }}</td>
                                <td class="px-4 py-3 text-sm text-right whitespace-nowrap">
                                    <button type="button" wire:click="selectSale({{ $sale->id }})" class="text-amber-600 hover:text-amber-800 dark:text-amber-400 dark:hover:text-amber-300 font-medium">Refund</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No sales found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                    {{ $returnableSales->links() }}
                </div>
            </div>
        @endif
    @endif
</div>
