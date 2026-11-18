@php
    // One shared formatter for every money figure on this page, instead
    // of each figure deciding for itself whether to show the real
    // configured currency code. The code renders smaller than the number
    // itself (.ccy below — not .cur, which this page already uses for
    // "this is the current chart bar") so the figure is still what the
    // eye lands on. Wrapped in HtmlString so {{ }} doesn't escape the
    // span away, same trick Blade's own e() helper already makes safe to
    // rely on.
    $money = fn ($amount) => new \Illuminate\Support\HtmlString(
        '<span class="ccy">'.e($currencyCode).'</span> '.number_format($amount, 2)
    );
    // Plain-text form for contexts HTML can't reach, like a title
    // attribute (the chart bars' tooltips) — a browser shows that
    // literally, tags and all, rather than rendering them.
    $moneyPlain = fn ($amount) => $currencyCode.' '.number_format($amount, 2);
@endphp

<div class="dash-skin">
    <div class="top">
        <div>
            <h1>Overview</h1>
            <p>Sales, stock and credit at a glance</p>
        </div>
        <div class="date">
            <x-icon name="calendar" />
            {{ now()->format('F Y') }}
        </div>
    </div>

    @if ($canViewRevenue || $canViewReturns || $canViewCustomers || $canViewInventoryValue)
        <section class="g">
            @if ($canViewRevenue)
                <article class="card rev">
                    <div class="hd">
                        <div>
                            <span class="ic"><x-icon name="arrow-trending-up" /></span>
                            <span class="lbl">{{ $periodLabel }}</span>
                        </div>
                        <div class="seg">
                            @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year', 'all' => 'All'] as $value => $label)
                                <button type="button" wire:click="setPeriod('{{ $value }}')" aria-pressed="{{ $period === $value ? 'true' : 'false' }}">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="big">
                        {{ $money($periodSales->revenue) }}
                        @if ($revenueChangePercent !== null)
                            <span class="pill {{ $revenueChangePercent >= 0 ? 'ok' : '' }}">{{ $revenueChangePercent >= 0 ? '+' : '' }}{{ $revenueChangePercent }}% {{ $revenueChangeLabel }}</span>
                        @elseif ($revenueChangeIsNew)
                            {{-- The prior period made no sales at all, so
                                 there's no real baseline for a percentage
                                 — rather than show nothing, say so. --}}
                            <span class="pill">No prior data {{ $revenueChangeLabel }}</span>
                        @endif
                    </div>
                    <p class="dl">{{ $periodSales->transaction_count }} transaction{{ $periodSales->transaction_count == 1 ? '' : 's' }}</p>

                    @php $max = max(1, collect($salesTrend)->max('revenue')); @endphp
                    <div class="chart">
                        @foreach ($salesTrend as $day)
                            <i class="{{ $day['day'] === $currentTrendKey ? 'cur' : '' }}" style="height: {{ max(2, round($day['revenue'] / $max * 100)) }}%" title="{{ $day['label'] }}: {{ $moneyPlain($day['revenue']) }}"></i>
                        @endforeach
                    </div>
                    <div class="axis">
                        @foreach ($salesTrend as $day)
                            <span class="{{ $day['day'] === $currentTrendKey ? 'cur' : '' }}">{{ $day['label'] }}</span>
                        @endforeach
                    </div>
                </article>

                <article class="card prof">
                    <div class="hd"><span class="ic"><x-icon name="banknotes" /></span><span class="lbl">Profit</span></div>
                    <div class="mid {{ $periodProfit < 0 ? 'neg' : '' }}">{{ $money($periodProfit) }}</div>
                </article>
            @endif

            @if ($canViewReturns)
                <article class="card ref">
                    <div class="hd"><span class="ic"><x-icon name="arrow-uturn-left" /></span><span class="lbl">Refunds</span></div>
                    <div class="mid">{{ $money($refundsTotal) }}</div>
                </article>
            @endif

            @if ($canViewCustomers)
                <article class="card cred">
                    <div class="bh">
                        <span class="ic"><x-icon name="credit-card" /></span>
                        <div class="t">
                            <b>Outstanding credit</b>
                            <span class="cnt">{{ $outstandingCreditCustomers->count() }}</span>
                        </div>
                        <a class="va" href="{{ route('customers.index') }}" wire:navigate>View all &rarr;</a>
                    </div>
                    <div class="mid" style="margin-top:4px">{{ $money($outstandingCredit) }}</div>
                    @forelse ($outstandingCreditCustomers as $customer)
                        <div class="ln">
                            <div class="m"><div class="n">{{ $customer->customer_name }}</div></div>
                            <span class="a">{{ $money($customer->outstanding_balance) }}</span>
                        </div>
                    @empty
                        <p class="none">No outstanding credit.</p>
                    @endforelse
                </article>
            @endif

            @if ($canViewInventoryValue)
                <article class="card inv">
                    <div class="hd"><span class="ic"><x-icon name="archive-box" /></span><span class="lbl">Inventory value</span></div>
                    <div class="mid">{{ $money($inventoryValue) }}</div>
                </article>

                <article class="card gp">
                    <div class="hd"><span class="ic"><x-icon name="chart-bar" /></span><span class="lbl">Estimated gross profit</span></div>
                    <div class="mid">{{ $money($estimatedGrossProfit) }}</div>
                </article>
            @endif

            @if ($canViewRevenue)
                <article class="card top-p">
                    <div class="hd"><span class="ic"><x-icon name="star" /></span><span class="lbl">Top products</span></div>
                    @forelse ($topProducts as $product)
                        <div class="ln">
                            <span class="th">
                                @if ($product->image_url)
                                    <img src="{{ $product->image_url }}" alt="{{ $product->product_name }}">
                                @else
                                    <x-icon name="cube" />
                                @endif
                            </span>
                            <div class="m">
                                <div class="n">{{ $product->product_name }}</div>
                                <div class="s">{{ rtrim(rtrim(number_format($product->qty_sold, 3), '0'), '.') }} sold</div>
                            </div>
                            <span class="a">{{ $money($product->total_revenue) }}</span>
                        </div>
                    @empty
                        <p class="none">No sales yet.</p>
                    @endforelse
                </article>
            @endif
        </section>
    @endif

    @if ($canViewInventory || $canViewPurchaseOrders || auth()->user()->canSeeFinancials())
        <section class="g4">
            @if ($canViewInventory)
                <article class="card">
                    <div class="bh">
                        <span class="ic"><x-icon name="exclamation-triangle" /></span>
                        <div class="t"><b>Low stock</b><span class="cnt">{{ $lowStockCount }}</span></div>
                        <a class="va" href="{{ route('inventory.index', ['tab' => 'stock', 'low_stock' => 1]) }}" wire:navigate>View all &rarr;</a>
                    </div>
                    @forelse ($lowStockItems as $item)
                        <div class="ln">
                            <span class="th"><x-icon name="exclamation-triangle" /></span>
                            <div class="m">
                                <div class="n">{{ $item->product_name }}</div>
                                <div class="s w">{{ rtrim(rtrim(number_format($item->qty_on_hand, 3), '0'), '.') }} left</div>
                            </div>
                            <a class="btn" href="{{ route('inventory.index', ['tab' => 'stock', 'low_stock' => 1]) }}" wire:navigate>Restock</a>
                        </div>
                    @empty
                        <p class="none">Nothing low right now.</p>
                    @endforelse
                </article>

                <article class="card">
                    <div class="bh">
                        <span class="ic"><x-icon name="x-circle" /></span>
                        <div class="t"><b>Out of stock</b><span class="cnt">{{ $outOfStockCount }}</span></div>
                        <a class="va" href="{{ route('inventory.index', ['tab' => 'stock']) }}" wire:navigate>View all &rarr;</a>
                    </div>
                    @forelse ($outOfStockNames as $name)
                        <div class="ln">
                            <span class="th"><x-icon name="x-circle" /></span>
                            <div class="m">
                                <div class="n">{{ $name }}</div>
                                <div class="s r">Out of stock</div>
                            </div>
                            <a class="btn" href="{{ route('inventory.index', ['tab' => 'stock']) }}" wire:navigate>Restock</a>
                        </div>
                    @empty
                        <p class="none">Nothing out of stock.</p>
                    @endforelse
                </article>
            @endif

            @if ($canViewPurchaseOrders)
                <article class="card">
                    <div class="bh">
                        <span class="ic"><x-icon name="clock" /></span>
                        <div class="t"><b>Pending orders</b><span class="cnt">{{ $pendingPurchaseOrdersCount }}</span></div>
                        <a class="va" href="{{ route('purchase-orders.index') }}" wire:navigate>View all &rarr;</a>
                    </div>
                    @forelse ($pendingPurchaseOrders as $po)
                        <div class="ln">
                            <span class="th"><x-icon name="clipboard-check" /></span>
                            <div class="m">
                                <div class="n">{{ $po->po_number }} &middot; {{ $po->supplier_name }}</div>
                                <div class="s">{{ $money($po->total) }} &middot; {{ ucfirst(str_replace('_', ' ', $po->status)) }}</div>
                            </div>
                            <a class="btn p" href="{{ route('purchase-orders.index') }}" wire:navigate>View</a>
                        </div>
                    @empty
                        <p class="none">Nothing pending.</p>
                    @endforelse
                </article>
            @endif

            @if (auth()->user()->canSeeFinancials())
                <livewire:dashboard.online-users />
            @endif
        </section>
    @endif

    @unless ($canViewRevenue || $canViewInventory || $canViewCustomers || $canViewPurchaseOrders || auth()->user()->canSeeFinancials())
        <div class="card" style="text-align:center;color:var(--mut)">
            You're logged in — your role doesn't have visibility into any dashboard data yet.
        </div>
    @endunless
</div>
