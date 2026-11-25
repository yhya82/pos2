@php
    $money = fn ($amount) => new \Illuminate\Support\HtmlString(
        '<span class="ccy">'.e($currencyCode).'</span> '.number_format($amount, 2)
    );
    $seeFinancials = auth()->user()->canSeeFinancials();
@endphp

<div class="products-skin">
    <div class="profile-head">
        @if (auth()->user()->hasPermission('products', 'update'))
            <button type="button" wire:click="openEditForm" class="editbtn" aria-label="Edit product" title="Edit product">
                <svg viewBox="0 0 24 24"><path d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/><path d="M15.75 6.75l2.5 2.5"/></svg>
            </button>
        @endif

        <div class="photo">
            @if ($product->imageUrl())
                <img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}">
            @else
                <svg viewBox="0 0 24 24"><path d="M21 7.5v9a1.5 1.5 0 01-.75 1.3l-7.5 4.33a1.5 1.5 0 01-1.5 0l-7.5-4.33A1.5 1.5 0 013 16.5v-9a1.5 1.5 0 01.75-1.3l7.5-4.33a1.5 1.5 0 011.5 0l7.5 4.33A1.5 1.5 0 0121 7.5z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/></svg>
            @endif
        </div>

        <div class="main">
            <div class="nm">
                <h2>{{ $product->name }}</h2>
                <span @class(['status', 'ok' => $product->status === 'active', 'plain' => $product->status !== 'active'])>{{ ucfirst($product->status) }}</span>
            </div>
            <div class="meta">
                <span class="row">
                    <span class="icon"><svg viewBox="0 0 24 24"><path d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z"/><path d="M6 6h.008v.008H6V6z"/></svg></span>
                    {{ $product->category?->name ?? 'Uncategorized' }}
                </span>
                @if ($product->supplier)
                    <span class="row">
                        <span class="icon"><svg viewBox="0 0 24 24"><path d="M3 16.5V6.75A1.5 1.5 0 014.5 5.25h8.25v11.25H3z"/><path d="M12.75 9.75H18l3 3.75v3h-8.25z"/><circle cx="7.5" cy="18" r="1.5"/><circle cx="17.25" cy="18" r="1.5"/></svg></span>
                        {{ $product->supplier->name }}
                    </span>
                @endif
                @if ($product->barcode)
                    <span class="row">
                        <span class="icon"><svg viewBox="0 0 24 24"><path d="M4 6v12M7.5 6v12M11 6v12M14.5 6v12M18 6v12M21 6v12"/></svg></span>
                        <span class="mono">{{ $product->barcode }}</span>
                    </span>
                @endif
            </div>
            @if ($product->description)
                <p class="desc">{{ $product->description }}</p>
            @endif

            <div class="acts">
                @if (auth()->user()->hasPermission('inventory', 'update'))
                    <button type="button" wire:click="openAddStockForm" class="btn sm add">
                        <svg viewBox="0 0 24 24" style="width:14px;height:14px"><path d="M12 5v14M5 12h14"/></svg>
                        Add Stock
                    </button>
                    <button type="button" wire:click="openAdjustForm('correction_add')" class="btn sm adjust">
                        <svg viewBox="0 0 24 24" style="width:14px;height:14px"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
                        Adjust Stock
                    </button>
                @endif
                @if (auth()->user()->hasPermission('discounts', 'update'))
                    <button type="button" wire:click="openDiscountForm" class="btn sm discount">
                        <svg viewBox="0 0 24 24" style="width:14px;height:14px"><path d="M20 12a8 8 0 11-16 0 8 8 0 0116 0z"/><path d="M9.5 9.5h.01M14.5 14.5h.01M15 9l-6 6"/></svg>
                        {{ $product->hasActivePromo() ? 'Edit Discount' : 'Set Discount' }}
                    </button>
                @endif
            </div>
        </div>

        <div class="figs">
            <div>
                <div class="lbl">Stock on Hand</div>
                <div class="big">
                    <span @class(['stockval', 'low' => $stockOnHand <= (float) $product->min_stock_level])>{{ rtrim(rtrim(number_format($stockOnHand, 3), '0'), '.') ?: '0' }}</span>
                    {{ $product->sellingUnit->name }}
                </div>
            </div>
            <div>
                <div class="lbl">Selling Price</div>
                @if ($product->hasActivePromo())
                    <div class="promo-old">{{ number_format($product->selling_price, 2) }}</div>
                    <div class="big promo-new">{{ $money($product->effectiveSellingPrice()) }}</div>
                    <div class="promo-tag">
                        {{ $product->promo_discount_type === 'percentage' ? rtrim(rtrim(number_format($product->promo_discount_value, 2), '0'), '.').'% off' : number_format($product->promo_discount_value, 2).' off' }}
                        @if ($product->promo_ends_at) &middot; until {{ $product->promo_ends_at->format('Y-m-d') }} @endif
                    </div>
                @else
                    <div class="big">{{ $money($product->selling_price) }}</div>
                @endif
            </div>
        </div>
    </div>

    <div class="tabs">
        @foreach (['overview' => 'Overview', 'inventory' => 'Inventory & Batches', 'movements' => 'Stock Movements', 'discounts' => 'Discount History'] as $tab => $label)
            <button type="button" wire:click="setTab('{{ $tab }}')" aria-selected="{{ $activeTab === $tab ? 'true' : 'false' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($activeTab === 'overview')
        @php $overviewHasDistinctUnits = $product->purchase_unit_id !== $product->selling_unit_id; @endphp
        <div class="statgrid">
            @if ($seeFinancials)
                <div class="statcard">
                    <div class="hd"><span class="ic"><svg viewBox="0 0 24 24"><rect x="2.25" y="6.75" width="19.5" height="10.5" rx="1.5"/><circle cx="12" cy="12" r="2.25"/></svg></span><span class="lbl">Cost Price</span></div>
                    <div class="val">{{ $money($product->cost_price) }} per {{ $product->sellingUnit->name }}</div>
                </div>
                @if ($overviewHasDistinctUnits)
                    <div class="statcard">
                        <div class="hd"><span class="ic"><svg viewBox="0 0 24 24"><rect x="2.25" y="6.75" width="19.5" height="10.5" rx="1.5"/><circle cx="12" cy="12" r="2.25"/></svg></span><span class="lbl">&asymp; Cost per {{ $product->purchaseUnit->name }}</span></div>
                        <div class="val">{{ $money($product->toPurchaseUnitCost((float) $product->cost_price, 'selling')) }}</div>
                    </div>
                @endif
            @endif
            <div class="statcard">
                <div class="hd"><span class="ic"><svg viewBox="0 0 24 24"><path d="M21 7.5v9a1.5 1.5 0 01-.75 1.3l-7.5 4.33a1.5 1.5 0 01-1.5 0l-7.5-4.33A1.5 1.5 0 013 16.5v-9a1.5 1.5 0 01.75-1.3l7.5-4.33a1.5 1.5 0 011.5 0l7.5 4.33A1.5 1.5 0 0121 7.5z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/></svg></span><span class="lbl">Purchase Unit</span></div>
                <div class="val">{{ $product->purchaseUnit->name }}</div>
            </div>
            <div class="statcard">
                <div class="hd"><span class="ic"><svg viewBox="0 0 24 24"><path d="M21 7.5v9a1.5 1.5 0 01-.75 1.3l-7.5 4.33a1.5 1.5 0 01-1.5 0l-7.5-4.33A1.5 1.5 0 013 16.5v-9a1.5 1.5 0 01.75-1.3l7.5-4.33a1.5 1.5 0 011.5 0l7.5 4.33A1.5 1.5 0 0121 7.5z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/></svg></span><span class="lbl">Selling Unit</span></div>
                <div class="val">{{ $product->sellingUnit->name }}</div>
            </div>
            <div class="statcard">
                <div class="hd"><span class="ic"><svg viewBox="0 0 24 24"><path d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5M16.5 3L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg></span><span class="lbl">Conversion</span></div>
                <div class="val">1 {{ $product->purchaseUnit->name }} = {{ rtrim(rtrim(number_format($product->conversion_qty, 3), '0'), '.') }} {{ $product->sellingUnit->name }}</div>
            </div>
            <div class="statcard">
                <div class="hd"><span class="ic"><svg viewBox="0 0 24 24"><path d="M3 7.5h18M3 7.5v9A2.25 2.25 0 005.25 18.75h13.5A2.25 2.25 0 0021 16.5v-9M3 7.5l2.25-4.5h13.5L21 7.5M9.75 11.25h4.5"/></svg></span><span class="lbl">Minimum Stock Level</span></div>
                <div class="val">{{ (rtrim(rtrim(number_format($product->min_stock_level, 3), '0'), '.') ?: '0') }} {{ $product->sellingUnit->name }}</div>
            </div>
            <div class="statcard">
                <div class="hd"><span @class(['ic', 'ok' => $product->hasActivePromo()])><svg viewBox="0 0 24 24"><path d="M20 12a8 8 0 11-16 0 8 8 0 0116 0z"/><path d="M9.5 9.5h.01M14.5 14.5h.01M15 9l-6 6"/></svg></span><span class="lbl">Promotional Discount</span></div>
                <div class="val" @if ($product->hasActivePromo()) style="color:var(--ok)" @endif>
                    {{ $product->hasActivePromo() ? ($product->promo_discount_type === 'percentage' ? rtrim(rtrim(number_format($product->promo_discount_value, 2), '0'), '.').'% off' : number_format($product->promo_discount_value, 2).' off') : 'None' }}
                </div>
            </div>
            <div class="statcard">
                <div class="hd"><span class="ic"><svg viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="16.5" rx="1.5"/><path d="M3 9h18M8 3v3M16 3v3"/></svg></span><span class="lbl">Created</span></div>
                <div class="val">{{ $product->created_at->format('Y-m-d') }}</div>
            </div>
        </div>
    @endif

    @if ($activeTab === 'inventory')
        @php $batchActions = auth()->user()->hasPermission('inventory', 'update') && $canEditPrices; @endphp
        @if ($seeFinancials && $product->batches()->where('status', 'active')->where('qty_remaining', '>', 0)->where('unit_cost', '>=', $product->effectiveSellingPrice())->exists())
            <div class="notice">Some stock here cost as much as or more than it sells for ({{ $money($product->effectiveSellingPrice()) }}). If a batch cost was entered wrongly, use <strong>Correct cost</strong> on that batch; otherwise reprice the product.</div>
        @endif
        @if ($seeFinancials && $product->hasActivePromo())
            <p style="color:var(--ok); font-size:12.5px; margin: 0 0 10px;">Profit is calculated at the promotional price ({{ $money($product->effectiveSellingPrice()) }}), not the list price ({{ $money($product->selling_price) }}).</p>
        @endif
        <div class="card">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th class="num">Received ({{ $product->sellingUnit->name }})</th>
                        <th class="num">Remaining ({{ $product->sellingUnit->name }})</th>
                        @if ($seeFinancials)
                            <th class="num">Unit Cost</th>
                            <th class="num">Profit</th>
                            <th class="num">Est. Profit (remaining)</th>
                        @endif
                        <th>Received Date</th>
                        <th>Expiry</th>
                        <th>Status</th>
                        @if ($batchActions)
                            <th class="actions">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($batches as $batch)
                        @php
                            $batchCorrections = $corrections[$batch->id] ?? collect();
                            $last = $batchCorrections->first();
                            $unitProfit = $product->effectiveSellingPrice() - (float) $batch->unit_cost;
                        @endphp
                        <tr wire:key="batch-{{ $batch->id }}">
                            <td class="mono">
                                {{ $batch->batch_code ?? '#'.$batch->id }}
                                @if ($last && $seeFinancials)
                                    <span class="tag" title="Cost corrected {{ $batchCorrections->count() > 1 ? $batchCorrections->count().' times. Last: ' : ': ' }}{{ number_format($last->previous_value['unit_cost'], 2) }} → {{ number_format($last->new_value['unit_cost'], 2) }} on {{ $last->created_at->format('Y-m-d') }} by {{ $last->user?->name ?? 'System' }}{{ ! empty($last->new_value['reason']) ? ' — '.$last->new_value['reason'] : '' }}">corrected</span>
                                @endif
                            </td>
                            <td class="num mut">{{ rtrim(rtrim(number_format($batch->qty_received, 3), '0'), '.') }}</td>
                            <td class="num">{{ rtrim(rtrim(number_format($batch->qty_remaining, 3), '0'), '.') ?: '0' }}</td>
                            @if ($seeFinancials)
                                <td class="num">{{ $money($batch->unit_cost) }}</td>
                                <td class="num" style="color: {{ $unitProfit >= 0 ? 'var(--ok)' : 'var(--acc)' }}">{{ $money($unitProfit) }}</td>
                                <td class="num" style="color: {{ $unitProfit >= 0 ? 'var(--ink)' : 'var(--acc)' }}">{{ $batch->status === 'active' ? $money((float) $batch->qty_remaining * $unitProfit) : '—' }}</td>
                            @endif
                            <td class="mut">{{ $batch->received_date->format('Y-m-d') }}</td>
                            <td class="mut">{{ $batch->expiry_date?->format('Y-m-d') ?? '—' }}</td>
                            <td>
                                <span @class([
                                    'status',
                                    'ok' => $batch->status === 'active',
                                    'plain' => $batch->status === 'depleted',
                                    'warn' => $batch->status === 'expired',
                                    'acc' => $batch->status === 'written_off',
                                ])>{{ ucfirst(str_replace('_', ' ', $batch->status)) }}</span>
                            </td>
                            @if ($batchActions)
                                <td class="actions">
                                    <button type="button" wire:click="openCostCorrection({{ $batch->id }})" class="actionbtn">Correct cost</button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ 6 + ($seeFinancials ? 3 : 0) + ($batchActions ? 1 : 0) }}" class="empty">No batches received yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="pager">
                <span>Showing {{ $batches->firstItem() ?? 0 }}–{{ $batches->lastItem() ?? 0 }} of {{ $batches->total() }}</span>
                @if ($batches->hasPages())
                    <div class="pages">
                        @foreach ($batches->getUrlRange(max(1, $batches->currentPage() - 2), min($batches->lastPage(), $batches->currentPage() + 2)) as $page => $url)
                            <button type="button" wire:click="gotoPage({{ $page }}, 'batchesPage')" aria-current="{{ $page === $batches->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($activeTab === 'movements')
        <div class="card">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th class="num">Qty</th>
                        <th class="num">Before &rarr; After</th>
                        <th>Reason</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($movements as $movement)
                        <tr wire:key="movement-{{ $movement->id }}">
                            <td class="mut">{{ $movement->created_at->format('Y-m-d h:i A') }}</td>
                            <td>
                                <span @class([
                                    'status',
                                    'ok' => in_array($movement->movement_type, ['stock_received', 'return']),
                                    'acc' => $movement->movement_type === 'damaged',
                                    'warn' => $movement->movement_type === 'expired',
                                    'plain' => in_array($movement->movement_type, ['sale', 'adjustment']),
                                ])>{{ ucfirst(str_replace('_', ' ', $movement->movement_type)) }}</span>
                            </td>
                            <td class="num" style="color: {{ $movement->quantity > 0 ? 'var(--ok)' : 'var(--acc)' }}">
                                {{ $movement->quantity > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($movement->quantity, 3), '0'), '.') }}
                            </td>
                            <td class="num mut">{{ rtrim(rtrim(number_format($movement->previous_qty, 3), '0'), '.') ?: '0' }} &rarr; {{ rtrim(rtrim(number_format($movement->new_qty, 3), '0'), '.') ?: '0' }}</td>
                            <td class="mut">{{ $movement->reason ?? '—' }}</td>
                            <td>{{ $movement->user?->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty">No stock movements recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="pager">
                <span>Showing {{ $movements->firstItem() ?? 0 }}–{{ $movements->lastItem() ?? 0 }} of {{ $movements->total() }}</span>
                @if ($movements->hasPages())
                    <div class="pages">
                        @foreach ($movements->getUrlRange(max(1, $movements->currentPage() - 2), min($movements->lastPage(), $movements->currentPage() + 2)) as $page => $url)
                            <button type="button" wire:click="gotoPage({{ $page }}, 'movementsPage')" aria-current="{{ $page === $movements->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($activeTab === 'discounts')
        <div class="card">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th>Date</th>
                        <th class="num">Qty</th>
                        <th class="num">Unit Price</th>
                        <th class="num">Discount</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($discountedLines as $line)
                        <tr wire:key="discount-{{ $line->id }}">
                            <td class="mono"><a href="{{ route('sales.receipt', $line->sale) }}" target="_blank" class="link">{{ $line->sale->receipt_number }}</a></td>
                            <td class="mut">{{ $line->created_at->format('Y-m-d h:i A') }}</td>
                            <td class="num mut">{{ rtrim(rtrim(number_format($line->quantity, 3), '0'), '.') }}</td>
                            <td class="num mut">{{ $money($line->unit_price) }}</td>
                            <td class="num" style="color: var(--acc)">
                                -{{ $money($line->line_discount_amount) }}
                                @if ($line->line_discount_type === 'percentage')
                                    <span class="mut" style="font-size:11px;">({{ rtrim(rtrim(number_format($line->line_discount_amount / max($line->unit_price * $line->quantity, 0.01) * 100, 1), '0'), '.') }}%)</span>
                                @endif
                            </td>
                            <td class="mut">{{ $line->line_discount_reason ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty">No discounted sales for this product yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="pager">
                <span>Showing {{ $discountedLines->firstItem() ?? 0 }}–{{ $discountedLines->lastItem() ?? 0 }} of {{ $discountedLines->total() }}</span>
                @if ($discountedLines->hasPages())
                    <div class="pages">
                        @foreach ($discountedLines->getUrlRange(max(1, $discountedLines->currentPage() - 2), min($discountedLines->lastPage(), $discountedLines->currentPage() + 2)) as $page => $url)
                            <button type="button" wire:click="gotoPage({{ $page }}, 'discountsPage')" aria-current="{{ $page === $discountedLines->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ----------------------------------------------------- Add Stock --}}
    @if ($showAddStockForm)
        @php
            $hasDistinctUnits = $product->purchase_unit_id !== $product->selling_unit_id;
            $addStockUnitLabel = $hasDistinctUnits
                ? ($addStockQtyUnit === 'purchase' ? $product->purchaseUnit->name : $product->sellingUnit->name)
                : $product->sellingUnit->name;
            $asSellingCost = is_numeric($addStockUnitCost) ? $product->toSellingUnitCost((float) $addStockUnitCost, $addStockQtyUnit) : null;
            $newPrice = is_numeric($addStockSellingPrice) && (float) $addStockSellingPrice > 0 ? (float) $addStockSellingPrice : null;
            $priceIsChanging = $newPrice !== null && abs($newPrice - (float) $product->selling_price) >= 0.005;
        @endphp
        <div class="overlay" wire:click="cancelAddStockForm"></div>
        <div class="modal">
            <div class="mh">
                <div class="hd">
                    <span class="ic add"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span>
                    <h2>Add Stock</h2>
                </div>
                <p>Creates a new batch directly — no purchase order needed. Use this for opening stock or deliveries not tracked through Purchase Orders.</p>
            </div>
            <form wire:submit="submitAddStock">
                <div class="mb">
                    @php
                        $batchValue = is_numeric($addStockQty) && is_numeric($addStockUnitCost) ? (float) $addStockQty * (float) $addStockUnitCost : null;
                        $conversionQtyTyped = ($addStockQtyUnit === 'purchase' && is_numeric($addStockQty) && (float) $addStockQty > 0) ? (float) $addStockQty : 1.0;
                    @endphp
                    @if ($hasDistinctUnits || $batchValue !== null)
                        <div class="preview-card">
                            @if ($hasDistinctUnits)
                                {{ rtrim(rtrim(number_format($conversionQtyTyped, 3), '0'), '.') }} {{ $product->purchaseUnit->name }} = {{ rtrim(rtrim(number_format($conversionQtyTyped * (float) $product->conversion_qty, 3), '0'), '.') }} {{ $product->sellingUnit->name }}
                            @endif
                            @if ($hasDistinctUnits && $batchValue !== null)<br>@endif
                            @if ($batchValue !== null)
                                New batch: <b>{{ rtrim(rtrim(number_format((float) $addStockQty, 3), '0'), '.') }} {{ $addStockUnitLabel }}</b> at <b>{{ number_format((float) $addStockUnitCost, 2) }}</b> each &mdash; worth <b>{{ $money($batchValue) }}</b> at cost.
                            @endif
                        </div>
                    @endif
                    <div class="f grid2">
                        <div>
                            <label for="add_stock_qty">Quantity Received</label>
                            <div class="row">
                                <input type="text" wire:model.live="addStockQty" id="add_stock_qty">
                                @if ($hasDistinctUnits)
                                    <select wire:model.live="addStockQtyUnit">
                                        <option value="purchase">{{ $product->purchaseUnit->name }}</option>
                                        <option value="selling">{{ $product->sellingUnit->name }}</option>
                                    </select>
                                @endif
                            </div>
                            <x-input-error :messages="$errors->get('addStockQty')" class="mt-2" />
                            <x-input-error :messages="$errors->get('addStockQtyUnit')" class="mt-2" />
                        </div>
                        <div>
                            <label for="add_stock_cost">Unit Cost (per {{ $addStockUnitLabel }})</label>
                            @if ($canEditPrices)
                                <input type="text" wire:model.live="addStockUnitCost" id="add_stock_cost">
                            @else
                                <div class="f" style="height:40px; display:flex; align-items:center; padding:0 12px; background:var(--soft); margin:0;" title="Only users who can edit products can change costs">{{ is_numeric($addStockUnitCost) ? number_format((float) $addStockUnitCost, 2) : '—' }}</div>
                            @endif
                            @if ($hasDistinctUnits && $addStockQtyUnit === 'purchase' && is_numeric($addStockUnitCost))
                                <div class="hint">= {{ $money($product->toSellingUnitCost((float) $addStockUnitCost, 'purchase')) }} per {{ $product->sellingUnit->name }}</div>
                            @endif
                            <x-input-error :messages="$errors->get('addStockUnitCost')" class="mt-2" />
                        </div>
                    </div>

                    <div class="f">
                        <label for="add_stock_price">Selling Price (per {{ $product->sellingUnit->name }})</label>
                        @if ($canEditPrices)
                            <input type="text" wire:model.live="addStockSellingPrice" id="add_stock_price">
                        @else
                            <div class="f" style="height:40px; display:flex; align-items:center; padding:0 12px; background:var(--soft); margin:0;" title="Only users who can edit products can change prices">{{ is_numeric($addStockSellingPrice) ? number_format((float) $addStockSellingPrice, 2) : '—' }}</div>
                        @endif
                        @if ($priceIsChanging)
                            <div class="notice warn">This changes the product's selling price from {{ number_format((float) $product->selling_price, 2) }} to {{ number_format($newPrice, 2) }} for all its stock.</div>
                        @endif
                        @if ($asSellingCost !== null && $newPrice !== null)
                            @if ($asSellingCost >= $newPrice)
                                <div class="notice">Cost {{ number_format($asSellingCost, 2) }} per {{ $product->sellingUnit->name }} is not below this price — there'd be no profit. Raise the price, or check the cost.</div>
                            @else
                                <div class="notice ok">Profit per {{ $product->sellingUnit->name }}: {{ number_format($newPrice - $asSellingCost, 2) }} ({{ number_format(($newPrice - $asSellingCost) / $newPrice * 100, 1) }}% margin)</div>
                            @endif
                        @endif
                        <x-input-error :messages="$errors->get('addStockSellingPrice')" class="mt-2" />
                    </div>

                    <div class="f grid2">
                        <div>
                            <label for="add_stock_received">Received Date</label>
                            <input type="date" wire:model="addStockReceivedDate" id="add_stock_received">
                            <x-input-error :messages="$errors->get('addStockReceivedDate')" class="mt-2" />
                        </div>
                        <div>
                            <label for="add_stock_expiry">Expiry Date (optional)</label>
                            <input type="date" wire:model="addStockExpiryDate" id="add_stock_expiry">
                            <x-input-error :messages="$errors->get('addStockExpiryDate')" class="mt-2" />
                        </div>
                    </div>

                    <div class="f">
                        <label for="add_stock_batch_code">Batch Code (optional)</label>
                        <input type="text" wire:model="addStockBatchCode" id="add_stock_batch_code">
                        <x-input-error :messages="$errors->get('addStockBatchCode')" class="mt-2" />
                    </div>

                    <div class="f" style="margin-bottom: 0;">
                        <label for="add_stock_reason">Reason / Note (optional)</label>
                        <textarea wire:model="addStockReason" id="add_stock_reason" rows="2"></textarea>
                        <x-input-error :messages="$errors->get('addStockReason')" class="mt-2" />
                    </div>
                </div>
                <div class="mf">
                    <button type="button" wire:click="cancelAddStockForm" class="btn">Cancel</button>
                    <button type="submit" class="btn p">Add Stock</button>
                </div>
            </form>
        </div>
    @endif

    {{-- -------------------------------------------------- Adjust Stock --}}
    @if ($showAdjustForm)
        @php $selectedBatch = $adjustBatchId ? $availableBatches->firstWhere('id', (int) $adjustBatchId) : null; @endphp
        <div class="overlay" wire:click="cancelAdjustForm"></div>
        <div class="modal">
            <div class="mh">
                <div class="hd">
                    <span class="ic adjust"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg></span>
                    <h2>Stock Adjustment</h2>
                </div>
            </div>
            <form wire:submit="submitAdjustment">
                <div class="mb">
                    @if ($availableBatches->isEmpty())
                        <div class="notice warn">This product has no active batches to adjust.</div>
                    @endif

                    <div class="f">
                        <label for="adjust_batch">Batch</label>
                        <select wire:model.live="adjustBatchId" id="adjust_batch">
                            <option value="">Select a batch…</option>
                            @foreach ($availableBatches as $batch)
                                <option value="{{ $batch->id }}">
                                    {{ $batch->batch_code ?: "Batch #{$batch->id}" }} — {{ rtrim(rtrim(number_format($batch->qty_remaining, 3), '0'), '.') }} remaining
                                    @if ($batch->expiry_date) (expires {{ $batch->expiry_date->format('Y-m-d') }}) @endif
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('adjustBatchId')" class="mt-2" />
                    </div>

                    @if ($selectedBatch)
                        <div class="preview-card">
                            <b>{{ $selectedBatch->batch_code ?: "Batch #{$selectedBatch->id}" }}</b> &middot;
                            received {{ rtrim(rtrim(number_format($selectedBatch->qty_received, 3), '0'), '.') }}, {{ rtrim(rtrim(number_format($selectedBatch->qty_remaining, 3), '0'), '.') ?: '0' }} remaining
                            @if ($selectedBatch->expiry_date) &middot; expires {{ $selectedBatch->expiry_date->format('Y-m-d') }} @endif
                            @if ($adjustType === 'correction_add')
                                <br>You can add up to <b>{{ rtrim(rtrim(number_format($selectedBatch->qty_received - $selectedBatch->qty_remaining, 3), '0'), '.') ?: '0' }}</b> more before it would exceed what this batch originally received.
                            @endif
                        </div>
                    @endif

                    <div class="f">
                        <label for="adjust_type">Adjustment Type</label>
                        <select wire:model.live="adjustType" id="adjust_type">
                            <option value="correction_add">Correction — Add</option>
                            <option value="correction_remove">Correction — Remove</option>
                            <option value="damaged">Damaged</option>
                            <option value="expired">Expired</option>
                        </select>
                        <x-input-error :messages="$errors->get('adjustType')" class="mt-2" />
                    </div>

                    <div class="f">
                        <label for="adjust_qty">Quantity ({{ $product->sellingUnit->name }})</label>
                        <input type="text" wire:model="adjustQty" id="adjust_qty">
                        <x-input-error :messages="$errors->get('adjustQty')" class="mt-2" />
                    </div>

                    <div class="f" style="margin-bottom: 0;">
                        <label for="adjust_reason">Reason</label>
                        <textarea wire:model="adjustReason" id="adjust_reason" rows="2"></textarea>
                        <x-input-error :messages="$errors->get('adjustReason')" class="mt-2" />
                    </div>
                </div>
                <div class="mf">
                    <button type="button" wire:click="cancelAdjustForm" class="btn">Cancel</button>
                    <button type="submit" class="btn p">Record Adjustment</button>
                </div>
            </form>
        </div>
    @endif

    {{-- ---------------------------------------------- Promotional Discount --}}
    @if ($showDiscountForm)
        @php
            $promoPreview = null;
            if ($promoDiscountType !== 'none' && is_numeric($promoDiscountValue) && (float) $promoDiscountValue > 0) {
                $promoPreview = \App\Models\Product::priceAfterDiscount((float) $product->selling_price, $promoDiscountType, (float) $promoDiscountValue);
            }
            $promoFloor = $product->breakEvenCost();
        @endphp
        <div class="overlay" wire:click="cancelDiscountForm"></div>
        <div class="modal">
            <div class="mh">
                <div class="hd">
                    <span class="ic discount"><svg viewBox="0 0 24 24"><path d="M20 12a8 8 0 11-16 0 8 8 0 0116 0z"/><path d="M9.5 9.5h.01M14.5 14.5h.01M15 9l-6 6"/></svg></span>
                    <h2>Promotional Discount</h2>
                </div>
            </div>
            <form wire:submit="saveDiscount">
                <div class="mb">
                    <div class="f">
                        <label for="promo_type">Discount Type</label>
                        <select wire:model.live="promoDiscountType" id="promo_type">
                            <option value="none">None</option>
                            <option value="fixed">Fixed Amount Off</option>
                            <option value="percentage">Percentage Off</option>
                        </select>
                        <x-input-error :messages="$errors->get('promoDiscountType')" class="mt-2" />
                    </div>

                    @if ($promoDiscountType !== 'none')
                        <div class="f">
                            <label for="promo_value">{{ $promoDiscountType === 'percentage' ? 'Percentage (%)' : 'Amount Off' }}</label>
                            <input type="text" wire:model.live="promoDiscountValue" id="promo_value">
                            <x-input-error :messages="$errors->get('promoDiscountValue')" class="mt-2" />
                        </div>

                        @if ($promoPreview !== null)
                            @if ($promoPreview < $promoFloor)
                                <div class="notice">This would price it at {{ number_format($promoPreview, 2) }}, below its cost of {{ number_format($promoFloor, 2) }}. Use a smaller discount.</div>
                            @else
                                <div class="notice ok">Customers will pay <b>{{ $money($promoPreview) }}</b> instead of {{ $money($product->selling_price) }}.</div>
                            @endif
                        @endif

                        <div class="f grid2">
                            <div>
                                <label for="promo_starts">Starts (optional)</label>
                                <input type="date" wire:model="promoStartsAt" id="promo_starts">
                                <x-input-error :messages="$errors->get('promoStartsAt')" class="mt-2" />
                            </div>
                            <div>
                                <label for="promo_ends">Ends (optional)</label>
                                <input type="date" wire:model="promoEndsAt" id="promo_ends">
                                <x-input-error :messages="$errors->get('promoEndsAt')" class="mt-2" />
                            </div>
                        </div>
                        <p class="hint" style="margin-top: -8px;">Leave both dates blank for an always-on standing discount.</p>
                    @endif
                </div>
                <div class="mf">
                    @if ($product->hasActivePromo())
                        <button type="button" wire:click="clearDiscount" class="btn back">Remove Discount</button>
                    @endif
                    <button type="button" wire:click="cancelDiscountForm" class="btn">Cancel</button>
                    <button type="submit" class="btn p">Save</button>
                </div>
            </form>
        </div>
    @endif

    <x-slide-over name="edit-product" title="Edit Product">
        <form wire:submit="submitEdit" id="edit-product-form">
            <p class="hint" style="margin: -4px 0 16px;"><span class="req">*</span> required</p>

            <div class="f" x-data="{ preview: null }">
                <label>Photo</label>
                <div class="photopick">
                    <div class="thumb" style="width:56px;height:56px;">
                        <img x-show="preview" :src="preview" x-cloak>
                        @if ($existingImagePath)
                            <img x-show="!preview" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($existingImagePath) }}">
                        @else
                            <svg x-show="!preview" viewBox="0 0 24 24"><path d="M21 7.5v9a1.5 1.5 0 01-.75 1.3l-7.5 4.33a1.5 1.5 0 01-1.5 0l-7.5-4.33A1.5 1.5 0 013 16.5v-9a1.5 1.5 0 01.75-1.3l7.5-4.33a1.5 1.5 0 011.5 0l7.5 4.33A1.5 1.5 0 0121 7.5z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/></svg>
                        @endif
                    </div>
                    <label for="edit_photo" class="btn sm" style="cursor:pointer;">Choose file</label>
                    <input type="file" id="edit_photo" wire:model="photo" accept="image/*" class="visually-hidden" x-on:change="preview = $event.target.files.length ? URL.createObjectURL($event.target.files[0]) : null">
                    @if ($existingImagePath)
                        <button type="button" wire:click="removePhoto" x-on:click="preview = null" class="actionbtn danger">Remove</button>
                    @endif
                </div>
                <x-input-error :messages="$errors->get('photo')" class="mt-2" />
            </div>

            <div class="f">
                <label for="edit_name">Name<span class="req">*</span></label>
                <input type="text" wire:model="name" id="edit_name">
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="f">
                <label for="edit_description">Description</label>
                <textarea wire:model="description" id="edit_description" rows="2"></textarea>
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div class="f grid2">
                <div>
                    <label for="edit_category">Category<span class="req">*</span></label>
                    <select wire:model="categoryId" id="edit_category">
                        <option value="">None</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('categoryId')" class="mt-2" />
                </div>
                <div>
                    <label for="edit_supplier">Supplier<span class="req">*</span></label>
                    <select wire:model="supplierId" id="edit_supplier">
                        <option value="">None</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('supplierId')" class="mt-2" />
                </div>
            </div>

            <div class="f">
                <label for="edit_barcode">Barcode</label>
                <input type="text" wire:model="barcode" id="edit_barcode">
                <x-input-error :messages="$errors->get('barcode')" class="mt-2" />
            </div>

            <div class="f grid2">
                <div>
                    <label for="edit_purchase_unit">Purchase Unit<span class="req">*</span></label>
                    <select wire:model.live="purchaseUnitId" id="edit_purchase_unit">
                        <option value="">Select…</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('purchaseUnitId')" class="mt-2" />
                </div>
                <div>
                    <label for="edit_selling_unit">Selling Unit<span class="req">*</span></label>
                    <select wire:model.live="sellingUnitId" id="edit_selling_unit">
                        <option value="">Select…</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('sellingUnitId')" class="mt-2" />
                </div>
            </div>

            <div class="f">
                <label for="edit_conversion_qty">Conversion Qty<span class="req">*</span> (1 purchase unit = ? selling units)</label>
                <input type="text" wire:model.live="conversionQty" id="edit_conversion_qty">
                <x-input-error :messages="$errors->get('conversionQty')" class="mt-2" />
            </div>

            <div class="f grid2">
                <div>
                    <label for="edit_cost_price">Cost Price<span class="req">*</span>{{ $sellingUnitId ? ' (per '.($units->firstWhere('id', $sellingUnitId)?->name).')' : '' }}</label>
                    <input type="text" wire:model.live="costPrice" id="edit_cost_price">
                    @if ($this->costPerPurchaseUnit() !== null)
                        <div class="hint">&asymp; {{ number_format($this->costPerPurchaseUnit(), 2) }} per {{ $units->firstWhere('id', $purchaseUnitId)?->name }}</div>
                    @endif
                    <x-input-error :messages="$errors->get('costPrice')" class="mt-2" />
                </div>
                <div>
                    <label for="edit_selling_price">Selling Price<span class="req">*</span></label>
                    <input type="text" wire:model.live="sellingPrice" id="edit_selling_price">
                    <x-input-error :messages="$errors->get('sellingPrice')" class="mt-2" />
                </div>
            </div>

            @php
                $marginCost = is_numeric($costPrice) ? (float) $costPrice : null;
                $marginPrice = is_numeric($sellingPrice) && (float) $sellingPrice > 0 ? (float) $sellingPrice : null;
            @endphp
            @if ($marginCost !== null && $marginPrice !== null)
                @if ($marginCost >= $marginPrice)
                    <div class="notice">Cost is not below the selling price — there'd be no profit.</div>
                @else
                    <div class="notice ok">Profit per {{ $units->firstWhere('id', $sellingUnitId)?->name }}: {{ number_format($marginPrice - $marginCost, 2) }} ({{ number_format(($marginPrice - $marginCost) / $marginPrice * 100, 1) }}% margin)</div>
                @endif
            @endif

            <div class="f">
                <label for="edit_min_stock">Minimum Stock Level<span class="req">*</span></label>
                <input type="text" wire:model="minStockLevel" id="edit_min_stock">
                <x-input-error :messages="$errors->get('minStockLevel')" class="mt-2" />
            </div>

            <div class="f">
                <label for="edit_status">Status<span class="req">*</span></label>
                <select wire:model="status" id="edit_status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </form>

        <x-slot name="footer">
            <button type="button" x-on:click="show = false" class="btn">Cancel</button>
            <button type="submit" form="edit-product-form" class="btn p">Save</button>
        </x-slot>
    </x-slide-over>

    <x-slide-over name="correct-batch-cost" title="Correct Batch Cost" max-width="2xl">
        {{-- Wider than this canvas's other slide-overs: this form's own comparison table needs the room, same reasoning as Sales' RefundModal using 2xl for its line-item grid. --}}
        @php
            $unitName = $product->sellingUnit->name;
            $purchaseUnitName = $product->purchaseUnit->name;
            $unitsDiffer = $product->purchase_unit_id !== $product->selling_unit_id;
            $signed = fn (float $n) => ($n > 0.004 ? '+' : ($n < -0.004 ? '−' : '')).number_format(abs($n), 2);
            $pct = fn (?float $n) => $n === null ? '—' : number_format($n, 1).'%';
            // Higher is better (selling price, profit, margin) vs lower is
            // better (cost, stock value at cost) — colors the "After" card
            // by what a correction actually does to the bottom line, not
            // just whether a number went up.
            $upGood = fn (float $old, float $new) => $new > $old + 0.004 ? 'var(--ok)' : ($new < $old - 0.004 ? 'var(--acc)' : 'inherit');
            $downGood = fn (float $old, float $new) => $new < $old - 0.004 ? 'var(--ok)' : ($new > $old + 0.004 ? 'var(--acc)' : 'inherit');
            $priorCorrections = $costCorrectionBatchId ? ($corrections[$costCorrectionBatchId] ?? collect()) : collect();
        @endphp

        <form wire:submit="submitCostCorrection" id="correct-batch-cost-form">
            @if ($costPreview)
                <div class="card" style="padding: 12px 16px; margin-bottom: 16px;">
                    <div class="f grid2" style="margin-bottom: 0;">
                        <div>
                            <div class="hint" style="margin-top:0;">Current cost (per {{ $unitName }})</div>
                            <div style="font-size: 22px; font-weight: 800; color: {{ $costPreview['old'] > $costPreview['price'] ? 'var(--acc)' : 'var(--ink)' }};">{{ number_format($costPreview['old'], 2) }}</div>
                        </div>
                        <div>
                            <div class="hint" style="margin-top:0;">Selling price (per {{ $unitName }})</div>
                            <div style="font-size: 22px; font-weight: 800;">{{ number_format($costPreview['price'], 2) }}</div>
                        </div>
                    </div>
                    <div class="hint">Batch {{ $costPreview['batch']->batch_code ?: '#'.$costPreview['batch']->id }} &middot; {{ rtrim(rtrim(number_format($costPreview['qty'], 3), '0'), '.') ?: '0' }} {{ $unitName }} in stock</div>
                </div>
            @endif

            @if ($unitsDiffer)
                <div class="notice warn">
                    This product is bought by the <strong>{{ $purchaseUnitName }}</strong> but sold by the <strong>{{ $unitName }}</strong> — 1 {{ $purchaseUnitName }} = {{ rtrim(rtrim(number_format($product->conversion_qty, 3), '0'), '.') }} {{ $unitName }}. Enter the cost per <strong>{{ $unitName }}</strong> below, not per {{ $purchaseUnitName }}.
                </div>
            @endif

            <p class="hint" style="margin-bottom: 16px;">
                Use this when a batch was received at the wrong cost
                @if ($unitsDiffer)
                    — for example a cost entered per {{ $purchaseUnitName }} instead of per {{ $unitName }}
                @endif
                . Stock value and profit use each batch's own cost, so fixing the product's cost price doesn't fix an existing batch.
            </p>

            <div class="f">
                <label for="cost_correction_value">New cost (per {{ $unitName }})</label>
                <input type="text" wire:model.live.debounce.300ms="costCorrectionValue" id="cost_correction_value">
                <x-input-error :messages="$errors->get('costCorrectionValue')" class="mt-2" />
            </div>

            <div class="f">
                <label for="cost_correction_price">Selling price (per {{ $unitName }})</label>
                <input type="text" wire:model.live.debounce.300ms="costCorrectionPrice" id="cost_correction_price">
                <div class="hint">Pre-filled with the current price. Change it here if the price, not the cost, is what was wrong.</div>
                <x-input-error :messages="$errors->get('costCorrectionPrice')" class="mt-2" />
            </div>

            @if ($costPreview && $costPreview['new'] !== null)
                @if (! $costPreview['changed'])
                    <p class="hint">Enter a different cost or selling price to see what changes.</p>
                @else
                    <div class="hint" style="font-weight: 700; color: var(--ink); margin-bottom: 8px;">What changes</div>
                    <div class="f grid2" style="margin-bottom: 16px;">
                        <div class="card costcard">
                            <div class="costcard-h">Before</div>
                            <div class="costcard-row">
                                <span>Cost per {{ $unitName }}</span>
                                <strong>{{ number_format($costPreview['old'], 2) }}</strong>
                            </div>
                            @if ($costPreview['priceChanged'])
                                <div class="costcard-row">
                                    <span>Selling price per {{ $unitName }}</span>
                                    <strong>{{ number_format($costPreview['listOld'], 2) }}</strong>
                                </div>
                            @endif
                            <div class="costcard-row">
                                <span>Profit per {{ $unitName }} <span class="mut" style="font-size:11px;">(at {{ number_format($costPreview['price'], 2) }})</span></span>
                                <strong style="color: {{ $costPreview['unitProfitOld'] < 0 ? 'var(--acc)' : 'inherit' }}">{{ number_format($costPreview['unitProfitOld'], 2) }}</strong>
                            </div>
                            <div class="costcard-row">
                                <span>Margin</span>
                                <strong>{{ $pct($costPreview['marginOld']) }}</strong>
                            </div>
                            @if ($costPreview['countsTowardStock'])
                                <div class="costcard-row">
                                    <span>Stock value at cost <span class="mut" style="font-size:11px;">({{ rtrim(rtrim(number_format($costPreview['qty'], 3), '0'), '.') ?: '0' }} in stock)</span></span>
                                    <strong>{{ number_format($costPreview['valueOld'], 2) }}</strong>
                                </div>
                            @endif
                        </div>

                        <div class="card costcard after">
                            <div class="costcard-h">After</div>
                            <div class="costcard-row">
                                <span>Cost per {{ $unitName }}</span>
                                <strong style="color: {{ $downGood($costPreview['old'], $costPreview['new']) }}">
                                    {{ number_format($costPreview['new'], 2) }}
                                    <span class="delta">({{ $signed($costPreview['new'] - $costPreview['old']) }})</span>
                                </strong>
                            </div>
                            @if ($costPreview['priceChanged'])
                                <div class="costcard-row">
                                    <span>Selling price per {{ $unitName }}</span>
                                    <strong style="color: {{ $upGood($costPreview['listOld'], $costPreview['listNew']) }}">
                                        {{ number_format($costPreview['listNew'], 2) }}
                                        <span class="delta">({{ $signed($costPreview['listNew'] - $costPreview['listOld']) }})</span>
                                    </strong>
                                </div>
                            @endif
                            <div class="costcard-row">
                                <span>Profit per {{ $unitName }} <span class="mut" style="font-size:11px;">(at {{ number_format($costPreview['price'], 2) }})</span></span>
                                <strong style="color: {{ $upGood($costPreview['unitProfitOld'], $costPreview['unitProfitNew']) }}">
                                    {{ number_format($costPreview['unitProfitNew'], 2) }}
                                    <span class="delta">({{ $signed($costPreview['unitProfitNew'] - $costPreview['unitProfitOld']) }})</span>
                                </strong>
                            </div>
                            <div class="costcard-row">
                                <span>Margin</span>
                                <strong style="color: {{ $costPreview['marginOld'] !== null && $costPreview['marginNew'] !== null ? $upGood($costPreview['marginOld'], $costPreview['marginNew']) : 'inherit' }}">
                                    {{ $pct($costPreview['marginNew']) }}
                                    <span class="delta">({{ $costPreview['marginOld'] !== null && $costPreview['marginNew'] !== null ? $signed($costPreview['marginNew'] - $costPreview['marginOld']).' pts' : '—' }})</span>
                                </strong>
                            </div>
                            @if ($costPreview['countsTowardStock'])
                                <div class="costcard-row">
                                    <span>Stock value at cost <span class="mut" style="font-size:11px;">({{ rtrim(rtrim(number_format($costPreview['qty'], 3), '0'), '.') ?: '0' }} in stock)</span></span>
                                    <strong style="color: {{ $downGood($costPreview['valueOld'], $costPreview['valueNew']) }}">
                                        {{ number_format($costPreview['valueNew'], 2) }}
                                        <span class="delta">({{ $signed($costPreview['valueNew'] - $costPreview['valueOld']) }})</span>
                                    </strong>
                                </div>
                            @endif
                        </div>
                    </div>

                    @unless ($costPreview['countsTowardStock'])
                        <p class="hint">This batch is {{ str_replace('_', ' ', $costPreview['batch']->status) }}, so it isn't counted in stock value.</p>
                    @endunless

                    @if (! $costPreview['costChanged'])
                        <p class="hint">Only the selling price changes, so profit already reported on past sales stays as it is.</p>
                    @elseif ($costPreview['netUnitsSold'] > 0)
                        <div class="notice warn">
                            <strong>{{ rtrim(rtrim(number_format($costPreview['netUnitsSold'], 3), '0'), '.') }} {{ $unitName }}</strong>
                            already sold from this batch (after returns). Reported profit on those sales will change by
                            <strong>{{ $signed($costPreview['pastProfitChange']) }}</strong>.
                        </div>
                    @else
                        <p class="hint">Nothing has been sold from this batch yet, so no past profit changes.</p>
                    @endif

                    @if ($costPreview['stillAbovePrice'])
                        <div class="notice">This cost is still not below the selling price ({{ number_format($costPreview['price'], 2) }}), so these units would sell at no profit or a loss. If the cost really is that high, raise the selling price above it.</div>
                    @endif
                @endif
            @endif

            <div class="f">
                <label for="cost_correction_reason">Reason</label>
                <textarea wire:model="costCorrectionReason" id="cost_correction_reason" rows="2"></textarea>
                <x-input-error :messages="$errors->get('costCorrectionReason')" class="mt-2" />
                <div class="hint">Recorded in the audit log with the old and new cost.</div>
            </div>

            @if ($priorCorrections->isNotEmpty())
                <div class="f">
                    <label>Earlier corrections to this batch</label>
                    @foreach ($priorCorrections as $log)
                        <div class="card" style="padding: 8px 12px; margin-bottom: 6px; font-size: 12.5px;">
                            <span class="mono">{{ number_format($log->previous_value['unit_cost'], 2) }} &rarr; {{ number_format($log->new_value['unit_cost'], 2) }}</span>
                            &middot; {{ $log->created_at->format('Y-m-d') }} &middot; {{ $log->user?->name ?? 'System' }}
                            @if (! empty($log->new_value['reason']))
                                <div class="mut">“{{ $log->new_value['reason'] }}”</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </form>

        <x-slot name="footer">
            <button type="button" x-on:click="show = false" class="btn">Cancel</button>
            <button type="submit" form="correct-batch-cost-form" class="btn p">Save Correction</button>
        </x-slot>
    </x-slide-over>
</div>
