<div class="purchase-orders-skin">
    <div class="top">
        <div>
            <h1>Purchase Orders</h1>
            <p>Every order placed with a supplier , draft, ordered, received or cancelled</p>
        </div>
        @if (auth()->user()->hasPermission('purchase_orders', 'create'))
            <button type="button" wire:click="create" class="btn p">
                <svg viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M12 5v14M5 12h14"/></svg>
                Create Purchase Order
            </button>
        @endif
    </div>

    <div class="filters">
        <div class="search">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search PO # or supplier…">
        </div>
        <select wire:model.live="statusFilter">
            <option value="">All statuses</option>
            <option value="draft">Draft</option>
            <option value="ordered">Ordered</option>
            <option value="partially_received">Partially Received</option>
            <option value="received">Received</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>

    <div class="card">
        <table class="tbl">
            <thead>
                <tr>
                    <th>PO #</th>
                    <th>Supplier</th>
                    <th>Order Date</th>
                    <th>Items</th>
                    <th>Created By</th>
                    <th>Status</th>
                    <th class="actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($purchaseOrders as $po)
                    <tr wire:key="po-{{ $po->id }}">
                        <td class="mono"><button type="button" wire:click="view({{ $po->id }})" class="link">{{ $po->po_number }}</button></td>
                        <td class="mut">{{ $po->supplier->name }}</td>
                        <td class="mut">{{ $po->order_date->format('Y-m-d') }}</td>
                        <td>
                            @php $shown = $po->lineItems->take(3); @endphp
                            @foreach ($shown as $item)
                                <div title="{{ $item->product->name }}">
                                    {{ $item->product->name }}
                                    <span class="mut" style="font-size:12px;">
                                        × {{ rtrim(rtrim(number_format((float) $item->qty_ordered, 3), '0'), '.') }} {{ $item->purchaseUnit->name }}@if ((float) $item->qty_damaged > 0)
                                            · <span style="color:var(--warn);">{{ rtrim(rtrim(number_format((float) $item->qty_damaged, 3), '0'), '.') }} damaged</span>
                                        @endif
                                        @if ((float) $item->qty_closed_short > 0)
                                            · <span style="color:var(--acc);">{{ rtrim(rtrim(number_format((float) $item->qty_closed_short, 3), '0'), '.') }} short</span>
                                        @endif
                                        @if ((float) $item->qty_received > 0 && $po->status !== 'draft')
                                            · {{ rtrim(rtrim(number_format((float) $item->qty_received, 3), '0'), '.') }} received
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                            @if ($po->line_items_count > $shown->count())
                                <div class="mut" style="font-size:12px;" title="{{ $po->lineItems->pluck('product.name')->implode(', ') }}">+ {{ $po->line_items_count - $shown->count() }} more</div>
                            @endif
                        </td>
                        <td class="mut">{{ $po->creator->name }}</td>
                        <td>
                            <span @class([
                                'status',
                                'plain' => $po->status === 'draft',
                                'ok' => in_array($po->status, ['ordered', 'received']),
                                'warn' => $po->status === 'partially_received',
                                'acc' => $po->status === 'cancelled',
                            ])>{{ str($po->status)->headline() }}</span>
                            @if ($po->approved_at)
                                <span class="tag" title="Approved by {{ $po->approver?->name }} on {{ $po->approved_at->format('Y-m-d') }}"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>approved</span>
                            @endif
                        </td>
                        <td class="actions">
                            <button type="button" wire:click="view({{ $po->id }})" class="actionbtn">View</button>
                            @if (auth()->user()->hasPermission('purchase_orders', 'update'))
                                @if ($po->status === 'draft')
                                    <button type="button" wire:click="edit({{ $po->id }})" class="actionbtn">Edit</button>
                                    @unless ($po->approved_at)
                                        <button type="button" wire:click="approve({{ $po->id }})" class="actionbtn">Approve</button>
                                    @endunless
                                    <button type="button" wire:click="markAsOrdered({{ $po->id }})" class="actionbtn p">Mark Ordered</button>
                                @endif
                                @if (in_array($po->status, ['ordered', 'partially_received']))
                                    <button type="button" wire:click="openReceive({{ $po->id }})" class="actionbtn p">Receive</button>
                                @endif
                                @if (in_array($po->status, ['draft', 'ordered']))
                                    <button type="button" wire:click="confirmCancel({{ $po->id }})" class="actionbtn danger">Cancel</button>
                                @endif
                            @endif
                            @if ($po->status === 'draft' && auth()->user()->hasPermission('purchase_orders', 'delete'))
                                <button type="button" wire:click="confirmDelete({{ $po->id }})" class="actionbtn danger">Delete</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="empty">No purchase orders found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="pager">
            <span>Showing {{ $purchaseOrders->firstItem() ?? 0 }}–{{ $purchaseOrders->lastItem() ?? 0 }} of {{ $purchaseOrders->total() }}</span>
            @if ($purchaseOrders->hasPages())
                <div class="pages">
                    @foreach ($purchaseOrders->getUrlRange(max(1, $purchaseOrders->currentPage() - 2), min($purchaseOrders->lastPage(), $purchaseOrders->currentPage() + 2)) as $page => $url)
                        <button type="button" wire:click="gotoPage({{ $page }})" aria-current="{{ $page === $purchaseOrders->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <x-slide-over name="po-form" :title="$editingPoId ? 'Edit Purchase Order' : 'Create Purchase Order'" max-width="2xl">
        <form wire:submit="save" id="po-form">
            <div class="f grid2">
                <div>
                    <label for="po_supplier">Supplier<span class="req">*</span></label>
                    <select wire:model="supplierId" id="po_supplier">
                        <option value="">Select...</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('supplierId')" class="mt-2" />
                </div>

                <div>
                    <label for="po_order_date">Order Date<span class="req">*</span></label>
                    <input wire:model="orderDate" id="po_order_date" type="date">
                    <x-input-error :messages="$errors->get('orderDate')" class="mt-2" />
                </div>
            </div>

            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                <label style="margin:0;">Line Items<span class="req">*</span></label>
                <button type="button" wire:click="addLine" class="btn sm">+ Add Line</button>
            </div>
            <x-input-error :messages="$errors->get('lines')" class="mb-2" />

            @foreach ($lines as $index => $line)
                <div class="linecard" wire:key="line-{{ $index }}">
                    <div style="display:flex; gap:10px; align-items:flex-start;">
                        <div class="linecard-grid" style="grid-template-columns: 2fr 1fr 1fr 1fr; flex:1;">
                            <div class="f" style="margin-bottom:0;">
                                <select wire:model.live="lines.{{ $index }}.product_id">
                                    <option value="">Product...</option>
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('lines.'.$index.'.product_id')" class="mt-1" />
                            </div>

                            <div class="f" style="margin-bottom:0;">
                                <input type="text" wire:model.live.debounce.300ms="lines.{{ $index }}.qty_ordered" placeholder="Qty">
                                @php
                                    $convProduct = $products->firstWhere('id', $line['product_id']);
                                    $convDiffers = $convProduct && $convProduct->purchase_unit_id !== $convProduct->selling_unit_id && (float) $convProduct->conversion_qty > 0;
                                @endphp
                                @if ($convDiffers && is_numeric($line['qty_ordered']) && (float) $line['qty_ordered'] > 0)
                                    <div class="hint">= {{ rtrim(rtrim(number_format((float) $line['qty_ordered'] * (float) $convProduct->conversion_qty, 3), '0'), '.') }} {{ $convProduct->sellingUnit->name }}</div>
                                @endif
                                <x-input-error :messages="$errors->get('lines.'.$index.'.qty_ordered')" class="mt-1" />
                            </div>

                            <div class="f" style="margin-bottom:0;">
                                {{-- Read-only: always the product's own purchase unit (auto-filled below when a
                                     product is picked), since conversion_qty is only meaningful for that one
                                     pairing — letting a line override it would silently break unit conversion
                                     at receiving time. --}}
                                <div class="readonly">{{ $products->firstWhere('id', $line['product_id'])?->purchaseUnit->name ?? '—' }}</div>
                                @php
                                    $rateProduct = $products->firstWhere('id', $line['product_id']);
                                    $rateShown = $rateProduct && $rateProduct->purchase_unit_id !== $rateProduct->selling_unit_id && (float) $rateProduct->conversion_qty > 0;
                                @endphp
                                @if ($rateShown)
                                    <div class="hint">1 {{ $rateProduct->purchaseUnit->name }} = {{ rtrim(rtrim(number_format((float) $rateProduct->conversion_qty, 3), '0'), '.') }} {{ $rateProduct->sellingUnit->name }}</div>
                                @endif
                                <x-input-error :messages="$errors->get('lines.'.$index.'.purchase_unit_id')" class="mt-1" />
                            </div>

                            <div class="f" style="margin-bottom:0;">
                                @php
                                    $lineProduct = $products->firstWhere('id', $line['product_id']);
                                    $lineCostPerSellingUnit = $lineProduct
                                        && $lineProduct->purchase_unit_id !== $lineProduct->selling_unit_id
                                        && is_numeric($line['cost_price'])
                                        ? $lineProduct->toSellingUnitCost((float) $line['cost_price'], 'purchase')
                                        : null;
                                @endphp
                                @if ($canEditPrices)
                                    <input type="text" wire:model.live.debounce.300ms="lines.{{ $index }}.cost_price" placeholder="{{ $lineProduct ? 'Cost per '.$lineProduct->purchaseUnit->name : 'Cost' }}">
                                @else
                                    <div class="readonly" title="Only users who can edit products can change costs">{{ is_numeric($line['cost_price']) ? number_format((float) $line['cost_price'], 2) : '—' }}</div>
                                @endif
                                @if ($lineCostPerSellingUnit !== null)
                                    <div class="hint">= <span class="ccy">{{ $currencyCode }}</span> {{ number_format($lineCostPerSellingUnit, 2) }} / {{ $lineProduct->sellingUnit->name }}</div>
                                @endif
                                @if (is_numeric($line['qty_ordered']) && is_numeric($line['cost_price']) && (float) $line['qty_ordered'] > 0)
                                    <div class="hint" style="color:var(--ink); font-weight:600;">Line total: {{ number_format((float) $line['qty_ordered'] * (float) $line['cost_price'], 2) }}</div>
                                @endif
                                <x-input-error :messages="$errors->get('lines.'.$index.'.cost_price')" class="mt-1" />
                            </div>
                        </div>
                        @if (count($lines) > 1)
                            <button type="button" wire:click="removeLine({{ $index }})" aria-label="Remove line" class="linecard-remove"><svg viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M6 18L18 6M6 6l12 12"/></svg></button>
                        @endif
                    </div>
                </div>
            @endforeach

            @php
                $formTotal = collect($lines)->sum(fn ($l) => is_numeric($l['qty_ordered'] ?? null) && is_numeric($l['cost_price'] ?? null) ? (float) $l['qty_ordered'] * (float) $l['cost_price'] : 0);
            @endphp
            <div style="background:var(--soft); border:1px solid var(--line); padding:10px 14px; display:flex; align-items:center; justify-content:space-between; margin-top:12px;">
                <span style="font-size:13px; font-weight:600;">Order total <span class="mut" style="font-weight:400; font-size:12px;">({{ count($lines) }} {{ \Illuminate\Support\Str::plural('line', count($lines)) }})</span></span>
                <span style="font-size:15px; font-weight:800;"><span class="ccy">{{ $currencyCode }}</span> {{ number_format($formTotal, 2) }}</span>
            </div>
            @unless ($canEditPrices)
                <p class="hint" style="margin-top:8px;">Costs come from the product and can only be changed by someone who can edit products.</p>
            @endunless
        </form>

        <x-slot name="footer">
            <button type="button" x-on:click="show = false" class="btn">Cancel</button>
            <button type="submit" form="po-form" class="btn p">Save</button>
        </x-slot>
    </x-slide-over>

    <x-slide-over name="receive-form" title="Receive Stock">
        <form wire:submit="receive" id="receive-form">
            @forelse ($receivingLines as $index => $line)
                <div class="linecard" wire:key="receiving-line-{{ $index }}">
                    <div class="linecard-top">
                        <span>{{ $line['product_name'] }}</span>
                        <span class="mut">
                            {{ rtrim(rtrim(number_format((float) $line['ordered'], 3), '0'), '.') ?: '0' }} {{ $line['unit_name'] }} ordered
                            @if ($line['units_differ'] && $line['conversion_qty'] > 0)
                                <br>= {{ rtrim(rtrim(number_format((float) $line['ordered'] * $line['conversion_qty'], 3), '0'), '.') ?: '0' }} {{ $line['selling_unit_name'] }}
                            @endif
                            @if ($line['already_in'] > 0.0000005)
                                <br>{{ rtrim(rtrim(number_format((float) $line['already_in'], 3), '0'), '.') ?: '0' }} already in · {{ rtrim(rtrim(number_format((float) $line['remaining'], 3), '0'), '.') ?: '0' }} left
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

                    <div class="linecard-grid">
                        <div class="f" style="margin-bottom:0;">
                            <label>Qty received now</label>
                            <div class="row">
                                <input type="text" wire:model.live.debounce.300ms="receivingLines.{{ $index }}.qty">
                                @if ($line['units_differ'])
                                    <select wire:model.live="receivingLines.{{ $index }}.qty_unit">
                                        <option value="purchase">{{ $line['unit_name'] }}</option>
                                        <option value="selling">{{ $line['selling_unit_name'] }}</option>
                                    </select>
                                @else
                                    <span style="display:inline-flex; align-items:center; height:40px; padding:0 10px; color:var(--mut); font-size:13.5px; white-space:nowrap;">{{ $line['unit_name'] }}</span>
                                @endif
                            </div>
                            @if ($line['units_differ'] && $line['conversion_qty'] > 0)
                                <div class="hint">
                                    1 {{ $line['unit_name'] }} = {{ rtrim(rtrim(number_format($line['conversion_qty'], 3), '0'), '.') }} {{ $line['selling_unit_name'] }}
                                    @if (is_numeric($line['qty']) && (float) $line['qty'] > 0)
                                        <br>Adds {{ rtrim(rtrim(number_format((float) $line['qty'] * ($line['qty_unit'] === 'selling' ? 1 : $line['conversion_qty']), 3), '0'), '.') }} {{ $line['selling_unit_name'] }} to stock
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div class="f" style="margin-bottom:0;">
                            <label>Batch code</label>
                            <input type="text" wire:model="receivingLines.{{ $index }}.batch_code">
                        </div>
                        <div class="f" style="margin-bottom:0;">
                            <label>Expiry date</label>
                            <input type="date" wire:model="receivingLines.{{ $index }}.expiry_date">
                        </div>
                        <div class="f" style="margin-bottom:0;">
                            <label>Received date</label>
                            <input type="date" wire:model="receivingLines.{{ $index }}.received_date">
                        </div>
                        <div class="f" style="margin-bottom:0;">
                            <label>Cost paid (per {{ $line['unit_name'] }})</label>
                            @if ($canEditPrices)
                                <input type="text" wire:model.live.debounce.300ms="receivingLines.{{ $index }}.unit_cost">
                            @else
                                <div class="readonly">{{ is_numeric($line['unit_cost']) ? number_format((float) $line['unit_cost'], 2) : '—' }}</div>
                            @endif
                        </div>
                        <div class="f" style="margin-bottom:0;">
                            <label>Selling price (per {{ $line['selling_unit_name'] }})</label>
                            @if ($canEditPrices)
                                <input type="text" wire:model.live.debounce.300ms="receivingLines.{{ $index }}.selling_price">
                            @else
                                <div class="readonly">{{ number_format($line['current_price'], 2) }}</div>
                            @endif
                        </div>
                    </div>

                    @if ($over)
                        <div class="notice">
                            You ordered <strong>{{ $fmt($line['ordered']) }} {{ $line['unit_name'] }}</strong>@if ($line['already_in'] > 0.0000005), and {{ $fmt($line['already_in']) }} {{ $line['already_in'] == 1 ? 'is' : 'are' }} already in @endif
                            — so no more than <strong>{{ $fmt($remainingPieces) }} {{ $line['selling_unit_name'] }}</strong>@if ($line['units_differ']) ({{ $fmt($line['remaining']) }} {{ $line['unit_name'] }})@endif can be received now.
                            You've entered {{ $fmt($goodNow + $badNow) }} {{ $line['selling_unit_name'] }}@if ($badNow > 0) ({{ $fmt($goodNow) }} good + {{ $fmt($badNow) }} damaged)@endif.
                            Please lower the quantity — receiving is blocked until it fits.
                        </div>
                    @endif

                    <div x-data="{ open: {{ ($line['damaged_qty'] !== '' || $line['close_short']) ? 'true' : 'false' }} }">
                        <button type="button" x-on:click="open = ! open" class="togglelink">
                            <svg viewBox="0 0 24 24" x-show="!open"><path d="M12 5v14M5 12h14"/></svg>
                            <svg viewBox="0 0 24 24" x-show="open" x-cloak><path d="M19 9l-7 7-7-7"/></svg>
                            <span x-text="open ? 'Hide damaged / missing' : 'Some damaged or missing?'"></span>
                        </button>
                        <div x-show="open" x-cloak class="subpanel">
                            <div class="linecard-grid">
                                <div class="f" style="margin-bottom:0;">
                                    <label>Damaged on arrival</label>
                                    <div class="row">
                                        <input type="text" wire:model.live.debounce.300ms="receivingLines.{{ $index }}.damaged_qty">
                                        @if ($line['units_differ'])
                                            <select wire:model.live="receivingLines.{{ $index }}.damaged_unit">
                                                <option value="purchase">{{ $line['unit_name'] }}</option>
                                                <option value="selling">{{ $line['selling_unit_name'] }}</option>
                                            </select>
                                        @else
                                            <span style="display:inline-flex; align-items:center; height:40px; padding:0 10px; color:var(--mut); font-size:13.5px; white-space:nowrap;">{{ $line['unit_name'] }}</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="f" style="margin-bottom:0;">
                                    <label>What's wrong with them?</label>
                                    <input type="text" wire:model="receivingLines.{{ $index }}.damaged_reason" placeholder="e.g. crushed, leaking">
                                </div>
                            </div>
                            <p>Damaged units don't go into stock. They're recorded as a loss at cost and a claim against the supplier.</p>

                            @if ($canWriteOff)
                                <label class="check" style="margin-top:10px;">
                                    <input type="checkbox" wire:model.live="receivingLines.{{ $index }}.close_short">
                                    <span>The rest won't arrive — close this line short and claim it</span>
                                </label>
                                @if ($line['close_short'])
                                    <div class="f" style="margin-bottom:0; margin-top:8px;">
                                        <input type="text" wire:model="receivingLines.{{ $index }}.short_reason" placeholder="Why won't it arrive? (e.g. supplier out of stock)">
                                    </div>
                                @endif
                            @endif

                            @if (! $over)
                                @if ($badNow > 0)
                                    <p>{{ $fmt($badNow) }} {{ $line['selling_unit_name'] }} damaged — claim on the supplier: <strong><span class="ccy">{{ $currencyCode }}</span> {{ number_format($badNow * $pieceCost, 2) }}</strong></p>
                                @endif
                                @if ($canWriteOff && $line['close_short'] && $leftAfter > 0)
                                    <p>{{ $fmt($leftAfter) }} {{ $line['selling_unit_name'] }} closed as missing — claim: <strong><span class="ccy">{{ $currencyCode }}</span> {{ number_format($leftAfter * $pieceCost, 2) }}</strong></p>
                                @elseif ($leftAfter > 0)
                                    <p class="mut">Still expected after this delivery: {{ $fmt($leftAfter) }} {{ $line['selling_unit_name'] }}</p>
                                @endif
                            @endif
                        </div>
                    </div>
                    @php
                        $lineCost = is_numeric($line['unit_cost']) && $line['conversion_qty'] > 0 ? (float) $line['unit_cost'] / $line['conversion_qty'] : null;
                        $linePrice = is_numeric($line['selling_price']) && (float) $line['selling_price'] > 0 ? (float) $line['selling_price'] : null;
                    @endphp
                    @if ($linePrice !== null && abs($linePrice - $line['current_price']) >= 0.005)
                        <div class="notice warn">This changes the selling price from {{ number_format($line['current_price'], 2) }} to {{ number_format($linePrice, 2) }}.</div>
                    @endif
                    @if ($lineCost !== null && $linePrice !== null)
                        @if ($lineCost >= $linePrice)
                            <div class="notice">Cost <span class="ccy">{{ $currencyCode }}</span> {{ number_format($lineCost, 2) }} per {{ $line['selling_unit_name'] }} is not below this price — no profit. It will still be received; raise the price or check the cost.</div>
                        @else
                            <div class="notice ok">Profit per {{ $line['selling_unit_name'] }}: <span class="ccy">{{ $currencyCode }}</span> {{ number_format($linePrice - $lineCost, 2) }} ({{ number_format(($linePrice - $lineCost) / $linePrice * 100, 1) }}% margin)</div>
                        @endif
                    @endif
                </div>
            @empty
                <p class="mut" style="font-size:13.5px;">Nothing left to receive on this order.</p>
            @endforelse
        </form>

        <x-slot name="footer">
            <button type="button" x-on:click="show = false" class="btn">Cancel</button>
            <button type="submit" form="receive-form" class="btn p">Receive</button>
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

            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
                <div class="mono" style="font-size:17px; font-weight:800;">{{ $po->po_number }}</div>
                <span @class([
                    'status',
                    'plain' => $po->status === 'draft',
                    'ok' => in_array($po->status, ['ordered', 'received']),
                    'warn' => $po->status === 'partially_received',
                    'acc' => $po->status === 'cancelled',
                ])>{{ str($po->status)->headline() }}</span>
            </div>
            <div class="dl">
                <div><dt>Supplier</dt><dd>{{ $po->supplier->name }}</dd></div>
                <div><dt>Order date</dt><dd>{{ $po->order_date->format('Y-m-d') }}</dd></div>
                <div><dt>Created by</dt><dd>{{ $po->creator->name }}</dd></div>
                <div><dt>Approved</dt><dd>{{ $po->approved_at ? $po->approver?->name.' · '.$po->approved_at->format('Y-m-d') : '—' }}</dd></div>
            </div>

            <h3>Items ordered ({{ $po->lineItems->count() }})</h3>
            <div class="card">
                <table class="mini-tbl">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="num">Ordered</th>
                            <th class="num">Received</th>
                            <th class="num">Left</th>
                            <th class="num">Cost</th>
                            <th class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($po->lineItems as $item)
                            <tr wire:key="po-detail-line-{{ $item->id }}">
                                <td>
                                    {{ $item->product->name }}
                                    @if ((float) $item->qty_damaged > 0 || (float) $item->qty_closed_short > 0)
                                        <div class="mut" style="font-size:11px;">
                                            @if ((float) $item->qty_damaged > 0) {{ $qty($item->qty_damaged) }} damaged @endif
                                            @if ((float) $item->qty_closed_short > 0) {{ (float) $item->qty_damaged > 0 ? '·' : '' }} {{ $qty($item->qty_closed_short) }} closed short @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="num">{{ $qty($item->qty_ordered) }} {{ $item->purchaseUnit->name }}</td>
                                <td class="num">{{ $qty($item->qty_received) }}</td>
                                <td class="num" @style(['color:var(--warn)' => $item->remainingQty() > 0 && $po->status !== 'draft'])>{{ $qty($item->remainingQty()) }}</td>
                                <td class="num"><span class="ccy">{{ $currencyCode }}</span> {{ number_format((float) $item->cost_price, 2) }}</td>
                                <td class="num"><span class="ccy">{{ $currencyCode }}</span> {{ number_format((float) $item->qty_ordered * (float) $item->cost_price, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" style="text-align:right;">Order total</td>
                            <td class="num"><span class="ccy">{{ $currencyCode }}</span> {{ number_format($orderedTotal, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="hint">Cost is per unit as ordered. What was actually paid at delivery is listed below.</p>

            <h3>Deliveries received</h3>
            @if ($details['batches']->isEmpty())
                <p class="mut" style="font-size:13.5px;">Nothing has been received against this order yet.</p>
            @else
                <div class="card">
                    <table class="mini-tbl">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Product</th>
                                <th>Batch</th>
                                <th class="num">Qty</th>
                                <th class="num">Cost paid</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($details['batches'] as $batch)
                                <tr wire:key="po-detail-batch-{{ $batch->id }}">
                                    <td class="mut">{{ $batch->received_date->format('Y-m-d') }}</td>
                                    <td>{{ $batch->product->name }}</td>
                                    <td class="mono mut">{{ $batch->batch_code ?: '#'.$batch->id }}</td>
                                    <td class="num">{{ $qty($batch->qty_received) }} {{ $batch->product->sellingUnit->name }}</td>
                                    <td class="num"><span class="ccy">{{ $currencyCode }}</span> {{ number_format((float) $batch->unit_cost, 2) }} <span class="mut" style="font-size:10.5px;">/ {{ $batch->product->sellingUnit->name }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" style="text-align:right;">Received value at cost paid</td>
                                <td class="num"><span class="ccy">{{ $currencyCode }}</span> {{ number_format($receivedTotal, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif

            @if ($details['issues']->isNotEmpty())
                @php
                    $owedTotal = $details['issues']->sum(fn ($i) => $i->outstanding());
                    $creditedTotal = $details['issues']->sum(fn ($i) => (float) $i->credited_amount);
                @endphp
                <h3>Damaged &amp; missing — claims on {{ $po->supplier->name }}</h3>
                <div class="card">
                    <table class="mini-tbl">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Issue</th>
                                <th class="num">Qty</th>
                                <th class="num">Claim</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($details['issues'] as $issue)
                                <tr wire:key="po-issue-{{ $issue->id }}">
                                    <td>
                                        {{ $issue->product->name }}
                                        <div class="mut" style="font-size:11px;">{{ $issue->reason }} · {{ $issue->created_at->format('Y-m-d') }}</div>
                                    </td>
                                    <td>{{ ucfirst($issue->issue_type) }}</td>
                                    <td class="num">{{ $qty($issue->qty) }} {{ $issue->product->sellingUnit->name }}</td>
                                    <td class="num"><span class="ccy">{{ $currencyCode }}</span> {{ number_format((float) $issue->loss_value, 2) }}</td>
                                    <td>
                                        @if ($issue->claim_status === 'owed')
                                            <span class="status warn">Owed</span>
                                        @elseif ($issue->claim_status === 'credited')
                                            <span class="status ok" title="{{ $issue->resolution_note }}">Credited {{ number_format((float) $issue->credited_amount, 2) }}</span>
                                        @else
                                            <span class="status plain" title="{{ $issue->resolution_note }}">Waived</span>
                                        @endif
                                    </td>
                                    <td class="num">
                                        @if ($issue->claim_status === 'owed' && $canWriteOff)
                                            <button type="button" wire:click="openResolve({{ $issue->id }})" class="actionbtn">Settle</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" style="text-align:right;">Supplier still owes for this order</td>
                                <td class="num"><span class="ccy">{{ $currencyCode }}</span> {{ number_format($owedTotal, 2) }}</td>
                                <td colspan="2" class="mut" style="font-weight:400; font-size:11px;">{{ $creditedTotal > 0 ? number_format($creditedTotal, 2).' credited so far' : '' }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        @endif

        <x-slot name="footer">
            <button type="button" x-on:click="show = false" class="btn">Close</button>
        </x-slot>
    </x-slide-over>

    <x-slide-over name="resolve-claim" title="Settle Supplier Claim">
        @if ($resolvingIssue)
            <form wire:submit="submitResolve" id="resolve-claim-form">
                <div style="background:var(--soft); border:1px solid var(--line); padding:12px 14px; margin-bottom:16px;">
                    <div style="font-weight:700;">{{ $resolvingIssue->product->name }} — {{ $resolvingIssue->issue_type }}</div>
                    <div class="mut" style="font-size:12.5px; margin-top:3px;">{{ rtrim(rtrim(number_format((float) $resolvingIssue->qty, 3), '0'), '.') }} {{ $resolvingIssue->product->sellingUnit->name }} · claim <strong style="color:var(--ink);"><span class="ccy">{{ $currencyCode }}</span> {{ number_format((float) $resolvingIssue->loss_value, 2) }}</strong> against {{ $resolvingIssue->supplier->name }}</div>
                    <div class="mut" style="font-size:11.5px; margin-top:4px;">{{ $resolvingIssue->reason }}</div>
                </div>

                <div class="f">
                    <label>Outcome<span class="req">*</span></label>
                    <label class="radio"><input type="radio" wire:model.live="resolveAction" value="credited"> The supplier credited / refunded it</label>
                    <label class="radio"><input type="radio" wire:model.live="resolveAction" value="waived"> Waive it — we won't claim this</label>
                </div>

                @if ($resolveAction === 'credited')
                    <div class="f">
                        <label for="resolve_amount">Amount credited</label>
                        <input type="text" wire:model="resolveAmount" id="resolve_amount">
                        <div class="hint">Up to <span class="ccy">{{ $currencyCode }}</span> {{ number_format((float) $resolvingIssue->loss_value, 2) }}. If they gave back less, enter what they actually gave — the claim is closed either way.</div>
                    </div>
                @endif

                <div class="f" style="margin-bottom:0;">
                    <label for="resolve_note">{{ $resolveAction === 'waived' ? "Why is it being waived?" : 'Note (optional)' }}</label>
                    <textarea wire:model="resolveNote" id="resolve_note" rows="2"></textarea>
                    <x-input-error :messages="$errors->get('resolveNote')" class="mt-2" />
                </div>
            </form>
        @endif

        <x-slot name="footer">
            <button type="button" x-on:click="show = false" class="btn">Cancel</button>
            <button type="submit" form="resolve-claim-form" class="btn p">Save</button>
        </x-slot>
    </x-slide-over>

    <x-modal name="confirm-cancel-po" max-width="sm">
        <div class="dialog">
            <div class="dh">
                <h2>Confirm</h2>
                <p>Are you sure? Cancelling a purchase order is permanent — it stays in the list for history but can no longer be ordered or received.</p>
            </div>
            <div class="df">
                <button type="button" class="btn" x-on:click="$dispatch('close')">Cancel</button>
                <button type="button" wire:click="cancel" class="btn danger">Confirm</button>
            </div>
        </div>
    </x-modal>

    <x-modal name="confirm-delete-po" max-width="sm">
        <div class="dialog">
            <div class="dh">
                <h2>Confirm</h2>
                <p>Are you sure? This draft purchase order will be permanently deleted — this only works for drafts that were never sent to the supplier.</p>
            </div>
            <div class="df">
                <button type="button" class="btn" x-on:click="$dispatch('close')">Cancel</button>
                <button type="button" wire:click="delete" class="btn danger">Delete</button>
            </div>
        </div>
    </x-modal>
</div>
