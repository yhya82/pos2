@php
    $money = fn ($amount) => new \Illuminate\Support\HtmlString(
        '<span class="ccy">'.e($currencyCode).'</span> '.number_format($amount, 2)
    );
    // The sign goes before the currency code, not between it and the
    // digits — "-GMD 153.00", never "GMD -153.00".
    $signedMoney = fn ($amount) => new \Illuminate\Support\HtmlString(
        ($amount < 0 ? '-' : '').'<span class="ccy">'.e($currencyCode).'</span> '.number_format(abs($amount), 2)
    );
@endphp

<div class="inventory-skin">
    <div class="tabs">
        @foreach (array_filter([
            'stock' => 'Stock Overview',
            'valuation' => $canViewValuation ? 'Stock Valuation' : null,
            'movements' => 'Movement History',
            'adjust' => 'Stock Adjustments',
            'expiry' => 'Expiry Tracking',
            'discounts' => 'Bulk Discounts',
        ]) as $tab => $label)
            <button type="button" wire:click="setTab('{{ $tab }}')" aria-selected="{{ $activeTab === $tab ? 'true' : 'false' }}">{{ $label }}</button>
        @endforeach
    </div>

    {{-- ============================== STOCK VALUATION ============================== --}}
    @if ($activeTab === 'valuation' && $valuation)
        <div class="filters">
            <div class="search">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input wire:model.live.debounce.300ms="valuationSearch" type="search" placeholder="Search product…">
            </div>
            <select wire:model.live="valuationCategoryId">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
            <label class="check"><input type="checkbox" wire:model.live="valuationInStockOnly"> In stock only</label>
        </div>

        <p class="note">Selling value uses today's selling price (the promo price while a promo is running). Cost is what each batch actually cost. Profit is selling value minus cost. These totals match the Dashboard's Inventory Value and Estimated Gross Profit cards when no filters are applied.</p>

        <div class="card">
            <table class="tbl">
                <thead>
                    <tr>
                        @foreach ([['product_name', 'Product'], ['qty_on_hand', 'Qty on Hand'], ['value_at_cost', 'Value at Cost'], ['value_at_selling_price', 'Selling Value'], ['estimated_gross_profit', 'Gross Profit']] as [$key, $label])
                            <th @class(['num' => $key !== 'product_name'])>
                                <button type="button" wire:click="sortValuation('{{ $key }}')">
                                    {{ $label }}
                                    @if ($valuationSortBy === $key)
                                        <svg viewBox="0 0 24 24" style="{{ $valuationSortDirection === 'asc' ? 'transform:rotate(180deg)' : '' }}"><path d="M7 10l5 5 5-5"/></svg>
                                    @endif
                                </button>
                            </th>
                        @endforeach
                        <th class="num">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($valuation as $row)
                        @php
                            $margin = (float) $row->value_at_selling_price > 0
                                ? (float) $row->estimated_gross_profit / (float) $row->value_at_selling_price * 100
                                : null;
                        @endphp
                        <tr wire:key="valuation-{{ $row->product_id }}">
                            <td><a href="{{ route('products.show', $row->product_id) }}" wire:navigate class="link">{{ $row->product_name }}</a></td>
                            <td class="num mut">{{ rtrim(rtrim(number_format($row->qty_on_hand, 3), '0'), '.') ?: '0' }} {{ $row->selling_unit_name }}</td>
                            <td class="num mut">{{ $money($row->value_at_cost) }}</td>
                            <td class="num">{{ $money($row->value_at_selling_price) }}</td>
                            <td class="num" style="color: {{ $row->estimated_gross_profit >= 0 ? 'var(--ok)' : 'var(--acc)' }}">{{ $signedMoney($row->estimated_gross_profit) }}</td>
                            <td class="num mut">{{ $margin === null ? '—' : number_format($margin, 1).'%' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty">No products to value.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    @php
                        $totalMargin = (float) $valuationTotals->value_at_selling_price > 0
                            ? (float) $valuationTotals->estimated_gross_profit / (float) $valuationTotals->value_at_selling_price * 100
                            : null;
                        $filtered = $valuationSearch !== '' || $valuationCategoryId || ! $valuationInStockOnly;
                    @endphp
                    <tr>
                        <td>Total{{ $filtered ? ' (filtered)' : '' }} &middot; {{ $valuationTotals->product_count }} product(s)</td>
                        <td></td>
                        <td class="num">{{ $money($valuationTotals->value_at_cost) }}</td>
                        <td class="num">{{ $money($valuationTotals->value_at_selling_price) }}</td>
                        <td class="num" style="color: {{ $valuationTotals->estimated_gross_profit >= 0 ? 'var(--ok)' : 'var(--acc)' }}">{{ $signedMoney($valuationTotals->estimated_gross_profit) }}</td>
                        <td class="num">{{ $totalMargin === null ? '—' : number_format($totalMargin, 1).'%' }}</td>
                    </tr>
                </tfoot>
            </table>
            <div class="pager">
                <span>Showing {{ $valuation->firstItem() ?? 0 }}–{{ $valuation->lastItem() ?? 0 }} of {{ $valuation->total() }}</span>
                @if ($valuation->hasPages())
                    <div class="pages">
                        @foreach ($valuation->getUrlRange(max(1, $valuation->currentPage() - 2), min($valuation->lastPage(), $valuation->currentPage() + 2)) as $page => $url)
                            <button type="button" wire:click="gotoPage({{ $page }}, 'valuationPage')" aria-current="{{ $page === $valuation->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ============================== STOCK OVERVIEW ============================== --}}
    @if ($activeTab === 'stock')
        <div class="filters">
            <div class="search">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input wire:model.live.debounce.300ms="stockSearch" type="search" placeholder="Search product…">
            </div>
            <select wire:model.live="stockCategoryId">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="stockSupplierId">
                <option value="">All suppliers</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                @endforeach
            </select>
            <label class="check"><input type="checkbox" wire:model.live="lowStockOnly"> Low stock only</label>
        </div>

        <div class="card">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="num">Qty On Hand</th>
                        <th class="num">Min Level</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stock as $row)
                        <tr wire:key="stock-{{ $row->product_id }}">
                            <td>{{ $row->product_name }}</td>
                            <td @class(['num', 'stockval', 'acc' => $row->qty_on_hand <= 0, 'warn' => $row->qty_on_hand > 0 && $row->is_low_stock])>{{ rtrim(rtrim(number_format($row->qty_on_hand, 3), '0'), '.') ?: '0' }} {{ $row->selling_unit_name }}</td>
                            <td class="num mut">{{ rtrim(rtrim(number_format($row->min_stock_level, 3), '0'), '.') ?: '0' }} {{ $row->selling_unit_name }}</td>
                            <td>
                                @if ($row->qty_on_hand <= 0)
                                    <span class="status acc">Out of Stock</span>
                                @elseif ($row->is_low_stock)
                                    <span class="status warn">Low Stock</span>
                                @else
                                    <span class="status ok">OK</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty">No products found.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="pager">
                <span>Showing {{ $stock->firstItem() ?? 0 }}–{{ $stock->lastItem() ?? 0 }} of {{ $stock->total() }}</span>
                @if ($stock->hasPages())
                    <div class="pages">
                        @foreach ($stock->getUrlRange(max(1, $stock->currentPage() - 2), min($stock->lastPage(), $stock->currentPage() + 2)) as $page => $url)
                            <button type="button" wire:click="gotoPage({{ $page }}, 'stockPage')" aria-current="{{ $page === $stock->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ============================== MOVEMENT HISTORY ============================== --}}
    @if ($activeTab === 'movements')
        <div class="filters">
            <select wire:model.live="movementProductId">
                <option value="">All products</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}">{{ $product->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="movementType">
                <option value="">All movement types</option>
                @foreach ($movementTypes as $type)
                    <option value="{{ $type }}">{{ str($type)->headline() }}</option>
                @endforeach
            </select>
            <div class="daterange">
                <svg viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="16.5" rx="1.5"/><path d="M3 9h18M8 3v3M16 3v3"/></svg>
                <input type="date" wire:model.live="movementDateFrom">
                <span class="to">to</span>
                <input type="date" wire:model.live="movementDateTo">
            </div>
        </div>

        <div class="card">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th class="num">Qty</th>
                        <th class="num">Before &rarr; After</th>
                        <th>Reason</th>
                        <th>User</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($movements as $movement)
                        <tr wire:key="movement-{{ $movement->id }}">
                            <td class="mut">{{ $movement->created_at->format('Y-m-d h:i A') }}</td>
                            <td>{{ $movement->product->name }}</td>
                            <td>
                                <span @class([
                                    'status',
                                    'ok' => in_array($movement->movement_type, ['stock_received', 'return']),
                                    'acc' => $movement->movement_type === 'damaged',
                                    'warn' => $movement->movement_type === 'expired',
                                    'plain' => in_array($movement->movement_type, ['sale', 'adjustment']),
                                ])>{{ str($movement->movement_type)->headline() }}</span>
                            </td>
                            <td class="num" style="color: {{ $movement->quantity > 0 ? 'var(--ok)' : 'var(--acc)' }}">
                                {{ $movement->quantity > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($movement->quantity, 3), '0'), '.') }}
                            </td>
                            <td class="num mut">{{ rtrim(rtrim(number_format($movement->previous_qty, 3), '0'), '.') ?: '0' }} &rarr; {{ rtrim(rtrim(number_format($movement->new_qty, 3), '0'), '.') ?: '0' }}</td>
                            <td class="mut">{{ $movement->reason }}</td>
                            <td>{{ $movement->user?->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty">No movements found.</td></tr>
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

    {{-- ============================== STOCK ADJUSTMENTS ============================== --}}
    @if ($activeTab === 'adjust')
        <div class="card" style="max-width: 520px; padding: 20px;">
            @if (auth()->user()->hasPermission('inventory', 'update'))
                <div class="cardhead">
                    <span class="ic adjust"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg></span>
                    <h2>Record a Stock Adjustment</h2>
                </div>

                <form wire:submit="submitAdjustment">
                    <div class="f">
                        <label for="adjust_product">Product<span class="req">*</span></label>
                        <select wire:model.live="adjustProductId" id="adjust_product">
                            <option value="">Select a product…</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="f">
                        <label for="adjust_batch">Batch<span class="req">*</span></label>
                        <select wire:model="adjustBatchId" id="adjust_batch">
                            <option value="">Select a batch…</option>
                            @foreach ($availableBatches as $batch)
                                <option value="{{ $batch->id }}">
                                    {{ $batch->batch_code ?: "Batch #{$batch->id}" }} — {{ rtrim(rtrim(number_format($batch->qty_remaining, 3), '0'), '.') }} remaining
                                    @if ($batch->expiry_date) (expires {{ $batch->expiry_date->format('Y-m-d') }}) @endif
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('adjustBatchId')" class="mt-2" />
                        @if ($adjustProductId && $availableBatches->isEmpty())
                            <div class="hint">This product has no active batches to adjust.</div>
                        @endif
                    </div>

                    <div class="f">
                        <label for="adjust_type">Adjustment Type<span class="req">*</span></label>
                        <select wire:model="adjustType" id="adjust_type">
                            <option value="correction_add">Correction — Add</option>
                            <option value="correction_remove">Correction — Remove</option>
                            <option value="damaged">Damaged</option>
                            <option value="expired">Expired</option>
                        </select>
                        <x-input-error :messages="$errors->get('adjustType')" class="mt-2" />
                    </div>

                    <div class="f">
                        <label for="adjust_qty">Quantity<span class="req">*</span></label>
                        <input type="text" wire:model="adjustQty" id="adjust_qty">
                        <x-input-error :messages="$errors->get('adjustQty')" class="mt-2" />
                    </div>

                    <div class="f" style="margin-bottom: 20px;">
                        <label for="adjust_reason">Reason<span class="req">*</span></label>
                        <textarea wire:model="adjustReason" id="adjust_reason" rows="2"></textarea>
                        <x-input-error :messages="$errors->get('adjustReason')" class="mt-2" />
                    </div>

                    <div style="display: flex; justify-content: flex-end;">
                        <button type="submit" class="btn p">Record Adjustment</button>
                    </div>
                </form>
            @else
                <p class="mut" style="margin: 0;">You don't have permission to record stock adjustments.</p>
            @endif
        </div>
    @endif

    {{-- ============================== BULK DISCOUNTS ============================== --}}
    @if ($activeTab === 'discounts')
        @if (auth()->user()->hasPermission('discounts', 'update'))
            <div class="filters">
                <div class="search">
                    <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input wire:model.live.debounce.300ms="discountSearch" type="search" placeholder="Search product…">
                </div>
                <select wire:model.live="discountCategoryId">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="discountSupplierId">
                    <option value="">All suppliers</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px; align-items: start;">
                <div class="card">
                    <div class="toolbar">
                        <span>{{ count($selectedProductIds) }} selected</span>
                        <div class="acts">
                            <button type="button" wire:click="selectAllFiltered" class="btn sm">Select All Matching Filter</button>
                            <button type="button" wire:click="clearSelection" class="btn sm">Clear Selection</button>
                        </div>
                    </div>
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th class="chk"></th>
                                <th>Product</th>
                                <th class="num">Selling Price</th>
                                <th>Current Discount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($discountProducts as $product)
                                <tr wire:key="discount-product-{{ $product->id }}">
                                    <td class="chk"><input type="checkbox" wire:model.live="selectedProductIds" value="{{ $product->id }}"></td>
                                    <td>{{ $product->name }}</td>
                                    <td class="num">{{ $money($product->selling_price) }}</td>
                                    <td>
                                        @if ($product->hasActivePromo())
                                            <span class="status ok">{{ $product->promo_discount_type === 'percentage' ? rtrim(rtrim(number_format($product->promo_discount_value, 2), '0'), '.').'% off' : number_format($product->promo_discount_value, 2).' off' }}</span>
                                        @else
                                            <span class="mut">None</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="empty">No products found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <div class="pager">
                        <span>Showing {{ $discountProducts->firstItem() ?? 0 }}–{{ $discountProducts->lastItem() ?? 0 }} of {{ $discountProducts->total() }}</span>
                        @if ($discountProducts->hasPages())
                            <div class="pages">
                                @foreach ($discountProducts->getUrlRange(max(1, $discountProducts->currentPage() - 2), min($discountProducts->lastPage(), $discountProducts->currentPage() + 2)) as $page => $url)
                                    <button type="button" wire:click="gotoPage({{ $page }}, 'discountsPage')" aria-current="{{ $page === $discountProducts->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                <div class="panel">
                    <div class="cardhead">
                        <span class="ic discount"><svg viewBox="0 0 24 24"><path d="M20 12a8 8 0 11-16 0 8 8 0 0116 0z"/><path d="M9.5 9.5h.01M14.5 14.5h.01M15 9l-6 6"/></svg></span>
                        <h3>Apply Discount</h3>
                    </div>
                    <form wire:submit="applyBulkDiscount">
                        <div class="f">
                            <label for="bulk_type">Discount Type<span class="req">*</span></label>
                            <select wire:model.live="bulkDiscountType" id="bulk_type">
                                <option value="fixed">Fixed Amount Off</option>
                                <option value="percentage">Percentage Off</option>
                            </select>
                            @if ($bulkDiscountType === 'percentage')
                                <div class="hint">A % taken off each product's own price. e.g. 20% off <span class="ccy">GMD</span> 10.00 becomes <span class="ccy">GMD</span> 8.00. The cut scales with price, so it's the same percentage across every selected product even if they're priced differently.</div>
                            @else
                                <div class="hint">A flat amount subtracted from the price. e.g. <span class="ccy">GMD</span> 3.00 off <span class="ccy">GMD</span> 10.00 becomes <span class="ccy">GMD</span> 7.00. Every selected product drops by the same amount, not the same percentage.</div>
                            @endif
                            <x-input-error :messages="$errors->get('bulkDiscountType')" class="mt-2" />
                        </div>

                        <div class="f">
                            <label for="bulk_value">{{ $bulkDiscountType === 'percentage' ? 'Percentage (%)' : 'Amount Off' }}<span class="req">*</span></label>
                            <input type="text" wire:model="bulkDiscountValue" id="bulk_value">
                            <x-input-error :messages="$errors->get('bulkDiscountValue')" class="mt-2" />
                        </div>

                        <div class="f grid2">
                            <div>
                                <label for="bulk_starts">Starts (optional)</label>
                                <input type="date" wire:model="bulkStartsAt" id="bulk_starts">
                                <x-input-error :messages="$errors->get('bulkStartsAt')" class="mt-2" />
                            </div>
                            <div>
                                <label for="bulk_ends">Ends (optional)</label>
                                <input type="date" wire:model="bulkEndsAt" id="bulk_ends">
                                <x-input-error :messages="$errors->get('bulkEndsAt')" class="mt-2" />
                            </div>
                        </div>
                        <p class="hint" style="margin-top: -8px; margin-bottom: 16px;">Leave both dates blank for an always-on standing discount.</p>

                        <div class="stack">
                            <button type="submit" class="btn p" style="justify-content: center;">Apply to {{ count($selectedProductIds) }} Product(s)</button>
                            <button type="button" wire:click="clearBulkDiscount" class="btn back" style="justify-content: center;">Clear Discount from {{ count($selectedProductIds) }} Product(s)</button>
                        </div>
                    </form>
                </div>
            </div>
        @else
            <div class="card" style="max-width: 520px; padding: 20px;">
                <p class="mut" style="margin: 0;">You don't have permission to manage discounts.</p>
            </div>
        @endif
    @endif

    {{-- ============================== EXPIRY TRACKING ============================== --}}
    @if ($activeTab === 'expiry')
        <div class="filters">
            <div class="search">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input wire:model.live.debounce.300ms="expirySearch" type="search" placeholder="Search product…">
            </div>
            <select wire:model.live="expiryWithinDays">
                <option value="">All upcoming expiries</option>
                <option value="7">Within 7 days</option>
                <option value="30">Within 30 days</option>
                <option value="60">Within 60 days</option>
            </select>
        </div>

        <div class="card">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="num">Qty Remaining</th>
                        <th>Expiry Date</th>
                        <th>Days Left</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($expiring as $batch)
                        <tr wire:key="expiry-{{ $batch->batch_id }}">
                            <td>{{ $batch->product_name }}</td>
                            <td class="num mut">{{ rtrim(rtrim(number_format($batch->qty_remaining, 3), '0'), '.') }}</td>
                            <td class="mut">{{ $batch->expiry_date->format('Y-m-d') }}</td>
                            <td>
                                @if ($batch->days_to_expiry < 0)
                                    <span class="status acc">Expired</span>
                                @elseif ($batch->days_to_expiry <= 7)
                                    <span class="status acc">{{ $batch->days_to_expiry }} days</span>
                                @elseif ($batch->days_to_expiry <= 30)
                                    <span class="status warn">{{ $batch->days_to_expiry }} days</span>
                                @else
                                    <span class="mut">{{ $batch->days_to_expiry }} days</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty">No batches with an expiry date found.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="pager">
                <span>Showing {{ $expiring->firstItem() ?? 0 }}–{{ $expiring->lastItem() ?? 0 }} of {{ $expiring->total() }}</span>
                @if ($expiring->hasPages())
                    <div class="pages">
                        @foreach ($expiring->getUrlRange(max(1, $expiring->currentPage() - 2), min($expiring->lastPage(), $expiring->currentPage() + 2)) as $page => $url)
                            <button type="button" wire:click="gotoPage({{ $page }}, 'expiryPage')" aria-current="{{ $page === $expiring->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
