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
        );
    }

    private function productsForClient()
    {
        return Product::with(['category', 'sellingUnit'])
            ->withSum(['batches as stock_quantity' => fn ($q) => $q->where('status', 'active')], 'qty_remaining')
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'barcode', 'image_path', 'category_id', 'selling_price', 'selling_unit_id', 'promo_discount_type', 'promo_discount_value', 'promo_starts_at', 'promo_ends_at'])
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

        return view('livewire.pos.terminal', [
            'products' => $this->productsForClient(),
            'categories' => Category::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::where('status', 'active')->orderBy('name')->get(['id', 'name', 'phone', 'credit_enabled', 'credit_limit', 'outstanding_balance']),
            'paymentMethods' => $this->paymentMethodsForClient(),
            'defaultPaymentMethodId' => $salesSettings->default_payment_method_id,
            'maxDiscountPercentage' => (float) $salesSettings->max_discount_percentage,
            'taxEnabled' => $generalSettings->tax_enabled,
            'taxRate' => (float) $generalSettings->tax_rate,
            'currencyCode' => $generalSettings->currency_code,
            'autoPrintReceipt' => (bool) HardwareSetting::current()?->auto_print_receipt,
            'barcodeScannerEnabled' => (bool) HardwareSetting::current()?->barcode_scanner_enabled,
        ]);
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float|string}>  $cart
     * @return array{success: bool, message?: string, saleId?: int, receiptNumber?: string}
     */
    public function checkout(
        array $cart,
        ?int $customerId,
        int $paymentMethodId,
        ?string $referenceNumber,
        string $discountType,
        float $discountValue,
        ?string $discountReason,
        SaleService $saleService,
    ): array {
        $this->authorizeAction('sales', 'create');

        try {
            $sale = $saleService->completeSale(
                $cart,
                $customerId,
                $paymentMethodId,
                $referenceNumber,
                $discountType,
                $discountValue,
                $discountReason,
                auth()->user(),
            );
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'saleId' => $sale->id, 'receiptNumber' => $sale->receipt_number];
    }
}
