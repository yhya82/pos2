<?php

namespace App\Livewire\Inventory;

use App\Events\ProductPriceChanged;
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
use Livewire\Attributes\On;
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

    // --- Stock valuation ---
    public string $valuationSearch = '';

    public ?int $valuationCategoryId = null;

    public bool $valuationInStockOnly = true;

    public string $valuationSortBy = 'value_at_selling_price';

    public string $valuationSortDirection = 'desc';

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

    #[On('echo-private:stock,.StockChanged')]
    public function onStockChanged(): void
    {
        // No-op — render() below re-queries fresh.
    }

    /**
     * Same audience as the dashboard's Inventory Value / Estimated Gross
     * Profit cards — the money value of stock is a financial figure, not an
     * operational one, so a Cashier doesn't see it.
     */
    private function canViewValuation(): bool
    {
        $user = auth()->user();

        return $user->hasPermission('inventory', 'view') && ! $user->isCashier();
    }

    public function sortValuation(string $column): void
    {
        if ($this->valuationSortBy === $column) {
            $this->valuationSortDirection = $this->valuationSortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->valuationSortBy = $column;
            $this->valuationSortDirection = $column === 'product_name' ? 'asc' : 'desc';
        }

        $this->resetPage('valuationPage');
    }

    public function updatingValuationSearch(): void
    {
        $this->resetPage('valuationPage');
    }

    public function updatingValuationCategoryId(): void
    {
        $this->resetPage('valuationPage');
    }

    public function updatingValuationInStockOnly(): void
    {
        $this->resetPage('valuationPage');
    }

    /**
     * One row per product straight from v_inventory_valuation — the same
     * view the dashboard's cards sum — so the totals row here and those
     * cards can never disagree. Cost is each batch's own cost; the selling
     * side is the price actually charged today (promo-aware).
     */
    private function valuationBaseQuery()
    {
        return DB::table('v_inventory_valuation as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('units as u', 'u.id', '=', 'p.selling_unit_id')
            ->when($this->valuationSearch, fn ($q) => $q->where('v.product_name', 'like', "%{$this->valuationSearch}%"))
            ->when($this->valuationCategoryId, fn ($q) => $q->where('p.category_id', $this->valuationCategoryId))
            ->when($this->valuationInStockOnly, fn ($q) => $q->where('v.qty_on_hand', '>', 0));
    }

    private function valuationQuery()
    {
        $sortable = ['product_name', 'qty_on_hand', 'value_at_cost', 'value_at_selling_price', 'estimated_gross_profit'];
        $sortBy = in_array($this->valuationSortBy, $sortable, true) ? $this->valuationSortBy : 'value_at_selling_price';
        $direction = $this->valuationSortDirection === 'asc' ? 'asc' : 'desc';

        return $this->valuationBaseQuery()
            ->select('v.*', 'u.name as selling_unit_name')
            ->orderBy("v.{$sortBy}", $direction)
            ->orderBy('v.product_name')
            ->paginate(15, pageName: 'valuationPage');
    }

    private function valuationTotals(): object
    {
        return $this->valuationBaseQuery()
            ->selectRaw('COUNT(*) AS product_count,
                COALESCE(SUM(v.value_at_cost), 0) AS value_at_cost,
                COALESCE(SUM(v.value_at_selling_price), 0) AS value_at_selling_price,
                COALESCE(SUM(v.estimated_gross_profit), 0) AS estimated_gross_profit')
            ->first();
    }

    public function render()
    {
        // A deep link (?tab=valuation) or stale tab state shouldn't expose
        // the valuation to someone who can't see it.
        $canViewValuation = $this->canViewValuation();

        if ($this->activeTab === 'valuation' && ! $canViewValuation) {
            $this->activeTab = 'stock';
        }

        return view('livewire.inventory.inventory-overview', [
            'canViewValuation' => $canViewValuation,
            'valuation' => $this->activeTab === 'valuation' ? $this->valuationQuery() : null,
            'valuationTotals' => $this->activeTab === 'valuation' ? $this->valuationTotals() : null,
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
            ->join('units', 'units.id', '=', 'products.selling_unit_id')
            ->when($this->stockSearch, fn ($q) => $q->where('v_current_stock.product_name', 'like', "%{$this->stockSearch}%"))
            ->when($this->stockCategoryId, fn ($q) => $q->where('products.category_id', $this->stockCategoryId))
            ->when($this->stockSupplierId, fn ($q) => $q->where('products.supplier_id', $this->stockSupplierId))
            ->when($this->lowStockOnly, fn ($q) => $q->where('v_current_stock.is_low_stock', 1))
            ->select('v_current_stock.*', 'units.name as selling_unit_name')
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
        $this->authorizeAction('discounts', 'update');

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

        // No product in the selection may end up priced below its cost.
        // All-or-nothing: applying it to some and skipping the rest would
        // leave the selection half-discounted with no clear record of which.
        $belowCost = Product::whereIn('id', $this->selectedProductIds)->get()
            ->map(function (Product $product) use ($validated) {
                $price = Product::priceAfterDiscount((float) $product->selling_price, $validated['bulkDiscountType'], (float) $validated['bulkDiscountValue']);
                $floor = $product->breakEvenCost();

                return $price < $floor ? "{$product->name} (would be ".number_format($price, 2).", cost ".number_format($floor, 2).')' : null;
            })
            ->filter()
            ->values();

        if ($belowCost->isNotEmpty()) {
            $shown = $belowCost->take(5)->implode('; ');
            $more = $belowCost->count() > 5 ? ' and '.($belowCost->count() - 5).' more' : '';

            $this->addError('bulkDiscountValue', "This discount would price {$belowCost->count()} product(s) below cost, so nothing was applied: {$shown}{$more}.");

            return;
        }

        $count = 0;

        $changedIds = [];

        DB::transaction(function () use ($validated, &$count, &$changedIds) {
            $products = Product::whereIn('id', $this->selectedProductIds)->get();

            // Per-product observer events are suppressed — one broadcast for
            // the whole batch below, not one (and one till refresh) each.
            Product::withoutEvents(function () use ($products, $validated, &$count, &$changedIds) {
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

                    $changedIds[] = $product->id;
                    $count++;
                }
            });
        });

        ProductPriceChanged::dispatchSafely($changedIds);

        $this->dispatch('flash-message', message: "Discount applied to {$count} product(s).", variant: 'success');
    }

    public function clearBulkDiscount(): void
    {
        $this->authorizeAction('discounts', 'update');

        if (empty($this->selectedProductIds)) {
            $this->dispatch('flash-message', message: 'Select at least one product first.', variant: 'error');

            return;
        }

        $count = 0;

        $changedIds = [];

        DB::transaction(function () use (&$count, &$changedIds) {
            $products = Product::whereIn('id', $this->selectedProductIds)->get();

            // See applyBulkDiscount() — one broadcast for the batch.
            Product::withoutEvents(function () use ($products, &$count, &$changedIds) {
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

                    $changedIds[] = $product->id;
                    $count++;
                }
            });
        });

        ProductPriceChanged::dispatchSafely($changedIds);

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
