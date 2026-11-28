<?php

namespace App\Livewire\Pos;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\Category;
use App\Models\Customer;
use App\Models\GeneralSetting;
use App\Models\HardwareSetting;
use App\Models\ModuleSetting;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SalesSetting;
use App\Services\SaleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

class Terminal extends Component
{
    use AuthorizesModuleActions;

    public function mount(): void
    {
        $this->authorizeAction('sales', 'create');
    }

    /**
     * Keeps the product grid's stock badges, payment methods, and tax
     * config live across concurrent terminals — e.g. Cashier A selling the
     * last unit should make Cashier B's screen show "out of stock" without
     * a manual refresh. The cart itself lives in Alpine state on the
     * client, untouched by this.
     *
     * A plain re-render alone can't do this: Terminal's product/payment/tax
     * data is baked into Alpine's x-data once at initial load, and Alpine's
     * morph deliberately preserves an already-initialized x-data component
     * (so an in-progress cart survives routine re-renders) instead of
     * re-running it — so fresh server data in the HTML would otherwise
     * never reach the client's reactive state. Dispatching a browser event
     * with the fresh data lets terminal.blade.php's refreshLiveData() patch
     * its own Alpine state directly, the same way it already
     * self-adjusts stock after its own checkout().
     */
    #[On('echo-private:stock,.StockChanged')]
    #[On('echo-private:stock,.ProductPriceChanged')]
    #[On('echo-private:settings,.ModuleSettingChanged')]
    #[On('echo-private:settings,.GeneralSettingChanged')]
    #[On('echo-private:settings,.HardwareSettingChanged')]
    public function onLiveDataChanged(): void
    {
        $generalSettings = GeneralSetting::current();
        $hardwareSettings = HardwareSetting::current();

        $this->dispatch(
            'pos-live-update',
            products: $this->productsForClient(),
            paymentMethods: $this->paymentMethodsForClient(),
            taxEnabled: $generalSettings->tax_enabled,
            taxRate: (float) $generalSettings->tax_rate,
            currencyCode: $generalSettings->currency_code,
            barcodeScannerEnabled: (bool) $hardwareSettings?->barcode_scanner_enabled,
            autoPrintReceipt: (bool) $hardwareSettings?->auto_print_receipt,
            paperSize: $hardwareSettings?->paper_size ?? '80mm',
        );
    }

    private function productsForClient()
    {
        return Product::with(['category', 'sellingUnit'])
            ->withSum(['batches as stock_quantity' => fn ($q) => $q->where('status', 'active')], 'qty_remaining')
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'barcode', 'image_path', 'category_id', 'selling_price', 'selling_unit_id', 'min_stock_level', 'promo_discount_type', 'promo_discount_value', 'promo_starts_at', 'promo_ends_at'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'barcode' => $p->barcode,
                'image_url' => $p->imageUrl(),
                'category_id' => $p->category_id,
                'selling_price' => (float) $p->selling_price,
                'effective_price' => $p->effectiveSellingPrice(),
                'has_promo' => $p->hasActivePromo(),
                'selling_unit' => $p->sellingUnit->name,
                'stock_quantity' => (float) ($p->stock_quantity ?? 0),
                // Same rule the v_current_stock view uses (qty on hand <= the
                // product's own minimum) — no fallback to a store-wide
                // default, so this always agrees with the real low-stock
                // reports rather than inventing a second definition of "low".
                'min_stock_level' => (float) $p->min_stock_level,
            ])
            ->values();
    }

    private function paymentMethodsForClient()
    {
        return PaymentMethod::where('is_enabled', true)
            ->when(! ModuleSetting::enabled('customer_credit'), fn ($q) => $q->where('code', '!=', 'credit'))
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'code' => $m->code])
            ->values();
    }

    public function render()
    {
        $salesSettings = SalesSetting::current();
        $generalSettings = GeneralSetting::current();
        $hardwareSettings = HardwareSetting::current();

        return view('livewire.pos.terminal', [
            'products' => $this->productsForClient(),
            'categories' => Category::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::where('status', 'active')->orderBy('name')->get(['id', 'name', 'phone', 'credit_enabled', 'credit_limit', 'outstanding_balance']),
            'paymentMethods' => $this->paymentMethodsForClient(),
            'defaultPaymentMethodId' => $salesSettings->default_payment_method_id,
            'taxEnabled' => $generalSettings->tax_enabled,
            'taxRate' => (float) $generalSettings->tax_rate,
            'currencyCode' => $generalSettings->currency_code,
            'autoPrintReceipt' => (bool) $hardwareSettings?->auto_print_receipt,
            'barcodeScannerEnabled' => (bool) $hardwareSettings?->barcode_scanner_enabled,
            'paperSize' => $hardwareSettings?->paper_size ?? '80mm',
        ]);
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float|string}>  $cart
     * @return array{success: bool, message?: string, saleId?: int, receiptNumber?: string, receipt?: array{business: array, lines: array, totals: array, meta: array}}
     */
    public function checkout(
        array $cart,
        ?int $customerId,
        int $paymentMethodId,
        bool $paymentConfirmed,
        ?float $tenderedAmount,
        SaleService $saleService,
    ): array {
        $this->authorizeAction('sales', 'create');

        // Cash and credit have nothing to confirm — only a mobile money/bank
        // transfer method (Aps/Wave) actually requires the cashier to tick
        // that the payment came through before the sale can complete.
        $paymentMethod = PaymentMethod::find($paymentMethodId);

        if ($paymentMethod && in_array($paymentMethod->code, ['mobile_money', 'bank_transfer'], true) && ! $paymentConfirmed) {
            return ['success' => false, 'message' => 'Please confirm the payment was received before completing the sale.'];
        }

        try {
            $sale = $saleService->completeSale(
                $cart,
                $customerId,
                $paymentMethodId,
                $paymentConfirmed,
                // No discounts at the till: prices are the product's (including
                // any promotion set on the product itself), nothing more.
                'none',
                0.0,
                null,
                auth()->user(),
                $tenderedAmount,
            );
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (ModelNotFoundException $e) {
            // A crafted/direct call with a bogus product, customer, or
            // payment method id — the JS never sends one itself, since it
            // only ever submits ids from data this component already gave
            // it, but this still needs to come back as the same friendly
            // result shape rather than an uncaught 404/500.
            return ['success' => false, 'message' => "Couldn't complete the sale — one of the items, the customer, or the payment method no longer exists."];
        }

        // The receipt animation renders the real, already-persisted snapshot
        // — not a client-reconstructed one — so what's shown on screen can
        // never drift from what a reprint later shows.
        $receipt = $sale->receipt;

        return [
            'success' => true,
            'saleId' => $sale->id,
            'receiptNumber' => $sale->receipt_number,
            'receipt' => $receipt ? [
                'business' => $receipt->business_snapshot,
                'lines' => $receipt->line_items_snapshot,
                'totals' => $receipt->totals_snapshot,
                // Same receipt-number/date/cashier/customer line the real
                // printed receipt (sales/receipt.blade.php) shows — not part
                // of the receipt's own frozen snapshot (those columns live
                // on the sale itself), so pulled from $sale directly.
                'meta' => [
                    'receipt_number' => $sale->receipt_number,
                    'sale_date' => $sale->sale_date->toIso8601String(),
                    'cashier_name' => $sale->cashier->name,
                    'customer_name' => $sale->customer?->name,
                ],
            ] : null,
        ];
    }
}
