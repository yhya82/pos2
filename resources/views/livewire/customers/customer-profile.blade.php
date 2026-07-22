<div>
    <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-4 mb-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $customer->name }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $customer->phone }} @if ($customer->email) · {{ $customer->email }} @endif</p>
                @if ($customer->address)
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $customer->address }}</p>
                @endif
            </div>

            @if ($creditModuleEnabled && $customer->credit_enabled)
                <div class="text-right">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Outstanding Balance</div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ number_format($customer->outstanding_balance, 2) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">of {{ number_format($customer->credit_limit, 2) }} limit</div>
                </div>
            @endif
        </div>
    </div>

    <div class="border-b border-gray-200 dark:border-gray-700 mb-4">
        <nav class="-mb-px flex gap-6">
            <button
                wire:click="setTab('purchases')"
                @class(['py-3 px-1 border-b-2 text-sm font-medium', 'border-indigo-500 text-indigo-600 dark:text-indigo-400' => $activeTab === 'purchases', 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400' => $activeTab !== 'purchases'])
            >Purchase History</button>
            @if ($creditModuleEnabled && $customer->credit_enabled)
                <button
                    wire:click="setTab('credit')"
                    @class(['py-3 px-1 border-b-2 text-sm font-medium', 'border-indigo-500 text-indigo-600 dark:text-indigo-400' => $activeTab === 'credit', 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400' => $activeTab !== 'credit'])
                >Credit Ledger</button>
            @endif
        </nav>
    </div>

    @if ($activeTab === 'purchases')
        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Receipt #</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Payment</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($sales as $sale)
                        <tr wire:key="sale-{{ $sale->id }}">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100 font-mono whitespace-nowrap">
                                <a href="{{ route('sales.receipt', $sale) }}" target="_blank" class="hover:text-indigo-600 dark:hover:text-indigo-400">{{ $sale->receipt_number }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $sale->sale_date->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $sale->payment?->paymentMethod->name }}</td>
                            <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ number_format($sale->total_amount, 2) }}</td>
                            <td class="px-4 py-3 text-sm whitespace-nowrap">
                                <span @class([
                                    'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $sale->status === 'completed',
                                    'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' => $sale->status === 'voided',
                                    'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => $sale->status === 'refunded',
                                ])>{{ ucfirst($sale->status) }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No purchases yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">{{ $sales->links() }}</div>
        </div>
    @endif

    @if ($activeTab === 'credit')
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Type</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Amount</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Balance After</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($creditTransactions as $transaction)
                            <tr wire:key="credit-{{ $transaction->id }}">
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3 text-sm whitespace-nowrap">
                                    <span @class([
                                        'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                        'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' => $transaction->type === 'credit_sale',
                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $transaction->type === 'payment',
                                    ])>{{ $transaction->type === 'credit_sale' ? 'Charge' : 'Payment' }}</span>
                                    @if ($transaction->sale)
                                        <a href="{{ route('sales.receipt', $transaction->sale) }}" target="_blank" class="ml-1 text-xs text-gray-400 hover:text-indigo-600">{{ $transaction->sale->receipt_number }}</a>
                                    @endif
                                </td>
                                <td @class(['px-4 py-3 text-sm text-right tabular-nums whitespace-nowrap', 'text-red-600' => $transaction->type === 'credit_sale', 'text-emerald-600' => $transaction->type === 'payment'])>
                                    {{ $transaction->type === 'credit_sale' ? '+' : '-' }}{{ number_format($transaction->amount, 2) }}
                                </td>
                                <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ number_format($transaction->balance_after, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No credit activity yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">{{ $creditTransactions->links() }}</div>
            </div>

            @if (auth()->user()->hasPermission('customers', 'update'))
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-4 space-y-3 self-start">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-100">Record Payment</h3>
                    <form wire:submit="recordPayment" class="space-y-3">
                        <div>
                            <x-input-label for="payment_amount" value="Amount" />
                            <x-text-input wire:model="paymentAmount" id="payment_amount" class="block mt-1 w-full" />
                            <x-input-error :messages="$errors->get('paymentAmount')" class="mt-2" />
                        </div>
                        <x-primary-button type="submit" class="w-full justify-center">Record Payment</x-primary-button>
                    </form>
                </div>
            @endif
        </div>
    @endif
</div>
