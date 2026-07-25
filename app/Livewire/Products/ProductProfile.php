<?php

namespace App\Livewire\Products;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\SaleLineItem;
use App\Services\InventoryAdjustmentService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class ProductProfile extends Component
{
    use WithPagination, AuthorizesModuleActions;

    #[Locked]
    public int $productId;

    public string $activeTab = 'overview';

    // --- Stock adjustment ---
    public bool $showAdjustForm = false;

    public ?int $adjustBatchId = null;

    public string $adjustType = InventoryAdjustmentService::TYPE_CORRECTION_ADD;

    public string $adjustQty = '';

    public string $adjustReason = '';

    // --- Promotional discount ---
    public bool $showDiscountForm = false;

    public string $promoDiscountType = 'none';

    public string $promoDiscountValue = '';

    public string $promoStartsAt = '';

    public string $promoEndsAt = '';

    public function mount(Product $product): void
    {
        $this->authorizeAction('products', 'view');

        $this->productId = $product->id;
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function openAdjustForm(string $type): void
    {
        $this->authorizeAction('inventory', 'update');

        $this->reset(['adjustBatchId', 'adjustQty', 'adjustReason']);
        $this->adjustType = $type;
        $this->showAdjustForm = true;
    }

    public function cancelAdjustForm(): void
    {
        $this->showAdjustForm = false;
    }

    public function submitAdjustment(InventoryAdjustmentService $service): void
    {
        $this->authorizeAction('inventory', 'update');

        $this->validate([
            'adjustBatchId' => ['required', 'exists:batches,id'],
            'adjustType' => ['required', 'in:correction_add,correction_remove,damaged,expired'],
            'adjustQty' => ['required', 'numeric', 'gt:0'],
            'adjustReason' => ['required', 'string', 'max:255'],
        ]);

        $batch = Batch::where('id', $this->adjustBatchId)->where('product_id', $this->productId)->firstOrFail();

        try {
            $service->adjust($batch, $this->adjustType, (float) $this->adjustQty, $this->adjustReason, auth()->user());
        } catch (RuntimeException $e) {
            $this->dispatch('flash-message', message: $e->getMessage(), variant: 'error');

            return;
        }

        $this->showAdjustForm = false;
        $this->reset(['adjustBatchId', 'adjustQty', 'adjustReason']);
        $this->adjustType = InventoryAdjustmentService::TYPE_CORRECTION_ADD;

        $this->dispatch('flash-message', message: 'Stock adjustment recorded.', variant: 'success');
    }

    public function openDiscountForm(): void
    {
        $this->authorizeAction('products', 'update');

        $product = Product::findOrFail($this->productId);

        $this->promoDiscountType = $product->promo_discount_type;
        $this->promoDiscountValue = $product->promo_discount_value > 0 ? (string) $product->promo_discount_value : '';
        $this->promoStartsAt = $product->promo_starts_at?->toDateString() ?? '';
        $this->promoEndsAt = $product->promo_ends_at?->toDateString() ?? '';
        $this->showDiscountForm = true;
    }

    public function cancelDiscountForm(): void
    {
        $this->showDiscountForm = false;
    }

    public function saveDiscount(): void
    {
        $this->authorizeAction('products', 'update');

        $validated = $this->validate([
            'promoDiscountType' => ['required', 'in:none,fixed,percentage'],
            'promoDiscountValue' => ['required_unless:promoDiscountType,none', 'nullable', 'numeric', 'gt:0'],
            'promoStartsAt' => ['nullable', 'date'],
            'promoEndsAt' => ['nullable', 'date', 'after_or_equal:promoStartsAt'],
        ]);

        if ($validated['promoDiscountType'] === 'percentage' && (float) $validated['promoDiscountValue'] > 100) {
            $this->addError('promoDiscountValue', 'A percentage discount cannot exceed 100%.');

            return;
        }

        $product = Product::findOrFail($this->productId);

        $previous = $product->only(['promo_discount_type', 'promo_discount_value', 'promo_starts_at', 'promo_ends_at']);

        $product->update([
            'promo_discount_type' => $validated['promoDiscountType'],
            'promo_discount_value' => $validated['promoDiscountType'] === 'none' ? 0 : $validated['promoDiscountValue'],
            'promo_starts_at' => $validated['promoDiscountType'] === 'none' ? null : ($validated['promoStartsAt'] ?: null),
            'promo_ends_at' => $validated['promoDiscountType'] === 'none' ? null : ($validated['promoEndsAt'] ?: null),
        ]);

        AuditLog::record('update', 'products', 'Product', $product->id, $previous, $product->only([
            'promo_discount_type', 'promo_discount_value', 'promo_starts_at', 'promo_ends_at',
        ]));

        $this->showDiscountForm = false;

        $this->dispatch('flash-message', message: 'Promotional discount saved.', variant: 'success');
    }

    public function clearDiscount(): void
    {
        $this->authorizeAction('products', 'update');

        $product = Product::findOrFail($this->productId);

        $previous = $product->only(['promo_discount_type', 'promo_discount_value', 'promo_starts_at', 'promo_ends_at']);

        $product->update([
            'promo_discount_type' => 'none',
            'promo_discount_value' => 0,
            'promo_starts_at' => null,
            'promo_ends_at' => null,
        ]);

        AuditLog::record('update', 'products', 'Product', $product->id, $previous, $product->only([
            'promo_discount_type', 'promo_discount_value', 'promo_starts_at', 'promo_ends_at',
        ]));

        $this->showDiscountForm = false;

        $this->dispatch('flash-message', message: 'Promotional discount removed.', variant: 'success');
    }

    public function render()
    {
        $product = Product::with(['category', 'supplier', 'purchaseUnit', 'sellingUnit'])->findOrFail($this->productId);

        return view('livewire.products.product-profile', [
            'product' => $product,
            'stockOnHand' => (float) $product->batches()->where('status', 'active')->sum('qty_remaining'),
            'batches' => $this->activeTab === 'inventory'
                ? $product->batches()->orderByDesc('received_date')->paginate(10, pageName: 'batchesPage')
                : null,
            'movements' => $this->activeTab === 'movements'
                ? InventoryMovement::where('product_id', $this->productId)->with('user')->orderByDesc('created_at')->paginate(10, pageName: 'movementsPage')
                : null,
            'discountedLines' => $this->activeTab === 'discounts'
                ? SaleLineItem::where('product_id', $this->productId)->where('line_discount_amount', '>', 0)->with('sale')->orderByDesc('created_at')->paginate(10, pageName: 'discountsPage')
                : null,
            'availableBatches' => $this->showAdjustForm
                ? Batch::where('product_id', $this->productId)->where('status', 'active')->orderBy('expiry_date')->get()
                : null,
        ]);
    }
}
