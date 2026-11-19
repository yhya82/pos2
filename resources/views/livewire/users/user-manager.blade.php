<div class="users-roles-skin">
    <div class="top">
        <div>
            <h1>Users</h1>
            <p>Everyone with a login,their role decides what they can do</p>
        </div>
        @if (auth()->user()->hasPermission('users', 'create'))
            <button type="button" wire:click="create" class="btn p">
                <svg viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M12 5v14M5 12h14"/></svg>
                Create User
            </button>
        @endif
    </div>

    <div class="filters">
        <div class="search">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search name, username or email…">
        </div>
    </div>

    <div class="card">
        @php $showActions = auth()->user()->hasPermission('users', 'update') || auth()->user()->hasPermission('users', 'delete'); @endphp
        <table class="tbl">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Status</th>
                    @if ($showActions)
                        <th class="actions">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    @php
                        $initials = collect(preg_split('/\s+/', trim($user->name)))->filter()->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->take(2)->implode('');
                    @endphp
                    <tr wire:key="user-{{ $user->id }}">
                        <td>
                            <div class="who">
                                <span class="avatar">{{ $initials ?: '?' }}</span>
                                <div>
                                    <div class="nm"><a href="{{ route('users.show', $user) }}" wire:navigate class="link">{{ $user->name }}</a></div>
                                    <div class="un">@{{ $user->username }}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="rolechip">
                                <svg viewBox="0 0 24 24"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/></svg>
                                {{ $user->role->name }}
                            </span>
                        </td>
                        <td class="mut">{{ $user->email ?: '—' }}</td>
                        <td class="mut">{{ $user->formattedPhone() }}</td>
                        <td>
                            <span @class(['status', 'ok' => $user->status === 'active', 'plain' => $user->status !== 'active'])>{{ ucfirst($user->status) }}</span>
                        </td>
                        @if ($showActions)
                            <td class="actions">
                                @if (auth()->user()->hasPermission('users', 'update'))
                                    <button type="button" wire:click="edit({{ $user->id }})" class="actionbtn">Edit</button>
                                @endif
                                @if (auth()->user()->hasPermission('users', 'delete'))
                                    <button type="button" wire:click="confirmDeactivate({{ $user->id }})" class="actionbtn danger">
                                        {{ $user->status === 'active' ? 'Deactivate' : 'Reactivate' }}
                                    </button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showActions ? 6 : 5 }}" class="empty">No users found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="pager">
            <span>Showing {{ $users->firstItem() ?? 0 }}–{{ $users->lastItem() ?? 0 }} of {{ $users->total() }}</span>
            @if ($users->hasPages())
                <div class="pages">
                    @foreach ($users->getUrlRange(max(1, $users->currentPage() - 2), min($users->lastPage(), $users->currentPage() + 2)) as $page => $url)
                        <button type="button" wire:click="gotoPage({{ $page }})" aria-current="{{ $page === $users->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <x-slide-over name="user-form" :title="$editingUserId ? 'Edit User' : 'Create User'">
        <form wire:submit="save" id="user-form">
            <div class="f">
                <label for="user_name">Name<span class="req">*</span></label>
                <input type="text" wire:model="name" id="user_name">
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="f">
                <label for="user_username">Username<span class="req">*</span></label>
                <input type="text" wire:model="username" id="user_username">
                <x-input-error :messages="$errors->get('username')" class="mt-2" />
            </div>

            <div class="f">
                <label for="user_email">Email</label>
                <input type="email" wire:model="email" id="user_email">
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div class="f">
                <label for="user_phone">Phone<span class="req">*</span></label>
                <div class="prefix">
                    <span>+220</span>
                    <input type="text" wire:model="phone" id="user_phone" placeholder="831234567" maxlength="9" inputmode="numeric">
                </div>
                <x-input-error :messages="$errors->get('phone')" class="mt-2" />
            </div>

            <div class="f grid2">
                <div>
                    <label for="user_role">Role<span class="req">*</span></label>
                    <select wire:model="roleId" id="user_role">
                        <option value="">Select...</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('roleId')" class="mt-2" />
                </div>
                <div>
                    <label for="user_status">Status<span class="req">*</span></label>
                    <select wire:model="status" id="user_status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <div class="f" style="margin-bottom:0;">
                <label for="user_password">{{ $editingUserId ? 'New Password (leave blank to keep current)' : 'Password' }}@unless ($editingUserId)<span class="req">*</span>@endunless</label>
                <input type="password" wire:model="password" id="user_password" autocomplete="new-password">
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>
        </form>

        <x-slot name="footer">
            <button type="button" x-on:click="show = false" class="btn">Cancel</button>
            <button type="submit" form="user-form" class="btn p">Save</button>
        </x-slot>
    </x-slide-over>

    <x-modal name="confirm-deactivate-user" max-width="sm">
        <div class="dialog">
            <div class="dh">
                <h2>Confirm</h2>
                <p>Are you sure? The account is deactivated, not deleted — its sales and audit history are kept, and it can be reactivated later.</p>
            </div>
            <div class="df">
                <button type="button" class="btn" x-on:click="$dispatch('close')">Cancel</button>
                <button type="button" wire:click="toggleStatus" class="btn danger">Confirm</button>
            </div>
        </div>
    </x-modal>
</div>
