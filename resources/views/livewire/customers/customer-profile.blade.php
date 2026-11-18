@php
    $money = fn ($amount) => new \Illuminate\Support\HtmlString(
        '<span class="ccy">'.e($currencyCode).'</span> '.number_format($amount, 2)
    );
@endphp

<div class="customers-skin">
    <div class="profile-head">
        <div>
            <h2>{{ $customer->name }}</h2>
            <div class="contact">
                @if ($customer->phone)
                    <div>
                        <span class="icon"><svg viewBox="0 0 24 24"><path d="M2.25 6.75c0 8.284 6.716 15 15 15h.75a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg></span>
                        {{ $customer->formattedPhone() }}
                    </div>
                @endif
                @if ($customer->email)
                    <div>
                        <span class="icon"><svg viewBox="0 0 24 24"><path d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg></span>
                        {{ $customer->email }}
                    </div>
                @endif
                @if ($customer->address)
                    <div>
                        <span class="icon"><svg viewBox="0 0 24 24"><path d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg></span>
                        {{ $customer->address }}
                    </div>
                @endif
            </div>
        </div>

        @if ($creditModuleEnabled && $customer->credit_enabled)
            @php
                $limit = (float) $customer->credit_limit;
                $balance = (float) $customer->outstanding_balance;
                $usedPct = $limit > 0 ? min(100, ($balance / $limit) * 100) : ($balance > 0 ? 100 : 0);
            @endphp
            <div class="bal">
                <div class="lbl">Outstanding balance</div>
                <div class="amt">{{ $money($balance) }}</div>
                <div @class(['bar', 'over' => $balance >= $limit && $limit > 0, 'near' => $limit > 0 && $balance < $limit && $usedPct >= 85])>
                    <div style="width: {{ $usedPct }}%"></div>
                </div>
                <div class="cap">of <span class="cap-amt">{{ $money($limit) }}</span> limit</div>
            </div>
        @endif
    </div>

    <div class="tabs">
        <button type="button" wire:click="setTab('purchases')" aria-selected="{{ $activeTab === 'purchases' ? 'true' : 'false' }}">Purchase History</button>
        @if ($creditModuleEnabled && $customer->credit_enabled)
            <button type="button" wire:click="setTab('credit')" aria-selected="{{ $activeTab === 'credit' ? 'true' : 'false' }}">Credit Ledger</button>
        @endif
    </div>

    @if ($activeTab === 'purchases')
        <div class="card">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Date</th>
                        <th>Payment</th>
                        <th class="num">Total</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sales as $sale)
                        <tr wire:key="sale-{{ $sale->id }}">
                            <td class="mono"><a href="{{ route('sales.receipt', $sale) }}" target="_blank" class="link">{{ $sale->receipt_number }}</a></td>
                            <td class="mut">{{ $sale->sale_date->format('Y-m-d H:i') }}</td>
                            <td>{{ $sale->payment?->paymentMethod->name }}</td>
                            <td class="num">{{ $money($sale->total_amount) }}</td>
                            <td>
                                <span @class([
                                    'status',
                                    'ok' => $sale->status === 'completed',
                                    'acc' => $sale->status === 'voided',
                                    'warn' => $sale->status === 'refunded',
                                ])>{{ ucfirst($sale->status) }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty">No purchases yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="pager">
                <span>Showing {{ $sales->firstItem() ?? 0 }}–{{ $sales->lastItem() ?? 0 }} of {{ $sales->total() }}</span>
                @if ($sales->hasPages())
                    <div class="pages">
                        @foreach ($sales->getUrlRange(max(1, $sales->currentPage() - 2), min($sales->lastPage(), $sales->currentPage() + 2)) as $page => $url)
                            <button type="button" wire:click="gotoPage({{ $page }}, 'salesPage')" aria-current="{{ $page === $sales->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($activeTab === 'credit')
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px; align-items: start">
            <div class="card">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th class="num">Amount</th>
                            <th class="num">Balance After</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($creditTransactions as $transaction)
                            <tr wire:key="credit-{{ $transaction->id }}">
                                <td class="mut">{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                                <td>
                                    <span @class(['status', 'acc' => $transaction->type === 'credit_sale', 'ok' => $transaction->type === 'payment'])>{{ $transaction->type === 'credit_sale' ? 'Charge' : 'Payment' }}</span>
                                    @if ($transaction->sale)
                                        <a href="{{ route('sales.receipt', $transaction->sale) }}" target="_blank" class="mono mut" style="font-size: 12px; margin-left: 4px;">{{ $transaction->sale->receipt_number }}</a>
                                    @endif
                                </td>
                                <td class="num" style="color: {{ $transaction->type === 'credit_sale' ? 'var(--acc)' : 'var(--ok)' }}">
                                    <span class="ccy">{{ $currencyCode }}</span> {{ $transaction->type === 'credit_sale' ? '+' : '-' }}{{ number_format($transaction->amount, 2) }}
                                </td>
                                <td class="num mut">{{ $money($transaction->balance_after) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="empty">No credit activity yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="pager">
                    <span>Showing {{ $creditTransactions->firstItem() ?? 0 }}–{{ $creditTransactions->lastItem() ?? 0 }} of {{ $creditTransactions->total() }}</span>
                    @if ($creditTransactions->hasPages())
                        <div class="pages">
                            @foreach ($creditTransactions->getUrlRange(max(1, $creditTransactions->currentPage() - 2), min($creditTransactions->lastPage(), $creditTransactions->currentPage() + 2)) as $page => $url)
                                <button type="button" wire:click="gotoPage({{ $page }}, 'creditPage')" aria-current="{{ $page === $creditTransactions->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            @if (auth()->user()->hasPermission('customers', 'update'))
                <div class="credit-panel">
                    <h3>Record payment</h3>
                    <form wire:submit="recordPayment">
                        <div class="f">
                            <label for="payment_amount">Amount</label>
                            <input type="text" wire:model="paymentAmount" id="payment_amount">
                            <x-input-error :messages="$errors->get('paymentAmount')" class="mt-2" />
                        </div>
                        <button type="submit" class="btn p" style="width: 100%; justify-content: center;">Record payment</button>
                    </form>
                </div>
            @endif
        </div>
    @endif
</div>
