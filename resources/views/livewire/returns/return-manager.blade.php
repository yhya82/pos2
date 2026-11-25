@php
    $money = fn ($amount) => new \Illuminate\Support\HtmlString(
        '<span class="ccy">'.e($currencyCode).'</span> '.number_format($amount, 2)
    );
@endphp

<div class="returns-skin">
    @if ($mode === 'list')
        <div class="top">
            <div>
                <h1>Returns</h1>
                <p>Every return processed, store-wide</p>
            </div>
            @if (auth()->user()->hasPermission('returns', 'create'))
                <button type="button" wire:click="startProcessing" class="btn p">
                    <svg viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M12 5v14M5 12h14"/></svg>
                    Process Return
                </button>
            @endif
        </div>

        <div class="filters">
            <div class="search">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search return # or receipt #…">
            </div>
        </div>

        <div class="card">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Return #</th>
                        <th>Original Sale</th>
                        <th>Date</th>
                        <th>Processed By</th>
                        <th class="num">Refund</th>
                        <th>Status</th>
                        <th class="actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($returns as $return)
                        <tr wire:key="return-{{ $return->id }}">
                            <td class="mono">{{ $return->return_number }}</td>
                            <td class="mono mut">{{ $return->originalSale->receipt_number }}</td>
                            <td class="mut">{{ $return->created_at->format('Y-m-d h:i A') }}</td>
                            <td>{{ $return->processedBy->name }}</td>
                            <td class="num">{{ $money($return->refund_amount) }}</td>
                            <td>
                                <span @class(['status', 'ok' => $return->status === 'completed', 'plain' => $return->status !== 'completed'])>{{ ucfirst($return->status) }}</span>
                            </td>
                            <td class="actions">
                                <a href="{{ route('returns.receipt', $return) }}" target="_blank" class="actionbtn">Receipt</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="empty">No returns found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="pager">
                <span>Showing {{ $returns->firstItem() ?? 0 }}–{{ $returns->lastItem() ?? 0 }} of {{ $returns->total() }}</span>
                @if ($returns->hasPages())
                    <div class="pages">
                        @foreach ($returns->getUrlRange(max(1, $returns->currentPage() - 2), min($returns->lastPage(), $returns->currentPage() + 2)) as $page => $url)
                            <button type="button" wire:click="gotoPage({{ $page }})" aria-current="{{ $page === $returns->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($mode === 'process')
        @if ($foundSale)
            <div class="top">
                <div>
                    <h1>Process return</h1>
                    <p>
                        {{ $foundSale->receipt_number }} — {{ $foundSale->sale_date->format('Y-m-d h:i A') }}
                        @if ($foundSale->customer) &middot; {{ $foundSale->customer->name }} @endif
                    </p>
                </div>
                <button type="button" wire:click="cancelProcessing" class="btn back">
                    <svg viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M15 19l-7-7 7-7"/></svg>
                    Back to returns
                </button>
            </div>

            <div class="card" style="padding: 20px;">
                <form wire:submit="submitReturn">
                    @foreach ($returnLines as $index => $line)
                        <div class="refline" wire:key="return-line-{{ $index }}">
                            <div class="refline-top">
                                <span>{{ $line['product_name'] }}</span>
                                <span class="mut">
                                    {{ rtrim(rtrim($line['max_returnable'], '0'), '.') }} eligible &commat; {{ $money($line['unit_price']) }}
                                    @if ($line['already_returned'] > 0)
                                        &middot; <span class="alreadyret">{{ rtrim(rtrim($line['already_returned'], '0'), '.') }} already returned</span>
                                    @endif
                                </span>
                            </div>
                            <div class="refline-grid">
                                <div>
                                    <label>Qty to return</label>
                                    <input type="text" wire:model="returnLines.{{ $index }}.quantity">
                                </div>
                                <div>
                                    <label>Condition</label>
                                    <select wire:model="returnLines.{{ $index }}.condition_type">
                                        <option value="sellable">Sellable</option>
                                        <option value="damaged">Damaged</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <div class="f">
                        <label for="overall_reason">Overall Reason (required)</label>
                        <textarea wire:model="overallReason" id="overall_reason" rows="2"></textarea>
                        <x-input-error :messages="$errors->get('overallReason')" class="mt-2" />
                    </div>

                    <div class="formactions">
                        <button type="button" wire:click="cancelProcessing" class="btn">Cancel</button>
                        <button type="submit" class="btn p">Process Return</button>
                    </div>
                </form>
            </div>
        @else
            <div class="top">
                <div>
                    <h1>Process return</h1>
                    <p>Pick the sale to refund, only completed sales can be refunded.p</p>
                </div>
                <button type="button" wire:click="cancelProcessing" class="btn back">
                    <svg viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M15 19l-7-7 7-7"/></svg>
                    Back to returns
                </button>
            </div>

            <div class="filters">
                <div class="search">
                    <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input wire:model.live.debounce.300ms="saleSearch" type="search" placeholder="Search receipt # or customer…">
                </div>
            </div>

            @if ($saleSearchError)
                <div class="notice">{{ $saleSearchError }}</div>
            @endif

            <div class="card">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Receipt #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Cashier</th>
                            <th class="num">Total</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($returnableSales as $sale)
                            <tr wire:key="returnable-sale-{{ $sale->id }}">
                                <td class="mono">{{ $sale->receipt_number }}</td>
                                <td class="mut">{{ $sale->sale_date->format('Y-m-d h:i A') }}</td>
                                <td class="mut">{{ $sale->customer?->name ?? 'Walk-in' }}</td>
                                <td>{{ $sale->cashier->name }}</td>
                                <td class="num">{{ $money($sale->total_amount) }}</td>
                                <td class="actions">
                                    <button type="button" wire:click="selectSale({{ $sale->id }})" class="actionbtn refund">Refund</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="empty">No sales found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="pager">
                    <span>Showing {{ $returnableSales->firstItem() ?? 0 }}–{{ $returnableSales->lastItem() ?? 0 }} of {{ $returnableSales->total() }}</span>
                    @if ($returnableSales->hasPages())
                        <div class="pages">
                            @foreach ($returnableSales->getUrlRange(max(1, $returnableSales->currentPage() - 2), min($returnableSales->lastPage(), $returnableSales->currentPage() + 2)) as $page => $url)
                                <button type="button" wire:click="gotoPage({{ $page }}, 'salesPage')" aria-current="{{ $page === $returnableSales->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif
    @endif
</div>
