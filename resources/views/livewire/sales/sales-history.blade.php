<div>
    <div class="flex flex-wrap items-center gap-3 mb-4">
        <div class="w-full max-w-xs">
            <x-text-input wire:model.live.debounce.300ms="search" type="search" placeholder="Search receipt # or customer..." class="w-full" />
        </div>
        <select wire:model.live="statusFilter" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
            <option value="">All statuses</option>
            <option value="completed">Completed</option>
            <option value="voided">Voided</option>
            <option value="refunded">Refunded</option>
        </select>
        <input type="date" wire:model.live="dateFrom" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
        <span class="text-gray-400 text-sm">to</span>
        <input type="date" wire:model.live="dateTo" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
    </div>

    <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-900/40">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Receipt #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Cashier</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Customer</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Payment</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($sales as $sale)
                    <tr wire:key="sale-{{ $sale->id }}">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100 whitespace-nowrap font-mono">{{ $sale->receipt_number }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $sale->sale_date->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $sale->cashier->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $sale->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $sale->payment?->paymentMethod->name }}</td>
                        <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ number_format($sale->total_amount, 2) }}</td>
                        <td class="px-4 py-3 text-sm whitespace-nowrap">
                            <span @class([
                                'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $sale->status === 'completed',
                                'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' => $sale->status === 'voided',
                                'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => $sale->status === 'refunded',
                            ])>
                                {{ ucfirst($sale->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-right whitespace-nowrap space-x-3">
                            <a href="{{ route('sales.receipt', $sale) }}" target="_blank" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium">Receipt</a>
                            @if ($sale->status === 'completed' && auth()->user()->hasPermission('sales', 'update'))
                                <button wire:click="confirmVoid({{ $sale->id }})" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 font-medium">Void</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No sales found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
            {{ $sales->links() }}
        </div>
    </div>

    <x-modal name="confirm-void-sale" max-width="sm">
        <form wire:submit="void" class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Void Sale</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                This permanently reverses the sale's inventory and, if it was a credit sale, the customer's balance. This cannot be undone.
            </p>

            <div class="mt-4">
                <x-input-label for="void_reason" value="Reason" />
                <textarea wire:model="voidReason" id="void_reason" rows="2" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                <x-input-error :messages="$errors->get('voidReason')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button x-on:click="$dispatch('close')">Cancel</x-secondary-button>
                <x-danger-button type="submit">Void Sale</x-danger-button>
            </div>
        </form>
    </x-modal>
</div>
