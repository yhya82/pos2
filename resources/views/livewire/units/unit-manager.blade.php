<div class="units-skin">
    <div class="top">
        <div>
            <h1>Units</h1>
            <p>Purchase and selling units products are measured in. e.g. Carton, Bottle, Kg</p>
        </div>
        @if (auth()->user()->hasPermission('products', 'create'))
            <button type="button" wire:click="create" class="btn p">
                <svg viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M12 5v14M5 12h14"/></svg>
                Create Unit
            </button>
        @endif
    </div>

    <div class="filters">
        <div class="search">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search units…">
        </div>
    </div>

    <div class="card">
        @php $showActions = auth()->user()->hasPermission('products', 'update') || auth()->user()->hasPermission('products', 'delete'); @endphp
        <table class="tbl">
            <thead>
                <tr>
                    <th>Name</th>
                    <th class="num">Products Using It</th>
                    <th>Status</th>
                    @if ($showActions)
                        <th class="actions">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($units as $unit)
                    <tr wire:key="unit-{{ $unit->id }}">
                        <td>{{ $unit->name }}</td>
                        <td class="num">{{ $unit->products_as_purchase_unit_count + $unit->products_as_selling_unit_count }}</td>
                        <td>
                            <span @class(['status', 'ok' => $unit->is_active, 'plain' => ! $unit->is_active])>{{ $unit->is_active ? 'Active' : 'Inactive' }}</span>
                        </td>
                        @if ($showActions)
                            <td class="actions">
                                @if (auth()->user()->hasPermission('products', 'update'))
                                    <button type="button" wire:click="edit({{ $unit->id }})" class="actionbtn">Edit</button>
                                @endif
                                @if (auth()->user()->hasPermission('products', 'delete'))
                                    <button type="button" wire:click="confirmDeactivate({{ $unit->id }})" class="actionbtn danger">
                                        {{ $unit->is_active ? 'Deactivate' : 'Reactivate' }}
                                    </button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showActions ? 4 : 3 }}" class="empty">No units found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="pager">
            <span>Showing {{ $units->firstItem() ?? 0 }}–{{ $units->lastItem() ?? 0 }} of {{ $units->total() }}</span>
            @if ($units->hasPages())
                <div class="pages">
                    @foreach ($units->getUrlRange(max(1, $units->currentPage() - 2), min($units->lastPage(), $units->currentPage() + 2)) as $page => $url)
                        <button type="button" wire:click="gotoPage({{ $page }})" aria-current="{{ $page === $units->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <x-modal name="unit-form" max-width="sm">
        <div class="dialog">
            <div class="dh">
                <h2>{{ $editingUnitId ? 'Edit Unit' : 'Create Unit' }}</h2>
            </div>
            <form wire:submit="save" id="unit-form">
                <div class="db">
                    <div class="f">
                        <label for="unit_name">Name<span class="req">*</span></label>
                        <input type="text" wire:model="name" id="unit_name" placeholder="e.g. Carton, Bottle, Kg">
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                </div>
            </form>
            <div class="df">
                <button type="button" class="btn" x-on:click="show = false">Cancel</button>
                <button type="submit" form="unit-form" class="btn p">Save</button>
            </div>
        </div>
    </x-modal>

    <x-modal name="confirm-deactivate-unit" max-width="sm">
        <div class="dialog">
            <div class="dh">
                <h2>Confirm</h2>
                <p>Are you sure? Products already using this unit are unaffected, but it stops being selectable for new products while inactive.</p>
            </div>
            <div class="df">
                <button type="button" class="btn" x-on:click="$dispatch('close')">Cancel</button>
                <button type="button" wire:click="toggleStatus" class="btn danger">Confirm</button>
            </div>
        </div>
    </x-modal>
</div>
