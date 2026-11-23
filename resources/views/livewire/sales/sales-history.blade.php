@php
    // Same idea as the Dashboard page's own money figures — the currency
    // code renders smaller than the number so the figure is still what
    // the eye lands on. Wrapped in HtmlString so {{ }} doesn't escape the
    // span away, same trick Blade's own e() helper already makes safe to
    // rely on.
    $money = fn ($amount) => new \Illuminate\Support\HtmlString(
        '<span class="ccy">'.e($currencyCode).'</span> '.number_format($amount, 2)
    );
@endphp

<div class="sales-skin">
    <div class="top">
        <div>
            <h1>Sales</h1>
            <p>Every completed, voided and refunded sale, store-wide</p>
        </div>
        @if (auth()->user()->hasPermission('sales', 'create'))
            <a href="{{ route('pos.index') }}" wire:navigate class="btn p">
                <svg viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M12 5v14M5 12h14"/></svg>
                Go to POS
            </a>
        @endif
    </div>

    <div class="filters">
        <div class="search">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search receipt # or customer…">
        </div>
        <div class="seg">
            @foreach (['' => 'All', 'completed' => 'Completed', 'voided' => 'Voided', 'refunded' => 'Refunded'] as $value => $label)
                <button type="button" wire:click="setStatusFilter('{{ $value }}')" aria-pressed="{{ $statusFilter === $value ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="daterange">
            <input type="date" wire:model.live="dateFrom">
            <span>to</span>
            <input type="date" wire:model.live="dateTo">
        </div>
    </div>

    <div class="card">
        <table class="tbl">
            <thead>
                <tr>
                    <th>Receipt #</th>
                    <th>Date</th>
                    <th>Cashier</th>
                    <th>Customer</th>
                    <th>Payment</th>
                    <th class="num">Total</th>
                    <th>Status</th>
                    <th class="actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sales as $sale)
                    <tr wire:key="sale-{{ $sale->id }}">
                        <td class="mono">{{ $sale->receipt_number }}</td>
                        <td class="mut">{{ $sale->sale_date->format('Y-m-d h:i') }} <span class="ampm">{{ $sale->sale_date->format('A') }}</span></td>
                        <td>{{ $sale->cashier->name }}</td>
                        <td class="mut">{{ $sale->customer?->name ?? 'Walk-in' }}</td>
                        <td>{{ $sale->payment?->paymentMethod->name }}</td>
                        <td class="num">{{ $money($sale->total_amount) }}</td>
                        <td>
                            <span @class([
                                'status',
                                'ok' => $sale->status === 'completed',
                                'acc' => $sale->status === 'voided',
                                'warn' => $sale->status === 'refunded',
                            ])>{{ ucfirst($sale->status) }}</span>
                            @if ($sale->status === 'refunded')
                                <div class="subnote">{{ rtrim(rtrim(number_format($sale->returned_line_items_sum_quantity ?? 0, 3), '0'), '.') }} of {{ rtrim(rtrim(number_format($sale->line_items_sum_quantity ?? 0, 3), '0'), '.') }} items returned</div>
                            @endif
                        </td>
                        <td class="actions">
                            <a href="{{ route('sales.receipt', $sale) }}" target="_blank" class="actionbtn">Receipt</a>
                            @if (in_array($sale->status, ['completed', 'refunded'], true) && auth()->user()->hasPermission('returns', 'create') && auth()->user()->canRefundSale($sale) && \App\Models\ModuleSetting::enabled('return_management'))
                                <button type="button" wire:click="$dispatch('confirm-refund', { saleId: {{ $sale->id }} })" class="actionbtn refund">Refund</button>
                            @endif
                            @if ($sale->status === 'completed' && auth()->user()->hasPermission('sales', 'update'))
                                <button type="button" wire:click="confirmVoid({{ $sale->id }})" class="actionbtn void">Void</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="empty">No sales found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="pager">
            <span>Showing {{ $sales->firstItem() ?? 0 }}–{{ $sales->lastItem() ?? 0 }} of {{ $sales->total() }}</span>
            @if ($sales->hasPages())
                <div class="pages">
                    @foreach ($sales->getUrlRange(max(1, $sales->currentPage() - 2), min($sales->lastPage(), $sales->currentPage() + 2)) as $page => $url)
                        <button type="button" wire:click="gotoPage({{ $page }})" aria-current="{{ $page === $sales->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <x-modal name="confirm-void-sale" max-width="sm">
        <form wire:submit="void" class="dialog">
            <div class="dh">
                <h2>Void sale</h2>
                <p>This permanently reverses the sale's inventory and, if it was a credit sale, the customer's balance. This cannot be undone.</p>
            </div>

            <div class="db">
                <label for="void_reason">Reason</label>
                <textarea wire:model="voidReason" id="void_reason" rows="2" placeholder="Why this sale is being voided"></textarea>
                <x-input-error :messages="$errors->get('voidReason')" class="mt-2" />
            </div>

            <div class="df">
                <button type="button" class="btn" x-on:click="$dispatch('close')">Cancel</button>
                <button type="submit" class="btn danger">Void sale</button>
            </div>
        </form>
    </x-modal>

    <livewire:sales.refund-modal />
</div>
