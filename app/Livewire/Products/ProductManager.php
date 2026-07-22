<?php

namespace App\Livewire\Products;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class ProductManager extends Component
{
    use WithPagination, AuthorizesModuleActions;

    public string $search = '';

    public ?int $editingProductId = null;

    public string $name = '';

    public string $description = '';

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

    public ?int $productIdPendingDeactivation = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.products.product-manager', [
            'products' => Product::with(['category', 'supplier', 'purchaseUnit', 'sellingUnit'])
                ->withSum(['batches as stock_quantity' => fn ($q) => $q->where('status', 'active')], 'qty_remaining')
                ->withMin(['batches as nearest_expiry' => fn ($q) => $q->where('status', 'active')->whereNotNull('expiry_date')], 'expiry_date')
                ->when($this->search, fn ($query) => $this->applySearch($query))
                ->orderBy('name')
                ->paginate(10),
            'categories' => Category::where('status', 'active')->orderBy('name')->get(),
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(),
            'units' => Unit::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * FULLTEXT search on the name (ft_products_name) with a boolean-mode
     * prefix match, so it behaves like search-as-you-type — plus a plain
     * barcode LIKE, since a POS product search is often literally a
     * scanned barcode. FULLTEXT's minimum token length means short queries
     * (under 3 chars) fall back to a plain LIKE instead of silently
     * matching nothing.
     */
    private function applySearch($query): void
    {
        $term = trim($this->search);

        $query->where(function ($q) use ($term) {
            if (mb_strlen($term) >= 3) {
                $safe = preg_replace('/[+\-><()~*"@]+/', ' ', $term);
                $q->whereRaw('MATCH(name) AGAINST (? IN BOOLEAN MODE)', [$safe.'*']);
            } else {
                $q->where('name', 'like', "%{$term}%");
            }

            $q->orWhere('barcode', 'like', "%{$term}%");
        });
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'categoryId' => ['nullable', 'exists:categories,id'],
            'supplierId' => ['nullable', 'exists:suppliers,id'],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('products', 'barcode')->ignore($this->editingProductId)],
            'purchaseUnitId' => ['required', 'exists:units,id'],
            'sellingUnitId' => ['required', 'exists:units,id'],
            'conversionQty' => ['required', 'numeric', 'gt:0'],
            'costPrice' => ['required', 'numeric', 'min:0'],
            'sellingPrice' => ['required', 'numeric', 'min:0'],
            'minStockLevel' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    public function create(): void
    {
        $this->authorizeAction('products', 'create');

        $this->reset(['editingProductId', 'name', 'description', 'categoryId', 'supplierId', 'barcode', 'purchaseUnitId', 'sellingUnitId']);
        $this->conversionQty = '1.000';
        $this->costPrice = '0.00';
        $this->sellingPrice = '';
        $this->minStockLevel = '0.000';
        $this->status = 'active';
        $this->resetValidation();

        $this->dispatch('open-modal', 'product-form');
    }

    public function edit(int $productId): void
    {
        $this->authorizeAction('products', 'update');

        $product = Product::findOrFail($productId);

        $this->editingProductId = $product->id;
        $this->name = $product->name;
        $this->description = (string) $product->description;
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

        $this->dispatch('open-modal', 'product-form');
    }

    public function save(): void
    {
        $this->authorizeAction('products', $this->editingProductId ? 'update' : 'create');

        $validated = $this->validate();

        $attributes = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?: null,
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

        $previous = null;
        $isCreating = ! $this->editingProductId;

        if ($isCreating) {
            $product = Product::create($attributes);
        } else {
            $product = Product::findOrFail($this->editingProductId);
            $previous = $product->only(array_keys($attributes));
            $product->update($attributes);
        }

        AuditLog::record(
            $isCreating ? 'create' : 'update',
            'products',
            'Product',
            $product->id,
            $previous,
            $product->only(array_keys($attributes)),
        );

        $this->dispatch('close-modal', 'product-form');
        $this->dispatch('flash-message', message: $isCreating ? 'Product created.' : 'Product updated.', variant: 'success');
    }

    public function confirmDeactivate(int $productId): void
    {
        $this->authorizeAction('products', 'delete');

        $this->productIdPendingDeactivation = $productId;
        $this->dispatch('open-modal', 'confirm-deactivate-product');
    }

    public function toggleStatus(): void
    {
        $this->authorizeAction('products', 'delete');

        $product = Product::findOrFail($this->productIdPendingDeactivation);
        $previous = $product->only(['status']);

        $product->status = $product->status === 'active' ? 'inactive' : 'active';
        $product->save();

        AuditLog::record('update', 'products', 'Product', $product->id, $previous, $product->only(['status']));

        $this->dispatch('close-modal', 'confirm-deactivate-product');
        $this->dispatch('flash-message', message: $product->status === 'active' ? 'Product reactivated.' : 'Product deactivated.', variant: 'success');
        $this->productIdPendingDeactivation = null;
    }
}
