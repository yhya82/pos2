<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Category;
use App\Models\CurrentStock;
use App\Models\BatchExpiry;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\InventoryAdjustmentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class InventoryOverview extends Component
{
    use WithPagination, AuthorizesModuleActions;

    #[Url(as: 'tab')]
    public string $activeTab = 'stock';

    // --- Stock overview ---
    public string $stockSearch = '';

    public ?int $stockCategoryId = null;

    public ?int $stockSupplierId = null;

    #[Url(as: 'low_stock')]
    public bool $lowStockOnly = false;

    // --- Movement history ---
    public ?int $movementProductId = null;

    public string $movementType = '';

    public string $movementDateFrom = '';

    public string $movementDateTo = '';

    // --- Stock adjustment ---
    public ?int $adjustProductId = null;

    public ?int $adjustBatchId = null;

    public string $adjustType = InventoryAdjustmentService::TYPE_CORRECTION_ADD;

    public string $adjustQty = '';

    public string $adjustReason = '';

    // --- Expiry tracking ---
    public string $expirySearch = '';

    public string $expiryWithinDays = '';

    // --- Bulk discounts ---
    public string $discountSearch = '';

    public ?int $discountCategoryId = null;

    public ?int $discountSupplierId = null;

    public array $selectedProductIds = [];

    public string $bulkDiscountType = 'percentage';

    public string $bulkDiscountValue = '';

    public string $bulkStartsAt = '';

    public string $bulkEndsAt = '';

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function render()
    {
        return view('livewire.inventory.inventory-overview', [
            'stock' => $this->activeTab === 'stock' ? $this->stockQuery() : null,
            'movements' => $this->activeTab === 'movements' ? $this->movementsQuery() : null,
            'expiring' => $this->activeTab === 'expiry' ? $this->expiryQuery() : null,
            'discountProducts' => $this->activeTab === 'discounts' ? $this->discountProductsQuery() : null,
            'categories' => Category::where('status', 'active')->orderBy('name')->get(),
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(),
            'products' => Product::where('status', 'active')->orderBy('name')->get(),
            'movementTypes' => ['stock_received', 'sale', 'return', 'damaged', 'expired', 'adjustment'],
            'availableBatches' => $this->availableBatches(),
        ]);
    }

    private function stockQuery()
    {
        return CurrentStock::query()
            ->join('products', 'products.id', '=', 'v_current_stock.product_id')
            ->when($this->stockSearch, fn ($q) => $q->where('v_current_stock.product_name', 'like', "%{$this->stockSearch}%"))
            ->when($this->stockCategoryId, fn ($q) => $q->where('products.category_id', $this->stockCategoryId))
            ->when($this->stockSupplierId, fn ($q) => $q->where('products.supplier_id', $this->stockSupplierId))
            ->when($this->lowStockOnly, fn ($q) => $q->where('v_current_stock.is_low_stock', 1))
            ->select('v_current_stock.*')
            ->orderBy('v_current_stock.product_name')
            ->paginate(10, pageName: 'stockPage');
    }

    private function movementsQuery()
    {
        return InventoryMovement::with(['product', 'batch', 'user'])
            ->when($this->movementProductId, fn ($q) => $q->where('product_id', $this->movementProductId))
            ->when($this->movementType, fn ($q) => $q->where('movement_type', $this->movementType))
            ->when($this->movementDateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->movementDateFrom))
            ->when($this->movementDateTo, fn ($q) => $q->whereDate('created_at', '<=', $this->movementDateTo))
            ->orderByDesc('created_at')
            ->paginate(15, pageName: 'movementsPage');
    }

    private function expiryQuery()
    {
        return BatchExpiry::query()
            ->when($this->expirySearch, fn ($q) => $q->where('product_name', 'like', "%{$this->expirySearch}%"))
            ->when($this->expiryWithinDays, fn ($q) => $q->where('days_to_expiry', '<=', (int) $this->expiryWithinDays))
            ->orderBy('days_to_expiry')
            ->paginate(15, pageName: 'expiryPage');
    }

    private function filteredDiscountProducts()
    {
        return Product::query()
            ->when($this->discountSearch, fn ($q) => $q->where('name', 'like', "%{$this->discountSearch}%"))
            ->when($this->discountCategoryId, fn ($q) => $q->where('category_id', $this->discountCategoryId))
            ->when($this->discountSupplierId, fn ($q) => $q->where('supplier_id', $this->discountSupplierId))
            ->orderBy('name');
    }

    private function discountProductsQuery()
    {
        return $this->filteredDiscountProducts()->paginate(10, pageName: 'discountsPage');
    }

    public function updatingDiscountSearch(): void
    {
        $this->resetPage('discountsPage');
    }

    public function updatingDiscountCategoryId(): void
    {
        $this->resetPage('discountsPage');
    }

    public function updatingDiscountSupplierId(): void
    {
        $this->resetPage('discountsPage');
    }

    public function selectAllFiltered(): void
    {
        $this->selectedProductIds = $this->filteredDiscountProducts()->pluck('id')->all();
    }

    public function clearSelection(): void
    {
        $this->selectedProductIds = [];
    }

    public function applyBulkDiscount(): void
    {
        $this->authorizeAction('products', 'update');

        if (empty($this->selectedProductIds)) {
            $this->dispatch('flash-message', message: 'Select at least one product first.', variant: 'error');

            return;
        }

        $validated = $this->validate([
            'bulkDiscountType' => ['required', 'in:fixed,percentage'],
            'bulkDiscountValue' => ['required', 'numeric', 'gt:0'],
            'bulkStartsAt' => ['nullable', 'date'],
            'bulkEndsAt' => ['nullable', 'date', 'after_or_equal:bulkStartsAt'],
        ]);

        if ($validated['bulkDiscountType'] === 'percentage' && (float) $validated['bulkDiscountValue'] > 100) {
            $this->addError('bulkDiscountValue', 'A percentage discount cannot exceed 100%.');

            return;
        }

        $count = 0;

        DB::transaction(function () use ($validated, &$count) {
            $products = Product::whereIn('id', $this->selectedProductIds)->get();

            foreach ($products as $product) {
                $previous = $product->only(['promo_discount_type', 'promo_discount_value', 'promo_starts_at', 'promo_ends_at']);

                $product->update([
                    'promo_discount_type' => $validated['bulkDiscountType'],
                    'promo_discount_value' => $validated['bulkDiscountValue'],
                    'promo_starts_at' => $validated['bulkStartsAt'] ?: null,
                    'promo_ends_at' => $validated['bulkEndsAt'] ?: null,
                ]);

                AuditLog::record('update', 'products', 'Product', $product->id, $previous, $product->only([
                    'promo_discount_type', 'promo_discount_value', 'promo_starts_at', 'promo_ends_at',
                ]));

                $count++;
            }
        });

        $this->dispatch('flash-message', message: "Discount applied to {$count} product(s).", variant: 'success');
    }

    public function clearBulkDiscount(): void
    {
        $this->authorizeAction('products', 'update');

        if (empty($this->selectedProductIds)) {
            $this->dispatch('flash-message', message: 'Select at least one product first.', variant: 'error');

            return;
        }

        $count = 0;

        DB::transaction(function () use (&$count) {
            $products = Product::whereIn('id', $this->selectedProductIds)->get();

            foreach ($products as $product) {
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

                $count++;
            }
        });

        $this->dispatch('flash-message', message: "Discount removed from {$count} product(s).", variant: 'success');
    }

    private function availableBatches(): Collection
    {
        if (! $this->adjustProductId) {
            return collect();
        }

        return Batch::where('product_id', $this->adjustProductId)
            ->where('status', 'active')
            ->orderBy('expiry_date')
            ->get();
    }

    public function updatedAdjustProductId(): void
    {
        $this->adjustBatchId = null;
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

        $batch = Batch::findOrFail($this->adjustBatchId);

        try {
            $service->adjust($batch, $this->adjustType, (float) $this->adjustQty, $this->adjustReason, auth()->user());
        } catch (RuntimeException $e) {
            $this->dispatch('flash-message', message: $e->getMessage(), variant: 'error');

            return;
        }

        $this->reset(['adjustBatchId', 'adjustQty', 'adjustReason']);
        $this->adjustType = InventoryAdjustmentService::TYPE_CORRECTION_ADD;

        $this->dispatch('flash-message', message: 'Stock adjustment recorded.', variant: 'success');
    }
}
