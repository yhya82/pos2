@php
    $money = fn ($amount) => new \Illuminate\Support\HtmlString(
        '<span class="ccy">'.e($currencyCode).'</span> '.number_format($amount, 2)
    );
@endphp

<div class="products-skin">
    <div class="top">
        <div>
            <h1>Products</h1>
            <p>The full catalog — every product on file, in or out of stock</p>
        </div>
        @if (auth()->user()->hasPermission('products', 'create'))
            <button type="button" wire:click="create" class="btn p">
                <svg viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M12 5v14M5 12h14"/></svg>
                Create Product
            </button>
        @endif
    </div>

    <div class="filters">
        <div class="search">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search by name or barcode…">
        </div>
    </div>

    <div class="card">
        @php $showActions = auth()->user()->hasPermission('products', 'update') || auth()->user()->hasPermission('products', 'delete'); @endphp
        <table class="tbl">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Supplier</th>
                    <th>Barcode</th>
                    <th>Units</th>
                    <th class="num">Stock</th>
                    <th class="num">Price</th>
                    <th>Nearest Expiry</th>
                    <th>Status</th>
                    @if ($showActions)
                        <th class="actions">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    @php $stock = (float) ($product->stock_quantity ?? 0); $low = $stock <= (float) $product->min_stock_level; @endphp
                    <tr wire:key="product-{{ $product->id }}">
                        <td>
                            <div class="prodcell">
                                <div class="thumb">
                                    @if ($product->imageUrl())
                                        <img src="{{ $product->imageUrl() }}" alt="">
                                    @else
                                        <svg viewBox="0 0 24 24"><path d="M21 7.5v9a1.5 1.5 0 01-.75 1.3l-7.5 4.33a1.5 1.5 0 01-1.5 0l-7.5-4.33A1.5 1.5 0 013 16.5v-9a1.5 1.5 0 01.75-1.3l7.5-4.33a1.5 1.5 0 011.5 0l7.5 4.33A1.5 1.5 0 0121 7.5z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/></svg>
                                    @endif
                                </div>
                                <a href="{{ route('products.show', $product) }}" wire:navigate class="link">{{ $product->name }}</a>
                            </div>
                        </td>
                        <td class="mut">{{ $product->category?->name ?? '—' }}</td>
                        <td class="mut">{{ $product->supplier?->name ?? '—' }}</td>
                        <td class="mono mut">{{ $product->barcode ?? '—' }}</td>
                        <td class="mut">{{ $product->purchaseUnit->name }} / {{ $product->sellingUnit->name }}</td>
                        <td class="num">
                            <span @class(['stockval', 'low' => $low])>{{ rtrim(rtrim(number_format($stock, 3), '0'), '.') ?: '0' }}</span>
                            @if ($low)<span class="pill-low">Low</span>@endif
                        </td>
                        <td class="num" @class(['mut' => $product->status !== 'active'])>{{ $money($product->selling_price) }}</td>
                        <td class="mut">{{ $product->nearest_expiry ?? '—' }}</td>
                        <td>
                            <span @class(['status', 'ok' => $product->status === 'active', 'plain' => $product->status !== 'active'])>{{ ucfirst($product->status) }}</span>
                        </td>
                        @if ($showActions)
                            <td class="actions">
                                @if (auth()->user()->hasPermission('products', 'update'))
                                    <button type="button" wire:click="edit({{ $product->id }})" class="actionbtn">Edit</button>
                                @endif
                                @if (auth()->user()->hasPermission('products', 'delete'))
                                    <button type="button" wire:click="confirmDeactivate({{ $product->id }})" class="actionbtn danger">
                                        {{ $product->status === 'active' ? 'Deactivate' : 'Reactivate' }}
                                    </button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showActions ? 10 : 9 }}" class="empty">No products found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="pager">
            <span>Showing {{ $products->firstItem() ?? 0 }}–{{ $products->lastItem() ?? 0 }} of {{ $products->total() }}</span>
            @if ($products->hasPages())
                <div class="pages">
                    @foreach ($products->getUrlRange(max(1, $products->currentPage() - 2), min($products->lastPage(), $products->currentPage() + 2)) as $page => $url)
                        <button type="button" wire:click="gotoPage({{ $page }})" aria-current="{{ $page === $products->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <x-slide-over name="product-form" :title="$editingProductId ? 'Edit Product' : 'Create Product'">
        <form wire:submit="save" id="product-form">
            <p class="hint" style="margin: -4px 0 16px;"><span class="req">*</span> required</p>

            <div class="f" x-data="{ preview: null }" wire:key="photo-field-{{ $editingProductId ?? 'new' }}">
                <label>Photo</label>
                <div class="photopick">
                    <div class="thumb" style="width:56px;height:56px;">
                        <img x-show="preview" :src="preview" x-cloak>
                        @if ($existingImagePath)
                            <img x-show="!preview" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($existingImagePath) }}">
                        @else
                            <svg x-show="!preview" viewBox="0 0 24 24"><path d="M21 7.5v9a1.5 1.5 0 01-.75 1.3l-7.5 4.33a1.5 1.5 0 01-1.5 0l-7.5-4.33A1.5 1.5 0 013 16.5v-9a1.5 1.5 0 01.75-1.3l7.5-4.33a1.5 1.5 0 011.5 0l7.5 4.33A1.5 1.5 0 0121 7.5z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/></svg>
                        @endif
                    </div>
                    <label for="product_photo" class="btn sm" style="cursor:pointer;">Choose file</label>
                    <input type="file" id="product_photo" wire:model="photo" accept="image/*" class="visually-hidden" x-on:change="preview = $event.target.files.length ? URL.createObjectURL($event.target.files[0]) : null">
                    @if ($existingImagePath)
                        <button type="button" wire:click="removePhoto" x-on:click="preview = null" class="actionbtn danger">Remove</button>
                    @endif
                </div>
                <div wire:loading wire:target="photo" class="hint">Uploading…</div>
                <x-input-error :messages="$errors->get('photo')" class="mt-2" />
            </div>

            <div class="f">
                <label for="product_name">Name<span class="req">*</span></label>
                <input type="text" wire:model="name" id="product_name">
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="f">
                <label for="product_description">Description</label>
                <textarea wire:model="description" id="product_description" rows="2"></textarea>
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div class="f grid2">
                <div>
                    <label for="product_category">Category<span class="req">*</span></label>
                    <select wire:model="categoryId" id="product_category">
                        <option value="">None</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('categoryId')" class="mt-2" />
                </div>
                <div>
                    <label for="product_supplier">Supplier<span class="req">*</span></label>
                    <select wire:model="supplierId" id="product_supplier">
                        <option value="">None</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('supplierId')" class="mt-2" />
                </div>
            </div>

            <div class="f">
                <label for="product_barcode">Barcode</label>
                <input type="text" wire:model="barcode" id="product_barcode">
                <x-input-error :messages="$errors->get('barcode')" class="mt-2" />
            </div>

            <div class="f grid2">
                <div>
                    <label for="product_purchase_unit">Purchase Unit<span class="req">*</span></label>
                    <select wire:model.live="purchaseUnitId" id="product_purchase_unit">
                        <option value="">Select…</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('purchaseUnitId')" class="mt-2" />
                </div>
                <div>
                    <label for="product_selling_unit">Selling Unit<span class="req">*</span></label>
                    <select wire:model.live="sellingUnitId" id="product_selling_unit">
                        <option value="">Select…</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('sellingUnitId')" class="mt-2" />
                </div>
            </div>

            <div class="f">
                <label for="product_conversion_qty">Conversion Qty<span class="req">*</span> (1 purchase unit = ? selling units)</label>
                <input type="text" wire:model.live="conversionQty" id="product_conversion_qty">
                <x-input-error :messages="$errors->get('conversionQty')" class="mt-2" />
            </div>

            <div class="f grid2">
                <div>
                    <label for="product_cost_price">Cost Price<span class="req">*</span>{{ $sellingUnitId ? ' (per '.($units->firstWhere('id', $sellingUnitId)?->name).')' : '' }}</label>
                    <input type="text" wire:model.live="costPrice" id="product_cost_price">
                    @if ($this->costPerPurchaseUnit() !== null)
                        <div class="hint">&asymp; {{ number_format($this->costPerPurchaseUnit(), 2) }} per {{ $units->firstWhere('id', $purchaseUnitId)?->name }}</div>
                    @endif
                    <x-input-error :messages="$errors->get('costPrice')" class="mt-2" />
                </div>
                <div>
                    <label for="product_selling_price">Selling Price<span class="req">*</span></label>
                    <input type="text" wire:model.live="sellingPrice" id="product_selling_price">
                    <x-input-error :messages="$errors->get('sellingPrice')" class="mt-2" />
                </div>
            </div>

            @php
                $marginCost = is_numeric($costPrice) ? (float) $costPrice : null;
                $marginPrice = is_numeric($sellingPrice) && (float) $sellingPrice > 0 ? (float) $sellingPrice : null;
            @endphp
            @if ($marginCost !== null && $marginPrice !== null)
                @if ($marginCost >= $marginPrice)
                    <div class="notice">Cost is not below the selling price — there'd be no profit.</div>
                @else
                    <div class="notice ok">Profit per {{ $units->firstWhere('id', $sellingUnitId)?->name }}: {{ number_format($marginPrice - $marginCost, 2) }} ({{ number_format(($marginPrice - $marginCost) / $marginPrice * 100, 1) }}% margin)</div>
                @endif
            @endif

            <div class="f">
                @php
                    $minStockHasDistinctUnits = $purchaseUnitId && $sellingUnitId && $purchaseUnitId !== $sellingUnitId;
                    $minStockPurchaseEquivalent = $minStockHasDistinctUnits && is_numeric($minStockLevel) && (float) $conversionQty > 0
                        ? rtrim(rtrim(number_format((float) $minStockLevel / (float) $conversionQty, 3), '0'), '.')
                        : null;
                @endphp
                <label for="product_min_stock">Minimum Stock Level<span class="req">*</span>{{ $sellingUnitId ? ' ('.$units->firstWhere('id', $sellingUnitId)?->name.')' : '' }}</label>
                <input type="text" wire:model.live="minStockLevel" id="product_min_stock">
                @if ($minStockPurchaseEquivalent !== null)
                    <div class="hint">&asymp; {{ $minStockPurchaseEquivalent }} {{ $units->firstWhere('id', $purchaseUnitId)?->name }}</div>
                @endif
                <x-input-error :messages="$errors->get('minStockLevel')" class="mt-2" />
            </div>

            <div class="f">
                <label for="product_status">Status<span class="req">*</span></label>
                <select wire:model="status" id="product_status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </form>

        <x-slot name="footer">
            <button type="button" x-on:click="show = false" class="btn">Cancel</button>
            <button type="submit" form="product-form" class="btn p">Save</button>
        </x-slot>
    </x-slide-over>

    <x-modal name="confirm-deactivate-product" max-width="sm">
        <div class="dialog">
            <div class="dh">
                <h2>Confirm</h2>
                <p>Are you sure? The product's sales history is kept, and it can be reactivated later — it just stops appearing in the POS product search while inactive.</p>
            </div>
            <div class="df">
                <button type="button" class="btn" x-on:click="$dispatch('close')">Cancel</button>
                <button type="button" wire:click="toggleStatus" class="btn danger">Confirm</button>
            </div>
        </div>
    </x-modal>
</div>
