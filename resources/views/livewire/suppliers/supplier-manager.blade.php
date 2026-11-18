<div class="suppliers-skin">
    <div class="top">
        <div>
            <h1>Suppliers</h1>
            <p>Everyone the store orders stock from</p>
        </div>
        @if (auth()->user()->hasPermission('suppliers', 'create'))
            <button type="button" wire:click="create" class="btn p">
                <svg viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M12 5v14M5 12h14"/></svg>
                Create Supplier
            </button>
        @endif
    </div>

    <div class="filters">
        <div class="search">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search name or phone…">
        </div>
    </div>

    @if ($phonesToFix > 0)
        <div class="notice"><strong>{{ $phonesToFix }}</strong> {{ $phonesToFix === 1 ? 'supplier has' : 'suppliers have' }} a phone number that needs updating — a valid number is +220 followed by 9 digits.</div>
    @endif

    <div class="card">
        @php $showActions = auth()->user()->hasPermission('suppliers', 'update') || auth()->user()->hasPermission('suppliers', 'delete'); @endphp
        <table class="tbl">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th class="num">Products</th>
                    <th>Status</th>
                    @if ($showActions)
                        <th class="actions">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($suppliers as $supplier)
                    <tr wire:key="supplier-{{ $supplier->id }}">
                        <td>{{ $supplier->name }}</td>
                        <td class="mut">
                            @if ($supplier->hasValidPhone())
                                {{ $supplier->formattedPhone() }}
                            @else
                                <span class="status warn" title="Not in the +220 + 9 digits form">Needs updating</span>
                                @if ($supplier->phone)
                                    <span class="mut" style="font-size:11px;">{{ $supplier->phone }}</span>
                                @endif
                            @endif
                        </td>
                        <td class="mut">{{ $supplier->email ?: '—' }}</td>
                        <td class="num">{{ $supplier->products_count }}</td>
                        <td>
                            <span @class(['status', 'ok' => $supplier->status === 'active', 'plain' => $supplier->status !== 'active'])>{{ ucfirst($supplier->status) }}</span>
                        </td>
                        @if ($showActions)
                            <td class="actions">
                                @if (auth()->user()->hasPermission('suppliers', 'update'))
                                    <button type="button" wire:click="edit({{ $supplier->id }})" class="actionbtn">Edit</button>
                                @endif
                                @if (auth()->user()->hasPermission('suppliers', 'delete'))
                                    <button type="button" wire:click="confirmDeactivate({{ $supplier->id }})" class="actionbtn danger">
                                        {{ $supplier->status === 'active' ? 'Deactivate' : 'Reactivate' }}
                                    </button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showActions ? 6 : 5 }}" class="empty">No suppliers found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="pager">
            <span>Showing {{ $suppliers->firstItem() ?? 0 }}–{{ $suppliers->lastItem() ?? 0 }} of {{ $suppliers->total() }}</span>
            @if ($suppliers->hasPages())
                <div class="pages">
                    @foreach ($suppliers->getUrlRange(max(1, $suppliers->currentPage() - 2), min($suppliers->lastPage(), $suppliers->currentPage() + 2)) as $page => $url)
                        <button type="button" wire:click="gotoPage({{ $page }})" aria-current="{{ $page === $suppliers->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <x-slide-over name="supplier-form" :title="$editingSupplierId ? 'Edit Supplier' : 'Create Supplier'">
        <form wire:submit="save" id="supplier-form">
            <div class="f">
                <label for="supplier_name">Name<span class="req">*</span></label>
                <input type="text" wire:model="name" id="supplier_name">
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="f">
                <label for="supplier_phone">Phone<span class="req">*</span></label>
                <div class="prefix">
                    <span>+220</span>
                    <input type="text" wire:model="phone" id="supplier_phone" placeholder="831234567" maxlength="9" inputmode="numeric">
                </div>
                @if ($legacyPhone !== '')
                    <div class="hint">On file: {{ $legacyPhone }} — that isn't a full number. Enter the 9 digits.</div>
                @endif
                <x-input-error :messages="$errors->get('phone')" class="mt-2" />
            </div>

            <div class="f">
                <label for="supplier_email">Email</label>
                <input type="email" wire:model="email" id="supplier_email">
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div class="f">
                <label for="supplier_address">Address</label>
                <textarea wire:model="address" id="supplier_address" rows="2"></textarea>
                <x-input-error :messages="$errors->get('address')" class="mt-2" />
            </div>

            <div class="f">
                <label for="supplier_notes">Notes</label>
                <textarea wire:model="notes" id="supplier_notes" rows="2"></textarea>
                <x-input-error :messages="$errors->get('notes')" class="mt-2" />
            </div>

            <div class="f">
                <label for="supplier_status">Status<span class="req">*</span></label>
                <select wire:model="status" id="supplier_status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </form>

        <x-slot name="footer">
            <button type="button" x-on:click="show = false" class="btn">Cancel</button>
            <button type="submit" form="supplier-form" class="btn p">Save</button>
        </x-slot>
    </x-slide-over>

    <x-modal name="confirm-deactivate-supplier" max-width="sm">
        <div class="dialog">
            <div class="dh">
                <h2>Confirm</h2>
                <p>Are you sure? Products from this supplier keep their assignment but it stops being selectable for new ones while inactive.</p>
            </div>
            <div class="df">
                <button type="button" class="btn" x-on:click="$dispatch('close')">Cancel</button>
                <button type="button" wire:click="toggleStatus" class="btn danger">Confirm</button>
            </div>
        </div>
    </x-modal>
</div>
