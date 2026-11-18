<div class="categories-skin">
    <div class="top">
        <div>
            <h1>Categories</h1>
            <p>Group products for browsing and reporting</p>
        </div>
        @if (auth()->user()->hasPermission('categories', 'create'))
            <button type="button" wire:click="create" class="btn p">
                <svg viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M12 5v14M5 12h14"/></svg>
                Create Category
            </button>
        @endif
    </div>

    <div class="filters">
        <div class="search">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search categories…">
        </div>
    </div>

    <div class="card">
        @php $showActions = auth()->user()->hasPermission('categories', 'update') || auth()->user()->hasPermission('categories', 'delete'); @endphp
        <table class="tbl">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th class="num">Products</th>
                    <th>Status</th>
                    @if ($showActions)
                        <th class="actions">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($categories as $category)
                    <tr wire:key="category-{{ $category->id }}">
                        <td>{{ $category->name }}</td>
                        <td class="mut wrap">{{ $category->description ?: '—' }}</td>
                        <td class="num">{{ $category->products_count }}</td>
                        <td>
                            <span @class(['status', 'ok' => $category->status === 'active', 'plain' => $category->status !== 'active'])>{{ ucfirst($category->status) }}</span>
                        </td>
                        @if ($showActions)
                            <td class="actions">
                                @if (auth()->user()->hasPermission('categories', 'update'))
                                    <button type="button" wire:click="edit({{ $category->id }})" class="actionbtn">Edit</button>
                                @endif
                                @if (auth()->user()->hasPermission('categories', 'delete'))
                                    <button type="button" wire:click="confirmDeactivate({{ $category->id }})" class="actionbtn danger">
                                        {{ $category->status === 'active' ? 'Deactivate' : 'Reactivate' }}
                                    </button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showActions ? 5 : 4 }}" class="empty">No categories found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="pager">
            <span>Showing {{ $categories->firstItem() ?? 0 }}–{{ $categories->lastItem() ?? 0 }} of {{ $categories->total() }}</span>
            @if ($categories->hasPages())
                <div class="pages">
                    @foreach ($categories->getUrlRange(max(1, $categories->currentPage() - 2), min($categories->lastPage(), $categories->currentPage() + 2)) as $page => $url)
                        <button type="button" wire:click="gotoPage({{ $page }})" aria-current="{{ $page === $categories->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <x-modal name="category-form" max-width="sm">
        <div class="dialog">
            <div class="dh">
                <h2>{{ $editingCategoryId ? 'Edit Category' : 'Create Category' }}</h2>
            </div>
            <form wire:submit="save" id="category-form">
                <div class="db">
                    <div class="f">
                        <label for="category_name">Name<span class="req">*</span></label>
                        <input type="text" wire:model="name" id="category_name">
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div class="f">
                        <label for="category_description">Description</label>
                        <textarea wire:model="description" id="category_description" rows="3"></textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>

                    <div class="f">
                        <label for="category_status">Status<span class="req">*</span></label>
                        <select wire:model="status" id="category_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </form>
            <div class="df">
                <button type="button" class="btn" x-on:click="show = false">Cancel</button>
                <button type="submit" form="category-form" class="btn p">Save</button>
            </div>
        </div>
    </x-modal>

    <x-modal name="confirm-deactivate-category" max-width="sm">
        <div class="dialog">
            <div class="dh">
                <h2>Confirm</h2>
                <p>Are you sure? Products in this category keep their assignment but the category stops being selectable for new ones while inactive.</p>
            </div>
            <div class="df">
                <button type="button" class="btn" x-on:click="$dispatch('close')">Cancel</button>
                <button type="button" wire:click="toggleStatus" class="btn danger">Confirm</button>
            </div>
        </div>
    </x-modal>
</div>
