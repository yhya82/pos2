@php
    $customersJson = $customers->map(fn ($c) => [
        'id' => $c->id,
        'name' => $c->name,
        'phone' => $c->phone,
        'credit_enabled' => $c->credit_enabled,
        'credit_limit' => (float) $c->credit_limit,
        'outstanding_balance' => (float) $c->outstanding_balance,
    ])->values();

    // What the print rule in pos-terminal.css actually sizes the paper to —
    // the real configured hardware setting, not the mockup's fixed 80mm.
    $paperWidths = ['58mm' => '58mm', '80mm' => '80mm', 'A4' => '210mm'];
@endphp

<div
    class="pos-skin"
    style="--paper-width: {{ $paperWidths[$paperSize] ?? '80mm' }}"
    {{--
        Everything below this point is driven entirely by Alpine's own
        reactive state — x-data only ever evaluates this JSON once, at
        mount; every update after that flows through refreshLiveData()
        via the pos-live-update browser event, never through fresh
        server HTML. wire:ignore tells Livewire to never re-morph this
        subtree on a later render (e.g. the StockChanged listener below
        firing from the till's own checkout deducting stock). Without
        it, that re-render restores plain static markers like x-cloak
        from the freshly rendered HTML — Alpine only strips x-cloak
        once, on first paint, it doesn't treat it as an ongoing binding
        to protect across morphs — which snaps `[x-cloak]{display:none}`
        back onto the receipt dialog mid-animation and silently closes
        it a couple of seconds after checkout.
    --}}
    wire:ignore
    x-data="posTerminal({
        products: @js($products),
        customers: @js($customersJson),
        paymentMethods: @js($paymentMethods),
        categories: @js($categories),
        defaultPaymentMethodId: @js($defaultPaymentMethodId),
        taxEnabled: {{ $taxEnabled ? 'true' : 'false' }},
        taxRate: {{ $taxRate }},
        currencyCode: @js($currencyCode),
        autoPrintReceipt: {{ $autoPrintReceipt ? 'true' : 'false' }},
        barcodeScannerEnabled: {{ $barcodeScannerEnabled ? 'true' : 'false' }},
    })"
    x-on:pos-live-update.window="refreshLiveData($event.detail)"
    x-on:keydown.window="if ($event.key === '/' && !['INPUT','SELECT','TEXTAREA'].includes(document.activeElement.tagName)) { $event.preventDefault(); $refs.search.focus() } if ($event.key === 'Escape') cartOpen = false"
    x-on:resize.window="if (receiptOpen) fitReceipt()"
>
    <div class="app">
        {{-- ============================== LEFT: PRODUCT SEARCH ============================== --}}
        <main>
            <section class="filters">
                <div class="sr">
                    <div class="search">
                        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input
                            x-ref="search"
                            type="search"
                            x-model="search"
                            @keydown.enter.prevent="searchEnter()"
                            placeholder="Search by name or barcode"
                            autocomplete="off"
                            aria-label="Search products by name or barcode"
                        >
                        <kbd>/</kbd>
                    </div>
                    <button type="button" class="scan" x-show="barcodeScannerEnabled" x-cloak @click="focusScan()" aria-label="Scan or enter a barcode">
                        <svg viewBox="0 0 24 24"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M7 12h10"/></svg>
                    </button>
                </div>
                <div class="cats">
                    <button type="button" class="cat" @click="selectedCategory = null" :aria-pressed="selectedCategory === null">
                        All<small x-text="products.length"></small>
                    </button>
                    <template x-for="category in categories" :key="category.id">
                        <button type="button" class="cat" @click="selectedCategory = category.id" :aria-pressed="selectedCategory === category.id">
                            <span x-text="category.name"></span><small x-text="products.filter(p => p.category_id === category.id).length"></small>
                        </button>
                    </template>
                </div>
            </section>

            <section class="grid">
                <template x-for="product in filteredProducts()" :key="product.id">
                    <button
                        type="button"
                        class="p"
                        :class="cartQty(product.id) > 0 ? 'in' : ''"
                        :disabled="isOutOfStock(product)"
                        @click="addToCart(product)"
                    >
                        <span class="cube" :class="product.image_url ? 'has-img' : ''">
                            <template x-if="cartQty(product.id) > 0"><span class="badge" x-text="cartQty(product.id)"></span></template>
                            <img x-show="product.image_url" :src="product.image_url" :alt="product.name" x-cloak>
                            <svg x-show="!product.image_url" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="9" cy="10" r="1.8"/><path d="m21 16-5-5-8 8"/></svg>
                        </span>
                        <span class="n" x-text="product.name"></span>
                        <span class="sku" x-text="(product.barcode ? product.barcode + ' · ' : '') + 'per ' + product.selling_unit"></span>
                        <span class="row">
                            <span class="pc">
                                <template x-if="product.has_promo"><del x-text="formatMoney(product.selling_price)"></del></template>
                                <span :class="product.has_promo ? 'promo' : ''" x-text="formatMoney(product.effective_price)"></span>
                            </span>
                            <span class="s" :class="isOutOfStock(product) ? 'oos' : (isLow(product) ? 'low' : '')" x-text="stockLabel(product)"></span>
                        </span>
                    </button>
                </template>
                <template x-if="filteredProducts().length === 0">
                    <div class="none" x-text="'No products match “' + search + '”. Try another name or category.'"></div>
                </template>
            </section>
        </main>

        {{-- ============================== RIGHT: CART + PAYMENT ============================== --}}
        <aside id="cart" aria-label="Cart" :class="cartOpen ? 'open' : ''">
            <div class="ch">
                <div>
                    <h2 @click="if (window.innerWidth <= 960) cartOpen = false">Current Sale</h2>
                    <p class="status" x-text="cart.length ? cartCount() + (cartCount() == 1 ? ' item in this sale' : ' items in this sale') : 'Cart is empty'"></p>
                </div>
                <button type="button" class="clear" :disabled="cart.length === 0" @click="clearCart()" aria-label="Clear cart" title="Clear cart">
                    <svg viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>
                </button>
            </div>

            <div class="body">
                <template x-if="priceNotices.length > 0">
                    <div class="notice">
                        <div class="flex items-start justify-between gap-2">
                            <b>Prices updated — your cart total has changed</b>
                            <button type="button" @click="priceNotices = []" aria-label="Dismiss">✕</button>
                        </div>
                        <template x-for="notice in priceNotices" :key="notice.product_id">
                            <div class="mt-1">
                                <span x-text="notice.name"></span>:
                                <del x-text="formatMoney(notice.from)"></del>
                                →
                                <b x-text="formatMoney(notice.to)"></b>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Nothing in the cart yet: the customer picker sits up here,
                     where there's otherwise nothing to show — e.g. a regular
                     customer can be picked before anything's scanned. Once
                     items exist it moves back down near payment, out of the
                     way of the lines themselves. One field either way (never
                     both at once), bound to the same customerId. --}}
                <template x-if="cart.length === 0">
                    <div class="f">
                        <label for="pos_customer">Customer (required for credit)</label>
                        <select id="pos_customer" x-model.number="customerId" @change="onCustomerChange()">
                            <option :value="null">Walk-in customer</option>
                            <template x-for="customer in customers" :key="customer.id">
                                <option :value="customer.id" x-text="customer.name + (customer.credit_enabled ? ' (credit)' : '')"></option>
                            </template>
                        </select>
                    </div>
                </template>

                <div class="lines" :class="cart.length === 0 ? 'lines-empty' : ''">
                    <template x-for="(line, index) in cart" :key="line.product_id">
                        <div class="l">
                            <span class="th">
                                <img x-show="line.image_url" :src="line.image_url" :alt="line.name" x-cloak>
                                <svg x-show="!line.image_url" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="9" cy="10" r="1.8"/><path d="m21 16-5-5-8 8"/></svg>
                            </span>
                            <div>
                                <div class="nm" x-text="line.name"></div>
                                <div class="un" x-text="formatMoney(line.unit_price) + ' / ' + line.selling_unit"></div>
                            </div>
                            <div class="lt" x-text="formatMoney(line.quantity * line.unit_price)"></div>
                            <div class="q">
                                <button type="button" @click="stepQuantity(index, -1)" :aria-label="'Remove one ' + line.name">−</button>
                                <b x-text="line.quantity"></b>
                                <button type="button" @click="stepQuantity(index, 1)" :aria-label="'Add one ' + line.name">+</button>
                            </div>
                            <button type="button" class="rm" @click="removeFromCart(index)">Remove</button>
                        </div>
                    </template>
                </div>

                <template x-if="cart.length > 0">
                    <div class="f">
                        <label for="pos_customer_bottom">Customer (required for credit)</label>
                        <select id="pos_customer_bottom" x-model.number="customerId" @change="onCustomerChange()">
                            <option :value="null">Walk-in customer</option>
                            <template x-for="customer in customers" :key="customer.id">
                                <option :value="customer.id" x-text="customer.name + (customer.credit_enabled ? ' (credit)' : '')"></option>
                            </template>
                        </select>
                    </div>
                </template>

                <div class="f" x-show="isCredit() && selectedCustomer()" x-cloak>
                    <p class="text-xs" :style="creditWouldExceedLimit() ? 'color:var(--acc)' : ''">
                        Available credit after this sale: <span x-text="selectedCustomer() ? formatMoney(selectedCustomer().credit_limit - selectedCustomer().outstanding_balance - total()) : ''"></span>
                    </p>
                </div>

                <div class="f">
                    <label>Payment method</label>
                    <div class="seg" :style="'--seg-cols:' + paymentMethods.length">
                        <template x-for="method in paymentMethods" :key="method.id">
                            <button type="button" :aria-pressed="method.id === paymentMethodId" @click="paymentMethodId = method.id" x-text="method.name"></button>
                        </template>
                    </div>
                </div>

                <div class="f" x-show="!isCredit() && paymentMethodCode() === 'cash'" x-cloak>
                    <label for="pos_tender">Amount tendered</label>
                    <input id="pos_tender" x-ref="tendered" x-model.number="tenderedAmount" @input="tenderedError = false" :class="tenderedError ? 'err' : ''" inputmode="decimal" placeholder="0.00">
                    <div class="quick">
                        <template x-for="(amount, i) in quickCashAmounts()" :key="i">
                            <button type="button" @click="tenderedAmount = amount" x-text="i === 0 && total() > 0 ? 'Exact' : num(amount)"></button>
                        </template>
                    </div>
                </div>

                <div class="f" x-show="!isCredit() && paymentMethodCode() !== 'cash'" x-cloak>
                    <label for="pos_ref">Reference number</label>
                    <input id="pos_ref" type="text" x-model="referenceNumber">
                </div>
            </div>

            <div class="sum">
                <div><span>Subtotal</span><span x-text="formatMoney(subtotal())"></span></div>
                <template x-if="taxEnabled">
                    <div><span>Tax</span><span x-text="formatMoney(taxAmount())"></span></div>
                </template>
                <template x-if="paymentMethodCode() === 'cash' && Number(tenderedAmount) > 0">
                    <div class="chg" x-show="changeDue() >= 0"><span>Change due</span><b x-text="formatMoney(changeDue())"></b></div>
                </template>
                <template x-if="paymentMethodCode() === 'cash' && Number(tenderedAmount) > 0">
                    <div x-show="changeDue() < 0"><span>Amount short</span><span x-text="formatMoney(Math.abs(changeDue()))"></span></div>
                </template>
                <div class="tot"><span>Total</span><b><i x-text="currencyCode"></i><span x-text="money(total())"></span></b></div>
            </div>

            <button type="button" class="go" :disabled="processing || cart.length === 0 || (isCredit() && !customerId)" @click="completeSale()">
                <span x-text="goLabel()"></span>
            </button>
        </aside>
    </div>

    <button type="button" class="cartbar" @click="cartOpen = true">
        <span x-text="cart.length ? cartCount() + (cartCount() == 1 ? ' item' : ' items') + ' · ' + formatMoney(total()) : 'Cart is empty'"></span>
        <span>View cart</span>
    </button>

    {{-- ----------------------------------------------------- receipt / printer ---------------------------------------------------- --}}
    <div class="rcpt" role="dialog" aria-modal="true" aria-label="Receipt" :hidden="!receiptOpen" x-cloak>
        <div class="rwrap" x-ref="rwrap">
            <div class="pr" :data-stage="receiptStage">
                <div class="mach">
                    <div class="ph">
                        <div class="pst">
                            <span class="ind" aria-hidden="true"></span>
                            <div class="stat-text" role="status" aria-live="polite" x-text="receiptStageLabel()" :key="receiptStage"></div>
                        </div>
                        <b>POS-80</b>
                    </div>
                    <div class="scr">
                        <small x-text="receiptItemsLabel()"></small>
                        <b x-text="receiptMoney(receiptData ? receiptData.totals.total_amount : 0)"></b>
                    </div>
                    <div class="pslot" aria-hidden="true"></div>
                </div>
                <div class="out">
                    <div class="feed">
                        <article class="paper" x-ref="paper" :style="'clip-path: ' + paperClip" x-html="receiptMarkup()"></article>
                    </div>
                </div>
            </div>
            <div class="pbtns">
                <button type="button" class="pb alt" :disabled="receiptStage !== 'complete'" @click="printReceipt()">Print receipt</button>
                <button type="button" class="pb" @click="newSale()" x-ref="newSaleBtn">New sale</button>
            </div>
        </div>
    </div>

    <div class="toast" :class="toastMessage ? 'on' : ''" role="status" x-text="toastMessage"></div>

    {{--
        Auto-print target: an iframe, not a new tab/window. Off-screen
        rather than display:none — a fully non-rendered iframe is
        unreliable for print() in some browsers. The receipt page itself
        calls window.print() on load (see ?autoprint=1 in receipt.blade.php)
        and, called from inside an iframe, that prints only the iframe's
        content, not the POS page behind it.
    --}}
    <iframe x-ref="printFrame" style="position: fixed; top: -9999px; left: -9999px; width: 1px; height: 1px; border: 0;" aria-hidden="true"></iframe>
</div>

<script>
    function posTerminal(config) {
        return {
            products: config.products,
            customers: config.customers,
            paymentMethods: config.paymentMethods,
            categories: config.categories,
            taxEnabled: config.taxEnabled,
            taxRate: config.taxRate,
            currencyCode: config.currencyCode,
            autoPrintReceipt: config.autoPrintReceipt,
            barcodeScannerEnabled: config.barcodeScannerEnabled,

            search: '',
            selectedCategory: null,
            cart: [],
            cartOpen: false,
            customerId: null,
            // Cash always wins as the starting selection, regardless of
            // the configured default_payment_method_id setting — every
            // till opens on Cash. Only falls through to the configured
            // setting (then "first in the list") if Cash itself isn't
            // among the enabled payment methods.
            paymentMethodId: config.paymentMethods.find(m => m.code === 'cash')?.id
                ?? config.defaultPaymentMethodId
                ?? (config.paymentMethods[0]?.id ?? null),
            referenceNumber: '',
            tenderedAmount: '',
            tenderedError: false,
            processing: false,
            toastMessage: '',
            toastTimer: null,
            priceNotices: [],
            receiptOpen: false,
            receiptStage: 'processing',
            receiptData: null,
            receiptTimers: [],
            // The torn/perforated bottom edge on the printed-paper look —
            // a zigzag clip-path computed once (it never needs to change)
            // and applied via :style, same shape the mockup itself used.
            paperClip: (() => {
                const stops = [];

                for (let i = 0; i < 80; i++) {
                    stops.push((100 - (i + 1) * 100 / 80) + '% ' + (i % 2 === 0 ? '100%' : 'calc(100% - 4px)'));
                }

                return 'polygon(0 0, 100% 0, 100% calc(100% - 4px), ' + stops.join(', ') + ')';
            })(),

            toast(message) {
                this.toastMessage = message;
                clearTimeout(this.toastTimer);
                this.toastTimer = setTimeout(() => { this.toastMessage = ''; }, 2600);
            },

            filteredProducts() {
                const term = this.search.trim().toLowerCase();

                return this.products.filter(p => {
                    if (this.selectedCategory !== null && p.category_id !== this.selectedCategory) return false;
                    if (term && !p.name.toLowerCase().includes(term) && !(p.barcode ?? '').toLowerCase().includes(term)) return false;

                    return true;
                });
            },

            cartQty(productId) {
                return this.cart.find(l => l.product_id === productId)?.quantity ?? 0;
            },

            cartCount() {
                return this.cart.reduce((sum, l) => sum + l.quantity, 0);
            },

            // Whole-tile out-of-stock check: < 1 full unit left. The sale
            // service rejects any line that outruns real stock on hand no
            // matter what — the till can't knowingly oversell, so this
            // never has an override to bend it either.
            isOutOfStock(product) {
                return product.stock_quantity < 1;
            },

            // Same rule the real low-stock report uses: on hand at or below
            // the product's own threshold, but still in stock (out of stock
            // is its own, more severe label).
            isLow(product) {
                return product.stock_quantity > 0 && product.stock_quantity <= product.min_stock_level;
            },

            stockLabel(product) {
                if (product.stock_quantity <= 0) return 'Out of stock';

                return this.num(product.stock_quantity) + ' ' + product.selling_unit + ' left';
            },

            // A truly out-of-stock tile is just a disabled button — nothing
            // to click, nothing to say. The only message this produces is
            // for the one case a disabled attribute can't express: some
            // stock exists, but all of it is already in the cart.
            addToCart(product) {
                if (this.isOutOfStock(product)) return;

                const existing = this.cart.find(l => l.product_id === product.id);
                const alreadyInCart = existing ? existing.quantity : 0;

                if (alreadyInCart + 1 > product.stock_quantity) {
                    this.toast('Only ' + this.num(product.stock_quantity) + ' of ' + product.name + ' in stock.');

                    return;
                }

                if (existing) {
                    existing.quantity += 1;
                } else {
                    this.cart.push({
                        product_id: product.id,
                        name: product.name,
                        image_url: product.image_url,
                        unit_price: product.effective_price,
                        selling_unit: product.selling_unit,
                        quantity: 1,
                    });
                }
            },

            // Quantities are whole numbers: the stepper only ever moves by
            // one, so there's nothing to round — going to 0 just removes it.
            stepQuantity(index, delta) {
                const line = this.cart[index];
                if (!line) return;

                if (delta > 0) {
                    const product = this.products.find(p => p.id === line.product_id);
                    if (product && line.quantity + 1 > product.stock_quantity) {
                        this.toast('Only ' + this.num(product.stock_quantity) + ' of ' + product.name + ' in stock.');

                        return;
                    }
                }

                line.quantity += delta;
                if (line.quantity <= 0) this.removeFromCart(index);
            },

            focusScan() {
                this.$refs.search.focus();
                this.toast('Scan a barcode, or type one, then press Enter');
            },

            // Enter in the search box: an exact barcode match wins outright
            // (what a hardware scanner actually produces); otherwise, if the
            // current search narrows the grid to exactly one in-stock match,
            // add that one — same as tapping its tile.
            searchEnter() {
                const term = this.search.trim();
                if (!term) return;

                const exact = this.products.find(p => p.barcode && p.barcode === term);
                if (exact) {
                    this.addToCart(exact);
                    this.search = '';

                    return;
                }

                const matches = this.filteredProducts().filter(p => !this.isOutOfStock(p));
                if (matches.length === 1) {
                    this.addToCart(matches[0]);
                    this.search = '';
                }
            },

            removeFromCart(index) {
                this.cart.splice(index, 1);
            },

            clearCart() {
                this.cart = [];
                this.priceNotices = [];
                this.tenderedError = false;
            },

            // A price or promo changed while items were already in the cart —
            // bring those lines to the new price (the server charges the
            // current price regardless, so this just keeps the screen honest)
            // and say so, so neither the cashier nor the customer is
            // surprised by a total that moved. One notice per product: if the
            // price moves twice, it shows original -> latest, and disappears
            // if it lands back where the line started.
            repriceCart() {
                this.cart.forEach(line => {
                    const product = this.products.find(p => p.id === line.product_id);

                    if (!product || Math.abs(product.effective_price - line.unit_price) < 0.005) return;

                    const existing = this.priceNotices.find(n => n.product_id === line.product_id);
                    const from = existing ? existing.from : line.unit_price;

                    this.priceNotices = this.priceNotices.filter(n => n.product_id !== line.product_id);

                    line.unit_price = product.effective_price;

                    if (Math.abs(from - product.effective_price) >= 0.005) {
                        this.priceNotices.push({ product_id: line.product_id, name: line.name, from, to: product.effective_price });
                    }
                });
            },

            subtotal() {
                return this.cart.reduce((sum, l) => sum + (l.quantity * l.unit_price), 0);
            },

            taxAmount() {
                if (!this.taxEnabled) return 0;

                return round2(this.subtotal() * this.taxRate / 100);
            },

            total() {
                return round2(this.subtotal() + this.taxAmount());
            },

            changeDue() {
                return round2((Number(this.tenderedAmount) || 0) - this.total());
            },

            quickCashAmounts() {
                const fixed = [100, 500, 1000, 5000];

                return this.total() > 0 ? [this.total(), ...fixed] : fixed;
            },

            selectedCustomer() {
                return this.customers.find(c => c.id === this.customerId) ?? null;
            },

            onCustomerChange() {
                const customer = this.selectedCustomer();
                if (!customer || !customer.credit_enabled) return;

                const creditMethod = this.paymentMethods.find(m => m.code === 'credit');
                if (creditMethod) {
                    this.paymentMethodId = creditMethod.id;
                }
            },

            paymentMethodCode() {
                return this.paymentMethods.find(m => m.id === this.paymentMethodId)?.code ?? '';
            },

            isCredit() {
                return this.paymentMethodCode() === 'credit';
            },

            creditWouldExceedLimit() {
                const customer = this.selectedCustomer();
                if (!customer) return false;

                return (customer.outstanding_balance + this.total()) > customer.credit_limit;
            },

            // The complete-sale button says up front what's stopping it,
            // rather than letting you click and only then finding out.
            goLabel() {
                if (this.isCredit() && !this.customerId) return 'Choose a customer for credit';

                return 'Complete sale';
            },

            formatMoney(amount) {
                return this.currencyCode + ' ' + this.money(amount);
            },

            money(amount) {
                return Number(amount ?? 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            },

            num(amount) {
                return Number(amount ?? 0).toLocaleString('en-GB', { maximumFractionDigits: 3 });
            },

            // Patches server-truth data (stock levels, available payment
            // methods, tax config) pushed from a StockChanged/
            // ModuleSettingChanged/GeneralSettingChanged broadcast —
            // deliberately leaves cart/customerId untouched, since
            // this is a background refresh, not a form reset.
            refreshLiveData(detail) {
                this.products = detail.products;
                this.repriceCart();
                this.paymentMethods = detail.paymentMethods;
                this.taxEnabled = detail.taxEnabled;
                this.taxRate = detail.taxRate;
                this.currencyCode = detail.currencyCode;
                this.barcodeScannerEnabled = detail.barcodeScannerEnabled;
                this.autoPrintReceipt = detail.autoPrintReceipt;

                if (!this.paymentMethods.some(m => m.id === this.paymentMethodId)) {
                    this.paymentMethodId = this.paymentMethods.find(m => m.code === 'cash')?.id ?? this.paymentMethods[0]?.id ?? null;
                }
            },

            async completeSale() {
                if (this.cart.length === 0 || this.processing) return;

                if (this.isCredit() && !this.customerId) {
                    this.toast('Credit sales require a customer to be selected.');

                    return;
                }

                if (this.paymentMethodCode() === 'cash' && (Number(this.tenderedAmount) || 0) < this.total()) {
                    this.toast('Amount tendered is less than the total due.');
                    this.tenderedError = true;
                    this.$nextTick(() => this.$refs.tendered?.focus());

                    return;
                }

                this.tenderedError = false;
                this.processing = true;

                const result = await this.$wire.checkout(
                    this.cart.map(l => ({ product_id: l.product_id, quantity: l.quantity })),
                    this.customerId,
                    this.paymentMethodId,
                    this.paymentMethodCode() === 'cash' ? null : this.referenceNumber,
                );

                this.processing = false;

                if (!result.success) {
                    this.toast(result.message);

                    return;
                }

                if (this.autoPrintReceipt) {
                    this.$refs.printFrame.src = '/sales/' + result.saleId + '/receipt?autoprint=1';
                }

                this.cart.forEach(line => {
                    const product = this.products.find(p => p.id === line.product_id);
                    if (product) {
                        product.stock_quantity = Math.max(0, product.stock_quantity - line.quantity);
                    }
                });

                this.cartOpen = false;
                this.showReceipt(result.receipt);

                this.cart = [];
                this.priceNotices = [];
                this.customerId = null;
                this.referenceNumber = '';
                this.tenderedAmount = '';
            },

            receiptStageLabel() {
                return { processing: 'Processing your order', printing: 'Printing your receipt', complete: 'Order complete' }[this.receiptStage];
            },

            receiptItemsLabel() {
                if (!this.receiptData) return '';

                const n = this.receiptData.lines.reduce((sum, l) => sum + Number(l.quantity), 0);

                return this.num(n) + (n == 1 ? ' item' : ' items') + ' · ' + this.receiptData.totals.payment_method;
            },

            // Formatted with the receipt's own frozen currency, not the
            // live setting — a reprint must read identically no matter what
            // the store's currency has changed to since.
            receiptMoney(amount) {
                const code = this.receiptData?.business?.currency_code ?? this.currencyCode;

                return code + ' ' + this.money(amount);
            },

            // Same 'Y-m-d h:i A' shape the real printed receipt uses.
            receiptDate(iso) {
                const d = new Date(iso);
                const pad = n => String(n).padStart(2, '0');
                const hours24 = d.getHours();
                const period = hours24 >= 12 ? 'PM' : 'AM';
                const hours12 = hours24 % 12 || 12;

                return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(hours12) + ':' + pad(d.getMinutes()) + ' ' + period;
            },

            receiptMarkup() {
                if (!this.receiptData) return '';

                const b = this.receiptData.business, t = this.receiptData.totals, m = this.receiptData.meta;
                const esc = s => String(s ?? '').replace(/[<&]/g, c => c === '<' ? '&lt;' : '&amp;');
                const row = (a, c, cls) => '<div class="rw' + (cls ? ' ' + cls : '') + '"><span>' + a + '</span><span>' + c + '</span></div>';

                let h = '<div class="rt">' + esc(b.business_name) + '</div>';
                // One block, one <br>-joined block — not a separate div per
                // line, which left them glued together with no breathing
                // room between them. Same grouping the real printed receipt
                // (sales/receipt.blade.php) uses: business name, then
                // address/phone/the store's own configurable "printed at the
                // top of every receipt" message (Settings → Receipt Business
                // Info) together, then a clear break before the sale's own
                // details.
                h += '<div class="rmeta c">' + [b.address, b.contact_phone, b.receipt_business_info].filter(Boolean).map(esc).join('<br>') + '</div>';
                h += '<hr>';

                h += '<div class="rmeta">' + [
                    'Receipt: ' + esc(m.receipt_number),
                    'Date: ' + this.receiptDate(m.sale_date),
                    'Cashier: ' + esc(m.cashier_name),
                    m.customer_name ? 'Customer: ' + esc(m.customer_name) : null,
                ].filter(Boolean).join('<br>') + '</div>';
                h += '<hr>';

                this.receiptData.lines.forEach(l => {
                    h += row(esc(l.product_name), this.money(l.subtotal))
                        + '<div class="rs">' + this.num(l.quantity) + ' ' + esc(l.unit) + ' × ' + this.receiptMoney(l.unit_price) + '</div>';
                });

                h += '<hr>' + row('Subtotal', this.money(t.subtotal));
                if (Number(t.discount_amount) > 0) h += row('Discount', '-' + this.money(t.discount_amount));
                if (Number(t.tax_amount) > 0) h += row('Tax', this.money(t.tax_amount));
                h += row('Total', this.receiptMoney(t.total_amount), 'b');
                h += row('Payment', esc(t.payment_method));
                h += '<hr><div class="rmeta c">Thank you for your purchase</div>';

                return h;
            },

            showReceipt(receipt) {
                this.receiptTimers.forEach(clearTimeout);
                this.receiptTimers = [];
                this.receiptData = receipt;
                this.receiptOpen = true;
                this.receiptStage = 'processing';

                const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
                this.receiptTimers.push(setTimeout(() => { this.receiptStage = 'printing'; }, reduced ? 0 : 1300));
                this.receiptTimers.push(setTimeout(() => { this.receiptStage = 'complete'; }, reduced ? 200 : 3050));

                this.$nextTick(() => {
                    // Auto-focusing "New sale" lets a keyboard-only cashier
                    // just hit Enter to move on — but with a barcode scanner
                    // in use, scanning the next item while this is still open
                    // sends its own trailing Enter keystroke, which a focused
                    // <button> treats as a click: the receipt would close
                    // itself the instant that scan lands, not from anyone
                    // actually choosing "New sale". Leaving focus alone when
                    // scanning is enabled keeps that keystroke harmless.
                    if (!this.barcodeScannerEnabled) {
                        this.$refs.newSaleBtn?.focus();
                    }
                    this.fitReceipt();
                });
            },

            // Shrinks the whole printer graphic (not just the paper) so a
            // long cart's receipt still fits the screen without the dialog
            // having to scroll — `zoom`, not `transform: scale`, because
            // scale only changes how it's painted, not the space it
            // reserves, so the dialog would still overflow and scroll by
            // the unscaled height even though it visually looked shrunk.
            // Floors at .5 rather than shrinking indefinitely; a receipt
            // that still can't fit at half size falls back to the dialog's
            // own scroll rather than becoming illegibly tiny.
            fitReceipt() {
                const wrap = this.$refs.rwrap;
                if (!wrap) return;

                wrap.style.zoom = 1;

                const available = wrap.parentElement.clientHeight;
                const natural = wrap.scrollHeight;

                if (available > 0 && natural > available) {
                    wrap.style.zoom = Math.max(.5, available / natural);
                }
            },

            newSale() {
                this.receiptTimers.forEach(clearTimeout);
                this.receiptOpen = false;
                this.$nextTick(() => this.$refs.search.focus());
            },

            printReceipt() {
                window.print();
            },
        };
    }

    function round2(value) {
        return Math.round((value + Number.EPSILON) * 100) / 100;
    }
</script>
