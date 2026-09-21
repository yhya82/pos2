<?php

namespace App\Livewire\Products;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\SaleLineItem;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\BatchCostService;
use App\Services\InventoryAdjustmentService;
use App\Services\ManualStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use App\Rules\WholeNumber;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use RuntimeException;

class ProductProfile extends Component
{
    use WithPagination, WithFileUploads, AuthorizesModuleActions;

    #[Locked]
    public int $productId;

    public string $activeTab = 'overview';

    // --- Edit product (slide-over; open/close state lives in Alpine, not here) ---
    public string $name = '';

    public string $description = '';

    public $photo = null;

    public ?string $existingImagePath = null;

    public ?string $originalImagePath = null;

    public ?int $categoryId = null;

    public ?int $supplierId = null;

    public string $barcode = '';

    public ?int $purchaseUnitId = null;

    public ?int $sellingUnitId = null;

    public string $conversionQty = '1.000';

    public string $costPrice = '0.00';

    public string $sellingPrice = '';

    public string $minStockLevel = '0.000';

    public string $status = 'active';

    // --- Add stock (no purchase order) ---
    public bool $showAddStockForm = false;

    public string $addStockQty = '';

    public string $addStockQtyUnit = 'purchase';

    public string $addStockUnitCost = '';

    public string $addStockSellingPrice = '';

    public string $addStockReceivedDate = '';

    public string $addStockExpiryDate = '';

    public string $addStockBatchCode = '';

    public string $addStockReason = '';

    // --- Batch cost correction (slide-over; open/close state lives in Alpine) ---
    public ?int $costCorrectionBatchId = null;

    public string $costCorrectionValue = '';

    public string $costCorrectionReason = '';

    public string $costCorrectionPrice = '';

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

    /**
     * Only one quick-action panel is ever open at a time — without this,
     * clicking Edit while Add Stock is still open stacked a second bordered
     * form directly underneath the first instead of replacing it. The Edit
     * slide-over's own open/close state lives in Alpine (like every other
     * slide-over in the app), so closing it from here is a dispatched
     * window event rather than a property flip.
     */
    private function closeAllForms(): void
    {
        $this->showAddStockForm = false;
        $this->showAdjustForm = false;
        $this->showDiscountForm = false;
        $this->dispatch('close-modal', 'edit-product');
    }

    public function openEditForm(): void
    {
        $this->authorizeAction('products', 'update');

        $this->closeAllForms();

        $product = Product::findOrFail($this->productId);

        $this->name = $product->name;
        $this->description = (string) $product->description;
        $this->photo = null;
        $this->existingImagePath = $product->image_path;
        $this->originalImagePath = $product->image_path;
        $this->categoryId = $product->category_id;
        $this->supplierId = $product->supplier_id;
        $this->barcode = (string) $product->barcode;
        $this->purchaseUnitId = $product->purchase_unit_id;
        $this->sellingUnitId = $product->selling_unit_id;
        $this->conversionQty = (string) $product->conversion_qty;
        $this->costPrice = (string) $product->cost_price;
        $this->sellingPrice = (string) $product->selling_price;
        $this->minStockLevel = (string) $product->min_stock_level;
        $this->status = $product->status;
        $this->resetValidation();

        $this->dispatch('open-modal', 'edit-product');
    }

    public function removePhoto(): void
    {
        $this->photo = null;
        $this->existingImagePath = null;
    }

    /**
     * Live "= X per {purchase unit}" reference hint under the Cost Price
     * field — costPrice is always entered/stored per selling unit now, so
     * this is just the reverse conversion for comparing against a
     * supplier's per-carton price. Same formula as
     * ProductManager::costPerPurchaseUnit().
     */
    public function costPerPurchaseUnit(): ?float
    {
        if ($this->purchaseUnitId === $this->sellingUnitId || ! is_numeric($this->costPrice)) {
            return null;
        }

        return (float) $this->costPrice * (float) $this->conversionQty;
    }

    public function submitEdit(): void
    {
        $this->authorizeAction('products', 'update');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'max:2048'],
            'categoryId' => ['required', 'exists:categories,id'],
            'supplierId' => ['required', 'exists:suppliers,id'],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('products', 'barcode')->ignore($this->productId)],
            'purchaseUnitId' => ['required', 'exists:units,id'],
            'sellingUnitId' => ['required', 'exists:units,id'],
            'conversionQty' => ['required', new WholeNumber(1)],
            'costPrice' => ['required', 'numeric', 'min:0', 'lt:sellingPrice'],
            'sellingPrice' => ['required', 'numeric', 'gt:0'],
            'minStockLevel' => ['required', new WholeNumber(0)],
            'status' => ['required', 'in:active,inactive'],
        ], [
            'sellingPrice.gt' => 'The selling price must be above 0.',
            'costPrice.lt' => "The cost price must be lower than the selling price — a product can't be sold at its cost.",
        ]);

        $product = Product::findOrFail($this->productId);

        // See ProductManager::save() — an existing promotion can end up
        // pricing the product below cost after a price/cost edit.
        if ($product->promo_discount_type !== 'none') {
            $promoPrice = Product::priceAfterDiscount((float) $validated['sellingPrice'], $product->promo_discount_type, (float) $product->promo_discount_value);

            if ($promoPrice < (float) $validated['costPrice']) {
                $this->addError('sellingPrice', 'With this product\'s current promotion the price would be '.number_format($promoPrice, 2).', below the cost of '.number_format((float) $validated['costPrice'], 2).'. Change the promotion first.');

                return;
            }
        }

        $imagePath = $this->existingImagePath;
        if ($this->photo) {
            if ($this->originalImagePath) {
                Storage::disk('public')->delete($this->originalImagePath);
            }
            $imagePath = $this->photo->store('products', 'public');
        } elseif ($this->originalImagePath && ! $this->existingImagePath) {
            Storage::disk('public')->delete($this->originalImagePath);
        }

        $attributes = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?: null,
            'image_path' => $imagePath,
            'category_id' => $validated['categoryId'],
            'supplier_id' => $validated['supplierId'],
            'barcode' => $validated['barcode'] ?: null,
            'purchase_unit_id' => $validated['purchaseUnitId'],
            'selling_unit_id' => $validated['sellingUnitId'],
            'conversion_qty' => $validated['conversionQty'],
            'cost_price' => $validated['costPrice'],
            'selling_price' => $validated['sellingPrice'],
            'min_stock_level' => $validated['minStockLevel'],
            'status' => $validated['status'],
        ];

        $previous = $product->only(array_keys($attributes));
        $product->update($attributes);

        AuditLog::record('update', 'products', 'Product', $product->id, $previous, $product->only(array_keys($attributes)));

        $this->dispatch('close-modal', 'edit-product');
        $this->dispatch('flash-message', message: 'Product updated.', variant: 'success');
    }

    public function openAddStockForm(): void
    {
        $this->authorizeAction('inventory', 'update');

        $this->closeAllForms();

        $this->reset(['addStockQty', 'addStockQtyUnit', 'addStockUnitCost', 'addStockExpiryDate', 'addStockBatchCode', 'addStockReason']);
        $this->addStockReceivedDate = now()->toDateString();

        // Start from what the product costs and sells for today; change
        // either when this delivery differs. The cost is per the unit the
        // quantity is entered in (purchase, by default).
        $product = Product::findOrFail($this->productId);
        $this->addStockUnitCost = $this->formatMoney($product->toPurchaseUnitCost((float) $product->cost_price, 'selling'));
        $this->addStockSellingPrice = $this->formatMoney((float) $product->selling_price);

        $this->showAddStockForm = true;
    }

    /**
     * Switching the quantity unit re-expresses whatever cost is typed (the
     * pre-filled one or the user's own) in the new unit, so it keeps meaning
     * the same money instead of silently becoming a per-pack/per-piece mix-up.
     */
    public function updatingAddStockQtyUnit(string $newUnit): void
    {
        if ($newUnit === $this->addStockQtyUnit || ! is_numeric($this->addStockUnitCost)) {
            return;
        }

        $product = Product::find($this->productId);

        if (! $product || (float) $product->conversion_qty <= 0) {
            return;
        }

        $perSellingUnit = $product->toSellingUnitCost((float) $this->addStockUnitCost, $this->addStockQtyUnit);
        $this->addStockUnitCost = $this->formatMoney(
            $newUnit === 'selling' ? $perSellingUnit : $product->toPurchaseUnitCost($perSellingUnit, 'selling')
        );
    }

    private function formatMoney(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.') ?: '0';
    }

    public function cancelAddStockForm(): void
    {
        $this->showAddStockForm = false;
    }

    public function submitAddStock(ManualStockService $service): void
    {
        $this->authorizeAction('inventory', 'update');

        $validated = $this->validate([
            'addStockQty' => ['required', new WholeNumber(1)],
            'addStockQtyUnit' => ['required', 'in:purchase,selling'],
            'addStockUnitCost' => ['required', 'numeric', 'min:0'],
            'addStockSellingPrice' => ['required', 'numeric', 'gt:0'],
            'addStockReceivedDate' => ['required', 'date'],
            'addStockExpiryDate' => ['nullable', 'date', 'after_or_equal:addStockReceivedDate'],
            'addStockBatchCode' => ['nullable', 'string', 'max:50'],
            'addStockReason' => ['nullable', 'string', 'max:255'],
        ]);

        $product = Product::findOrFail($this->productId);

        if (! $this->canEditPrices()) {
            // The product's own cost (in the unit entered) and price, not what was sent.
            $ownCost = $validated['addStockQtyUnit'] === 'selling'
                ? (float) $product->cost_price
                : $product->toPurchaseUnitCost((float) $product->cost_price, 'selling');
            $ownPrice = (float) $product->selling_price;

            if (abs((float) $validated['addStockUnitCost'] - $ownCost) > 0.005 || abs((float) $validated['addStockSellingPrice'] - $ownPrice) > 0.005) {
                AuditLog::tamperIgnored("Add Stock cost/price for \"{$product->name}\"",
                    ['unit_cost' => (float) $validated['addStockUnitCost'], 'selling_price' => (float) $validated['addStockSellingPrice']],
                    ['unit_cost' => $ownCost, 'selling_price' => $ownPrice]);
            }

            $validated['addStockUnitCost'] = $ownCost;
            $validated['addStockSellingPrice'] = $ownPrice;
        }

        try {
            $batch = $service->receive(
                $product,
                (float) $validated['addStockQty'],
                $validated['addStockQtyUnit'],
                (float) $validated['addStockUnitCost'],
                $validated['addStockReceivedDate'],
                $validated['addStockExpiryDate'] ?: null,
                $validated['addStockBatchCode'] ?: null,
                $validated['addStockReason'] ?: null,
                auth()->user(),
                (float) $validated['addStockSellingPrice'],
            );
        } catch (RuntimeException $e) {
            $this->addError('addStockSellingPrice', $e->getMessage());

            return;
        }

        $this->showAddStockForm = false;
        $this->reset(['addStockQty', 'addStockQtyUnit', 'addStockUnitCost', 'addStockSellingPrice', 'addStockExpiryDate', 'addStockBatchCode', 'addStockReason']);

        $warning = $product->fresh()->costAbovePriceWarning((float) $batch->unit_cost);

        $this->dispatch(
            'flash-message',
            message: 'Stock added.'.($warning ? ' Note: '.$warning : ''),
            variant: $warning ? 'warning' : 'success',
        );
    }

    /**
     * Costs and prices are what the books are built on: only someone who may
     * edit products can set them by hand. Everyone else gets the product's own
     * figures, whatever the form sent.
     */
    private function canEditPrices(): bool
    {
        return auth()->user()->hasPermission('products', 'update');
    }

    public function openCostCorrection(int $batchId): void
    {
        $this->authorizeAction('inventory', 'update');
        $this->authorizeAction('products', 'update');

        $batch = Batch::where('id', $batchId)->where('product_id', $this->productId)->firstOrFail();

        $this->costCorrectionBatchId = $batch->id;
        $this->costCorrectionValue = (string) $batch->unit_cost;
        $this->costCorrectionPrice = (string) Product::whereKey($this->productId)->value('selling_price');
        $this->costCorrectionReason = '';
        $this->resetValidation();

        $this->dispatch('open-modal', 'correct-batch-cost');
    }

    public function submitCostCorrection(BatchCostService $service): void
    {
        $this->authorizeAction('inventory', 'update');
        $this->authorizeAction('products', 'update');

        $this->validate([
            'costCorrectionValue' => ['required', 'numeric', 'min:0'],
            'costCorrectionPrice' => ['required', 'numeric', 'gt:0'],
            'costCorrectionReason' => ['required', 'string', 'max:255'],
        ], [
            'costCorrectionReason.required' => 'Say why this is being corrected — it goes in the audit log.',
            'costCorrectionPrice.gt' => 'The selling price must be above 0.',
        ]);

        $batch = Batch::where('id', $this->costCorrectionBatchId)->where('product_id', $this->productId)->firstOrFail();

        try {
            $note = $service->correct($batch, (float) $this->costCorrectionValue, $this->costCorrectionReason, (float) $this->costCorrectionPrice);
        } catch (RuntimeException $e) {
            $this->addError('costCorrectionReason', $e->getMessage());

            return;
        }

        $this->reset(['costCorrectionBatchId', 'costCorrectionValue', 'costCorrectionPrice', 'costCorrectionReason']);

        $this->dispatch('close-modal', 'correct-batch-cost');
        $this->dispatch('flash-message', message: trim('Batch cost corrected. '.($note ?? '')), variant: 'success');
    }

    public function openAdjustForm(string $type): void
    {
        $this->authorizeAction('inventory', 'update');

        $this->closeAllForms();

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
            'adjustQty' => ['required', new WholeNumber(1)],
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
        $this->authorizeAction('discounts', 'update');

        $this->closeAllForms();

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
        $this->authorizeAction('discounts', 'update');

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

        // A promotion may not price the product below what its stock cost.
        if ($validated['promoDiscountType'] !== 'none') {
            $floor = $product->breakEvenCost();
            $promoPrice = Product::priceAfterDiscount((float) $product->selling_price, $validated['promoDiscountType'], (float) $validated['promoDiscountValue']);

            if ($promoPrice < $floor) {
                $this->addError('promoDiscountValue', 'This discount would price it at '.number_format($promoPrice, 2).', below its cost of '.number_format($floor, 2).'. Use a smaller discount.');

                return;
            }
        }

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
        $this->authorizeAction('discounts', 'update');

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

    /**
     * Earlier cost corrections for the given batches, newest first, grouped
     * by batch id — BatchCostService is the only writer of an 'update' audit
     * entry on a Batch carrying a unit_cost, so that's what identifies one.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $batchIds
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection>
     */
    private function correctionsFor($batchIds)
    {
        if ($batchIds->isEmpty()) {
            return collect();
        }

        return AuditLog::with('user')
            ->where('module', 'inventory')
            ->where('record_type', 'Batch')
            ->where('action', 'update')
            ->whereIn('record_id', $batchIds)
            ->orderByDesc('id')
            ->get()
            ->filter(fn (AuditLog $log) => isset($log->previous_value['unit_cost'], $log->new_value['unit_cost']))
            ->groupBy('record_id');
    }

    /**
     * What a correction would do, worked out live as the new cost is typed —
     * so the person sees the before/after and the knock-on effect on reported
     * profit before committing to it, not after. Null new-cost means the
     * input isn't a number yet (nothing to preview).
     *
     * @return array<string, mixed>|null
     */
    private function costCorrectionPreview(Product $product): ?array
    {
        $batch = Batch::where('id', $this->costCorrectionBatchId)->where('product_id', $this->productId)->first();

        if (! $batch) {
            return null;
        }

        $old = (float) $batch->unit_cost;
        $new = is_numeric($this->costCorrectionValue) && (float) $this->costCorrectionValue >= 0
            ? (float) $this->costCorrectionValue
            : null;
        $priceOld = $product->effectiveSellingPrice();
        $listOld = (float) $product->selling_price;
        $listNew = is_numeric($this->costCorrectionPrice) && (float) $this->costCorrectionPrice > 0
            ? (float) $this->costCorrectionPrice
            : $listOld;
        $priceChanged = abs($listNew - $listOld) >= 0.005;
        // What a customer would actually pay after the change — promo-aware.
        $price = $priceChanged
            ? Product::priceAfterDiscount($listNew, $product->promo_discount_type, (float) $product->promo_discount_value)
            : $priceOld;
        $qty = (float) $batch->qty_remaining;

        // Units this batch has actually supplied to sales that still stand:
        // voided sales are excluded (their stock came back and the profit
        // report ignores them), and sellable returns put units back on the
        // shelf — the profit report gives their cost back — so they net out.
        $sold = (float) DB::table('sale_line_item_batches as slib')
            ->join('sale_line_items as sli', 'sli.id', '=', 'slib.sale_line_item_id')
            ->join('sales as s', 's.id', '=', 'sli.sale_id')
            ->where('slib.batch_id', $batch->id)
            ->where('s.status', '<>', 'voided')
            ->sum('slib.quantity_deducted');

        $returned = (float) DB::table('sales_return_line_item_batches as srlib')
            ->join('sales_return_line_items as srli', 'srli.id', '=', 'srlib.return_line_item_id')
            ->join('sales_returns as sr', 'sr.id', '=', 'srli.return_id')
            ->where('srlib.batch_id', $batch->id)
            ->where('srli.condition_type', 'sellable')
            ->where('sr.status', 'completed')
            ->sum('srlib.quantity');

        $netUnitsSold = max(0.0, $sold - $returned);

        $margin = fn (float $cost, float $at) => $at > 0 ? ($at - $cost) / $at * 100 : null;

        return [
            'batch' => $batch,
            'old' => $old,
            'new' => $new,
            'priceOld' => $priceOld,
            'price' => $price,
            'listOld' => $listOld,
            'listNew' => $listNew,
            'priceChanged' => $priceChanged,
            'qty' => $qty,
            // Only active batches count toward stock value / the dashboard.
            'countsTowardStock' => $batch->status === 'active',
            'unitProfitOld' => $priceOld - $old,
            'unitProfitNew' => $new === null ? null : $price - $new,
            'marginOld' => $margin($old, $priceOld),
            'marginNew' => $new === null ? null : $margin($new, $price),
            'valueOld' => $qty * $old,
            'valueNew' => $new === null ? null : $qty * $new,
            'netUnitsSold' => $netUnitsSold,
            'pastProfitChange' => $new === null ? null : ($old - $new) * $netUnitsSold,
            'costChanged' => $new !== null && abs($new - $old) >= 0.005,
            'changed' => $new !== null && (abs($new - $old) >= 0.005 || $priceChanged),
            'stillAbovePrice' => $new !== null && $new >= $price,
        ];
    }

    public function render()
    {
        $product = Product::with(['category', 'supplier', 'purchaseUnit', 'sellingUnit'])->findOrFail($this->productId);

        $batches = $this->activeTab === 'inventory'
            ? $product->batches()->orderByDesc('received_date')->paginate(10, pageName: 'batchesPage')
            : null;

        return view('livewire.products.product-profile', [
            'product' => $product,
            'stockOnHand' => (float) $product->batches()->where('status', 'active')->sum('qty_remaining'),
            'batches' => $batches,
            'movements' => $this->activeTab === 'movements'
                ? InventoryMovement::where('product_id', $this->productId)->with('user')->orderByDesc('created_at')->paginate(10, pageName: 'movementsPage')
                : null,
            'discountedLines' => $this->activeTab === 'discounts'
                ? SaleLineItem::where('product_id', $this->productId)->where('line_discount_amount', '>', 0)->with('sale')->orderByDesc('created_at')->paginate(10, pageName: 'discountsPage')
                : null,
            'availableBatches' => $this->showAdjustForm
                ? Batch::where('product_id', $this->productId)->where('status', 'active')->orderBy('expiry_date')->get()
                : null,
            'corrections' => $this->correctionsFor($this->activeTab === 'inventory' && $batches !== null
                ? $batches->pluck('id')
                : ($this->costCorrectionBatchId ? collect([$this->costCorrectionBatchId]) : collect())),
            'canEditPrices' => $this->canEditPrices(),
            'costPreview' => $this->costCorrectionBatchId ? $this->costCorrectionPreview($product) : null,
            // The edit slide-over is always present in the DOM (Alpine just
            // hides it), same as ProductManager's own create/edit modal —
            // so these load unconditionally rather than gated on a "form is
            // open" flag that no longer exists.
            'categories' => Category::where('status', 'active')->orderBy('name')->get(),
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(),
            'units' => Unit::where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
