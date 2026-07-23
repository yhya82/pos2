<div>
    <div class="flex items-center justify-between gap-4 mb-4">
        <div class="w-full max-w-xs">
            <x-text-input wire:model.live.debounce.300ms="search" type="search" placeholder="Search by name or barcode..." class="w-full" />
        </div>

        @if (auth()->user()->hasPermission('products', 'create'))
            <x-primary-button wire:click="create">
                Create Product
            </x-primary-button>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-900/40">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Product</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Category</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Supplier</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Barcode</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Purchase Unit</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Selling Unit</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Stock</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Price</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Nearest Expiry</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($products as $product)
                    <tr wire:key="product-{{ $product->id }}">
                        <td class="pl-4 py-2 whitespace-nowrap">
                            @if ($product->imageUrl())
                                <img src="{{ $product->imageUrl() }}" alt="" class="h-9 w-9 rounded-md object-cover ring-1 ring-gray-200 dark:ring-gray-700">
                            @else
                                <div class="h-9 w-9 rounded-md bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                    <x-icon name="cube" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm font-medium whitespace-nowrap">
                            <a href="{{ route('products.show', $product) }}" wire:navigate class="text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400">{{ $product->name }}</a>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $product->category?->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $product->supplier?->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 font-mono whitespace-nowrap">{{ $product->barcode }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $product->purchaseUnit->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $product->sellingUnit->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 text-right tabular-nums whitespace-nowrap">{{ rtrim(rtrim(number_format($product->stock_quantity ?? 0, 3), '0'), '.') ?: '0' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 text-right tabular-nums whitespace-nowrap">{{ number_format($product->selling_price, 2) }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $product->nearest_expiry ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm whitespace-nowrap">
                            <span @class([
                                'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $product->status === 'active',
                                'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $product->status === 'inactive',
                            ])>
                                {{ ucfirst($product->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-right space-x-3 whitespace-nowrap">
                            @if (auth()->user()->hasPermission('products', 'update'))
                                <button wire:click="edit({{ $product->id }})" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium">
                                    Edit
                                </button>
                            @endif
                            @if (auth()->user()->hasPermission('products', 'delete'))
                                <button wire:click="confirmDeactivate({{ $product->id }})" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 font-medium">
                                    {{ $product->status === 'active' ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="12" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            No products found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
            {{ $products->links() }}
        </div>
    </div>

    <x-slide-over name="product-form" :title="$editingProductId ? 'Edit Product' : 'Create Product'">
        <form wire:submit="save" id="product-form" class="space-y-6">
            <div x-data="{ preview: null }" wire:key="photo-field-{{ $editingProductId ?? 'new' }}">
                <x-input-label value="Photo" />
                <div class="mt-1 flex items-center gap-4">
                    <div class="h-16 w-16 rounded-md bg-gray-100 dark:bg-gray-700 flex items-center justify-center overflow-hidden shrink-0">
                        <img x-show="preview" :src="preview" class="h-full w-full object-cover" x-cloak>
                        @if ($existingImagePath)
                            <img x-show="!preview" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($existingImagePath) }}" class="h-full w-full object-cover">
                        @else
                            <x-icon x-show="!preview" name="cube" class="h-6 w-6 text-gray-400 dark:text-gray-500" />
                        @endif
                    </div>
                    <div class="flex-1">
                        <input
                            type="file"
                            wire:model="photo"
                            accept="image/*"
                            x-on:change="preview = $event.target.files.length ? URL.createObjectURL($event.target.files[0]) : null"
                            class="block w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 dark:file:bg-indigo-900/40 dark:file:text-indigo-300 hover:file:bg-indigo-100"
                        >
                        <div wire:loading wire:target="photo" class="text-xs text-gray-400 mt-1">Uploading...</div>
                        @if ($existingImagePath)
                            <button type="button" wire:click="removePhoto" x-on:click="preview = null" class="text-xs text-red-600 hover:text-red-800 dark:text-red-400 mt-1">Remove photo</button>
                        @endif
                        <x-input-error :messages="$errors->get('photo')" class="mt-1" />
                    </div>
                </div>
            </div>

            <div>
                <x-input-label for="product_name" value="Name" />
                <x-text-input wire:model="name" id="product_name" class="block mt-1 w-full" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="product_description" value="Description" />
                <textarea wire:model="description" id="product_description" rows="2" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="product_category" value="Category" />
                    <select wire:model="categoryId" id="product_category" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">None</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('categoryId')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="product_supplier" value="Supplier" />
                    <select wire:model="supplierId" id="product_supplier" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">None</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('supplierId')" class="mt-2" />
                </div>
            </div>

            <div>
                <x-input-label for="product_barcode" value="Barcode" />
                <x-text-input wire:model="barcode" id="product_barcode" class="block mt-1 w-full" />
                <x-input-error :messages="$errors->get('barcode')" class="mt-2" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="product_purchase_unit" value="Purchase Unit" />
                    <select wire:model="purchaseUnitId" id="product_purchase_unit" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select...</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('purchaseUnitId')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="product_selling_unit" value="Selling Unit" />
                    <select wire:model="sellingUnitId" id="product_selling_unit" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select...</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('sellingUnitId')" class="mt-2" />
                </div>
            </div>

            <div>
                <x-input-label for="product_conversion_qty" value="Conversion Qty (1 purchase unit = ? selling units)" />
                <x-text-input wire:model="conversionQty" id="product_conversion_qty" class="block mt-1 w-full" />
                <x-input-error :messages="$errors->get('conversionQty')" class="mt-2" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="product_cost_price" value="Cost Price" />
                    <x-text-input wire:model="costPrice" id="product_cost_price" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('costPrice')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="product_selling_price" value="Selling Price" />
                    <x-text-input wire:model="sellingPrice" id="product_selling_price" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('sellingPrice')" class="mt-2" />
                </div>
            </div>

            <div>
                <x-input-label for="product_min_stock" value="Minimum Stock Level" />
                <x-text-input wire:model="minStockLevel" id="product_min_stock" class="block mt-1 w-full" />
                <x-input-error :messages="$errors->get('minStockLevel')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="product_status" value="Status" />
                <select wire:model="status" id="product_status" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </form>

        <x-slot name="footer">
            <x-secondary-button x-on:click="show = false">Cancel</x-secondary-button>
            <x-primary-button type="submit" form="product-form">Save</x-primary-button>
        </x-slot>
    </x-slide-over>

    <x-confirm-modal
        name="confirm-deactivate-product"
        title="Confirm"
        message="Are you sure? The product's sales history is kept, and it can be reactivated later — it just stops appearing in the POS product search while inactive."
        confirm-label="Confirm"
    >
        <x-danger-button wire:click="toggleStatus">Confirm</x-danger-button>
    </x-confirm-modal>
</div>
