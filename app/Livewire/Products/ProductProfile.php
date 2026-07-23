<?php

namespace App\Livewire\Products;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\SaleLineItem;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class ProductProfile extends Component
{
    use WithPagination, AuthorizesModuleActions;

    #[Locked]
    public int $productId;

    public string $activeTab = 'overview';

    public function mount(Product $product): void
    {
        $this->authorizeAction('products', 'view');

        $this->productId = $product->id;
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
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
        ]);
    }
}
