@php
    $productsJson = $products->map(fn ($p) => [
        'id' => $p->id,
        'name' => $p->name,
        'barcode' => $p->barcode,
        'image_url' => $p->imageUrl(),
        'category_id' => $p->category_id,
        'selling_price' => (float) $p->selling_price,
        'selling_unit' => $p->sellingUnit->name,
        'stock_quantity' => (float) ($p->stock_quantity ?? 0),
    ])->values();

    $customersJson = $customers->map(fn ($c) => [
        'id' => $c->id,
        'name' => $c->name,
        'phone' => $c->phone,
        'credit_enabled' => $c->credit_enabled,
        'credit_limit' => (float) $c->credit_limit,
        'outstanding_balance' => (float) $c->outstanding_balance,
    ])->values();

    $paymentMethodsJson = $paymentMethods->map(fn ($m) => [
        'id' => $m->id,
        'name' => $m->name,
        'code' => $m->code,
    ])->values();
@endphp

<div
    x-data="posTerminal({
        products: @js($productsJson),
        customers: @js($customersJson),
        paymentMethods: @js($paymentMethodsJson),
        categories: @js($categories),
        defaultPaymentMethodId: @js($defaultPaymentMethodId),
        maxDiscountPercentage: {{ $maxDiscountPercentage }},
        taxEnabled: {{ $taxEnabled ? 'true' : 'false' }},
        taxRate: {{ $taxRate }},
        currencyCode: @js($currencyCode),
        autoPrintReceipt: {{ $autoPrintReceipt ? 'true' : 'false' }},
    })"
    class="grid grid-cols-1 lg:grid-cols-3 gap-4 items-start"
>
    {{-- ============================== LEFT: PRODUCT SEARCH ============================== --}}
    <div class="lg:col-span-2 space-y-4">
        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-4 space-y-3">
            <div class="flex gap-3">
                <input
                    type="text"
                    x-model="search"
                    placeholder="Search products by name..."
                    class="flex-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                >
                <input
                    type="text"
                    x-model="barcodeInput"
                    x-ref="barcodeInput"
                    @keydown.enter.prevent="scanBarcode()"
                    placeholder="Scan barcode + Enter"
                    class="w-56 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm font-mono"
                >
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    @click="selectedCategory = null"
                    :class="selectedCategory === null ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300'"
                    class="px-3 py-1 rounded-full text-xs font-medium"
                >All</button>
                <template x-for="category in categories" :key="category.id">
                    <button
                        type="button"
                        @click="selectedCategory = category.id"
                        :class="selectedCategory === category.id ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300'"
                        class="px-3 py-1 rounded-full text-xs font-medium"
                        x-text="category.name"
                    ></button>
                </template>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            <template x-for="product in filteredProducts()" :key="product.id">
                <button
                    type="button"
                    @click="addToCart(product)"
                    class="text-left bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden hover:ring-2 hover:ring-indigo-500 transition"
                >
                    <div class="h-20 w-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center overflow-hidden">
                        <img x-show="product.image_url" :src="product.image_url" class="h-full w-full object-cover" x-cloak>
                        <svg x-show="!product.image_url" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-8 w-8 text-gray-400 dark:text-gray-500">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                        </svg>
                    </div>
                    <div class="p-3">
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate" x-text="product.name"></div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1" x-text="formatMoney(product.selling_price) + ' / ' + product.selling_unit"></div>
                        <div class="text-xs mt-1" :class="product.stock_quantity <= 0 ? 'text-red-500 dark:text-red-400' : 'text-gray-400 dark:text-gray-500'" x-text="'Stock: ' + trimQty(product.stock_quantity)"></div>
                    </div>
                </button>
            </template>
            <template x-if="filteredProducts().length === 0">
                <p class="col-span-full text-sm text-gray-500 dark:text-gray-400 py-6 text-center">No products match.</p>
            </template>
        </div>
    </div>

    {{-- ============================== RIGHT: CART + PAYMENT ============================== --}}
    <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-4 space-y-4 lg:sticky lg:top-4">
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">Cart</h3>

        <div class="space-y-2 max-h-80 overflow-y-auto">
            <template x-for="(line, index) in cart" :key="line.product_id">
                <div class="flex items-center gap-2 text-sm border-b border-gray-100 dark:border-gray-700 pb-2">
                    <div class="flex-1 min-w-0">
                        <div class="truncate text-gray-800 dark:text-gray-100" x-text="line.name"></div>
                        <div class="text-xs text-gray-500 dark:text-gray-400" x-text="formatMoney(line.unit_price) + ' each'"></div>
                    </div>
                    <input type="number" min="0.001" step="0.001" x-model.number="line.quantity" @change="if (line.quantity <= 0) removeFromCart(index)" class="w-16 text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <div class="w-20 text-right tabular-nums text-gray-700 dark:text-gray-300" x-text="formatMoney(line.quantity * line.unit_price)"></div>
                    <button type="button" @click="removeFromCart(index)" class="text-red-500 hover:text-red-700">✕</button>
                </div>
            </template>
            <template x-if="cart.length === 0">
                <p class="text-sm text-gray-500 dark:text-gray-400 py-4 text-center">Cart is empty.</p>
            </template>
        </div>

        <div class="space-y-2 border-t border-gray-200 dark:border-gray-700 pt-3">
            <div>
                <label class="text-xs text-gray-500 dark:text-gray-400">Customer (required for credit)</label>
                <select x-model.number="customerId" @change="onCustomerChange()" class="mt-1 block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option :value="null">Walk-in customer</option>
                    <template x-for="customer in customers" :key="customer.id">
                        <option :value="customer.id" x-text="customer.name + (customer.credit_enabled ? ' (credit)' : '')"></option>
                    </template>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="text-xs text-gray-500 dark:text-gray-400">Discount</label>
                    <select x-model="discountType" class="mt-1 block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <option value="none">None</option>
                        <option value="fixed">Fixed</option>
                        <option value="percentage">Percentage</option>
                    </select>
                </div>
                <div x-show="discountType !== 'none'">
                    <label class="text-xs text-gray-500 dark:text-gray-400" x-text="discountType === 'percentage' ? 'Percent' : 'Amount'"></label>
                    <input type="number" min="0" step="0.01" x-model.number="discountValue" class="mt-1 block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                </div>
            </div>
            <input type="text" x-show="discountType !== 'none'" x-model="discountReason" placeholder="Discount reason" class="block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">

            <div>
                <label class="text-xs text-gray-500 dark:text-gray-400">Payment Method</label>
                <select x-model.number="paymentMethodId" class="mt-1 block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <template x-for="method in paymentMethods" :key="method.id">
                        <option :value="method.id" x-text="method.name"></option>
                    </template>
                </select>
            </div>

            <div x-show="isCredit() && selectedCustomer()">
                <p class="text-xs" :class="creditWouldExceedLimit() ? 'text-red-600' : 'text-gray-500 dark:text-gray-400'">
                    Available credit: <span x-text="selectedCustomer() ? formatMoney(selectedCustomer().credit_limit - selectedCustomer().outstanding_balance) : ''"></span>
                </p>
            </div>

            <div x-show="!isCredit()">
                <label class="text-xs text-gray-500 dark:text-gray-400" x-text="paymentMethodCode() === 'cash' ? 'Amount Tendered' : 'Reference Number'"></label>
                <template x-if="paymentMethodCode() === 'cash'">
                    <input type="number" min="0" step="0.01" x-model.number="tenderedAmount" class="mt-1 block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                </template>
                <template x-if="paymentMethodCode() !== 'cash'">
                    <input type="text" x-model="referenceNumber" class="mt-1 block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                </template>
            </div>
        </div>

        <div class="border-t border-gray-200 dark:border-gray-700 pt-3 space-y-1 text-sm">
            <div class="flex justify-between text-gray-600 dark:text-gray-400">
                <span>Subtotal</span><span x-text="formatMoney(subtotal())"></span>
            </div>
            <div class="flex justify-between text-gray-600 dark:text-gray-400" x-show="discountAmount() > 0">
                <span>Discount</span><span x-text="'-' + formatMoney(discountAmount())"></span>
            </div>
            <div class="flex justify-between text-gray-600 dark:text-gray-400" x-show="taxEnabled">
                <span>Tax</span><span x-text="formatMoney(taxAmount())"></span>
            </div>
            <div class="flex justify-between font-semibold text-gray-900 dark:text-gray-100 text-base">
                <span>Total</span><span x-text="formatMoney(total())"></span>
            </div>
            <div class="flex justify-between text-gray-500 dark:text-gray-400" x-show="paymentMethodCode() === 'cash' && tenderedAmount">
                <span>Change Due</span><span x-text="formatMoney(changeDue())"></span>
            </div>
        </div>

        <p x-show="errorMessage" x-text="errorMessage" class="text-sm text-red-600"></p>

        <button
            type="button"
            @click="completeSale()"
            :disabled="processing || cart.length === 0"
            class="w-full inline-flex justify-center items-center px-4 py-2 bg-green-500 dark:bg-green-500 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-300 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-black disabled:opacity-50 disabled:cursor-not-allowed"
        >
            <span x-show="!processing">Complete Sale</span>
            <span x-show="processing">Processing...</span>
        </button>
    </div>

    <div
        x-show="lastReceiptNumber"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-gray-500/75 dark:bg-gray-900/75 p-4"
    >
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-6 max-w-sm w-full text-center space-y-4">
            <div class="text-emerald-600 text-4xl">✓</div>
            <p class="text-gray-800 dark:text-gray-100 font-medium">Sale complete — <span x-text="lastReceiptNumber"></span></p>
            <div class="flex gap-3 justify-center">
                <a :href="'/sales/' + lastSaleId + '/receipt'" target="_blank" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500">
                    View Receipt
                </a>
                <button type="button" @click="lastReceiptNumber = null; lastSaleId = null" class="inline-flex items-center px-4 py-2 bg-gray-100 dark:bg-gray-700 border border-transparent rounded-md font-semibold text-xs text-gray-700 dark:text-gray-200 uppercase tracking-widest hover:bg-gray-200 dark:hover:bg-gray-600">
                    New Sale
                </button>
            </div>
        </div>
    </div>

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
            maxDiscountPercentage: config.maxDiscountPercentage,
            taxEnabled: config.taxEnabled,
            taxRate: config.taxRate,
            currencyCode: config.currencyCode,
            autoPrintReceipt: config.autoPrintReceipt,

            search: '',
            barcodeInput: '',
            selectedCategory: null,
            cart: [],
            customerId: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: '',
            paymentMethodId: config.defaultPaymentMethodId ?? (config.paymentMethods[0]?.id ?? null),
            referenceNumber: '',
            tenderedAmount: '',
            processing: false,
            errorMessage: '',
            lastReceiptNumber: null,
            lastSaleId: null,

            filteredProducts() {
                const term = this.search.trim().toLowerCase();

                return this.products.filter(p => {
                    if (this.selectedCategory !== null && p.category_id !== this.selectedCategory) return false;
                    if (term && !p.name.toLowerCase().includes(term)) return false;

                    return true;
                });
            },

            addToCart(product) {
                const existing = this.cart.find(l => l.product_id === product.id);

                if (existing) {
                    existing.quantity += 1;
                } else {
                    this.cart.push({
                        product_id: product.id,
                        name: product.name,
                        unit_price: product.selling_price,
                        quantity: 1,
                    });
                }

                this.errorMessage = '';
            },

            scanBarcode() {
                const code = this.barcodeInput.trim();
                this.barcodeInput = '';

                if (!code) return;

                const product = this.products.find(p => p.barcode === code);

                if (product) {
                    this.addToCart(product);
                } else {
                    this.errorMessage = `No product found for barcode "${code}".`;
                }

                this.$refs.barcodeInput.focus();
            },

            removeFromCart(index) {
                this.cart.splice(index, 1);
            },

            subtotal() {
                return this.cart.reduce((sum, l) => sum + (l.quantity * l.unit_price), 0);
            },

            discountAmount() {
                if (this.discountType === 'fixed') return Math.min(this.discountValue || 0, this.subtotal());
                if (this.discountType === 'percentage') return round2(this.subtotal() * (this.discountValue || 0) / 100);

                return 0;
            },

            taxAmount() {
                if (!this.taxEnabled) return 0;

                return round2((this.subtotal() - this.discountAmount()) * this.taxRate / 100);
            },

            total() {
                return round2(this.subtotal() - this.discountAmount() + this.taxAmount());
            },

            changeDue() {
                return round2((this.tenderedAmount || 0) - this.total());
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

            formatMoney(amount) {
                return this.currencyCode + ' ' + Number(amount ?? 0).toFixed(2);
            },

            trimQty(qty) {
                return Number(qty ?? 0).toFixed(3).replace(/\.?0+$/, '') || '0';
            },

            async completeSale() {
                this.errorMessage = '';

                if (this.cart.length === 0) return;

                if (this.isCredit() && !this.customerId) {
                    this.errorMessage = 'Credit sales require a customer to be selected.';
                    return;
                }

                this.processing = true;

                const result = await this.$wire.checkout(
                    this.cart.map(l => ({ product_id: l.product_id, quantity: l.quantity, unit_price: l.unit_price })),
                    this.customerId,
                    this.paymentMethodId,
                    this.paymentMethodCode() === 'cash' ? null : this.referenceNumber,
                    this.discountType,
                    this.discountValue || 0,
                    this.discountReason || null,
                );

                this.processing = false;

                if (!result.success) {
                    this.errorMessage = result.message;
                    return;
                }

                this.lastSaleId = result.saleId;
                this.lastReceiptNumber = result.receiptNumber;

                if (this.autoPrintReceipt) {
                    this.$refs.printFrame.src = '/sales/' + result.saleId + '/receipt?autoprint=1';
                }

                this.cart.forEach(line => {
                    const product = this.products.find(p => p.id === line.product_id);
                    if (product) {
                        product.stock_quantity = Math.max(0, product.stock_quantity - line.quantity);
                    }
                });

                this.cart = [];
                this.customerId = null;
                this.discountType = 'none';
                this.discountValue = 0;
                this.discountReason = '';
                this.referenceNumber = '';
                this.tenderedAmount = '';
            },
        };
    }

    function round2(value) {
        return Math.round((value + Number.EPSILON) * 100) / 100;
    }
</script>
