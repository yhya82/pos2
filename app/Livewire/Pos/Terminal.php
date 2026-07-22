<?php

namespace App\Livewire\Pos;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\Category;
use App\Models\Customer;
use App\Models\GeneralSetting;
use App\Models\ModuleSetting;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SalesSetting;
use App\Services\SaleService;
use Livewire\Component;
use RuntimeException;

class Terminal extends Component
{
    use AuthorizesModuleActions;

    public function mount(): void
    {
        $this->authorizeAction('sales', 'create');
    }

    public function render()
    {
        $paymentMethods = PaymentMethod::where('is_enabled', true)
            ->when(! ModuleSetting::enabled('customer_credit'), fn ($q) => $q->where('code', '!=', 'credit'))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $salesSettings = SalesSetting::current();
        $generalSettings = GeneralSetting::current();

        return view('livewire.pos.terminal', [
            'products' => Product::with(['category', 'sellingUnit'])
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'barcode', 'category_id', 'selling_price', 'selling_unit_id']),
            'categories' => Category::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::where('status', 'active')->orderBy('name')->get(['id', 'name', 'phone', 'credit_enabled', 'credit_limit', 'outstanding_balance']),
            'paymentMethods' => $paymentMethods,
            'defaultPaymentMethodId' => $salesSettings->default_payment_method_id,
            'maxDiscountPercentage' => (float) $salesSettings->max_discount_percentage,
            'taxEnabled' => $generalSettings->tax_enabled,
            'taxRate' => (float) $generalSettings->tax_rate,
            'currencyCode' => $generalSettings->currency_code,
        ]);
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float|string, unit_price?: float|string}>  $cart
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
