@php
    // One small inline-SVG set for the module header chips in the
    // permission matrix — purely decorative, so an unlisted/future
    // module just falls back to the generic folder icon rather than
    // breaking the page.
    $moduleIcons = [
        'sales' => '<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2V3z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'products' => '<path d="M3 7.5l9-4 9 4v9l-9 4-9-4v-9z"/><path d="M3 7.5l9 4 9-4"/><path d="M12 11.5V21"/>',
        'categories' => '<path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>',
        'suppliers' => '<rect x="2" y="7" width="12" height="9"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="6.5" cy="18" r="1.6"/><circle cx="16.5" cy="18" r="1.6"/>',
        'customers' => '<circle cx="12" cy="8" r="3.4"/><path d="M5.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/>',
        'inventory' => '<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 12l9 4 9-4"/><path d="M3 17l9 4 9-4"/>',
        'purchase_orders' => '<path d="M3 7.5l9-4 9 4v9l-9 4-9-4v-9z"/><path d="M9 15l6-6M9 9l6 6"/>',
        'returns' => '<path d="M9 14l-5-5 5-5"/><path d="M4 9h10a6 6 0 010 12h-2"/>',
        'discounts' => '<path d="M12.5 3.5h5a1 1 0 011 1v5a1 1 0 01-.3.7l-9 9a1 1 0 01-1.4 0l-5.5-5.5a1 1 0 010-1.4l9-9a1 1 0 01.7-.3z"/><circle cx="16.5" cy="7.5" r="1.3"/>',
        'supplier_claims' => '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/><path d="M12 8v5M12 15.3h.01"/>',
        'reports' => '<path d="M3 3v18h18"/><rect x="7" y="13" width="3" height="5"/><rect x="12" y="9" width="3" height="9"/><rect x="17" y="6" width="3" height="12"/>',
        'financials' => '<rect x="2.5" y="5.5" width="19" height="13" rx="1.5"/><path d="M2.5 9.5h19"/><path d="M6 14.5h4"/>',
        'users' => '<circle cx="12" cy="8" r="3.4"/><path d="M5.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/>',
        'roles' => '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/><path d="M12 8v5M12 15.3h.01"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'audit_logs' => '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4"/><path d="M9 12h6M9 16h6"/>',
    ];
    $fallbackIcon = '<path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>';
@endphp

<div class="users-roles-skin">
    <div class="top">
        <div>
            <h1>Roles</h1>
            <p>What each role can see and do assign permissions by module</p>
        </div>
        @if (auth()->user()->hasPermission('roles', 'create'))
            <button type="button" wire:click="create" class="btn p">
                <svg viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M12 5v14M5 12h14"/></svg>
                Create Role
            </button>
        @endif
    </div>

    <div class="filters">
        <div class="search">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search roles…">
        </div>
    </div>

    <div class="card">
        @php $showActions = auth()->user()->hasPermission('roles', 'update') || auth()->user()->hasPermission('roles', 'delete'); @endphp
        <table class="tbl">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th class="num">Users</th>
                    <th class="num">Permissions</th>
                    <th>Status</th>
                    @if ($showActions)
                        <th class="actions">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($roles as $role)
                    <tr wire:key="role-{{ $role->id }}">
                        <td style="font-weight:700;">{{ $role->name }}</td>
                        <td class="mut">{{ $role->description ?: '—' }}</td>
                        <td class="num">{{ $role->users_count }}</td>
                        <td class="num">{{ $role->permissions_count }}</td>
                        <td>
                            <span @class(['status', 'ok' => $role->status === 'active', 'plain' => $role->status !== 'active'])>{{ ucfirst($role->status) }}</span>
                        </td>
                        @if ($showActions)
                            <td class="actions">
                                @if (auth()->user()->hasPermission('roles', 'update'))
                                    <button type="button" wire:click="edit({{ $role->id }})" class="actionbtn">Edit</button>
                                @endif
                                @if (auth()->user()->hasPermission('roles', 'delete'))
                                    <button type="button" wire:click="confirmDeactivate({{ $role->id }})" class="actionbtn danger">
                                        {{ $role->status === 'active' ? 'Deactivate' : 'Reactivate' }}
                                    </button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showActions ? 6 : 5 }}" class="empty">No roles found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="pager">
            <span>Showing {{ $roles->firstItem() ?? 0 }}–{{ $roles->lastItem() ?? 0 }} of {{ $roles->total() }}</span>
            @if ($roles->hasPages())
                <div class="pages">
                    @foreach ($roles->getUrlRange(max(1, $roles->currentPage() - 2), min($roles->lastPage(), $roles->currentPage() + 2)) as $page => $url)
                        <button type="button" wire:click="gotoPage({{ $page }})" aria-current="{{ $page === $roles->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <x-slide-over name="role-form" :title="$editingRoleId ? 'Edit Role' : 'Create Role'" max-width="2xl">
        <form wire:submit="save" id="role-form">
            <div class="f grid2">
                <div>
                    <label for="role_name">Name<span class="req">*</span></label>
                    <input type="text" wire:model="name" id="role_name">
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>
                <div>
                    <label for="role_status">Status<span class="req">*</span></label>
                    <select wire:model="status" id="role_status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <div class="f">
                <label for="role_description">Description</label>
                <textarea wire:model="description" id="role_description" rows="2"></textarea>
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div class="permhead">
                <span class="lbl">Permissions</span>
            </div>
            <p class="hint" style="margin-top:-2px;">Grouped by module — check every action this role should be able to perform.</p>
            <div class="permlegend">
                <span><i class="view"></i>View</span>
                <span><i class="create"></i>Create</span>
                <span><i class="update"></i>Update</span>
                <span><i class="delete"></i>Delete</span>
            </div>

            @foreach ($permissionsByModule as $module => $permissions)
                <div class="permmod">
                    <div class="permmod-h">
                        <span class="ic">{!! '<svg viewBox="0 0 24 24">'.($moduleIcons[$module] ?? $fallbackIcon).'</svg>' !!}</span>
                        {{ str($module)->headline() }}
                    </div>
                    <div class="permmod-actions">
                        @foreach ($permissions as $permission)
                            <label class="permchk {{ $permission->action }}">
                                <input type="checkbox" wire:model="selectedPermissions" value="{{ $permission->id }}" @checked(in_array($permission->id, $selectedPermissions))>
                                <span class="dot"></span>
                                {{ ucfirst($permission->action) }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </form>

        <x-slot name="footer">
            <button type="button" x-on:click="show = false" class="btn">Cancel</button>
            <button type="submit" form="role-form" class="btn p">Save</button>
        </x-slot>
    </x-slide-over>

    <x-modal name="confirm-deactivate-role" max-width="sm">
        <div class="dialog">
            <div class="dh">
                <h2>Confirm</h2>
                <p>Are you sure? Users with this role are unaffected immediately, but the role stops being assignable to new users while inactive.</p>
            </div>
            <div class="df">
                <button type="button" class="btn" x-on:click="$dispatch('close')">Cancel</button>
                <button type="button" wire:click="toggleStatus" class="btn danger">Confirm</button>
            </div>
        </div>
    </x-modal>
</div>
