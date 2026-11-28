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
                                    <div class="un">&commat;{{ $user->username }}</div>
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
                                    <button type="button" wire:click="changePassword({{ $user->id }})" class="actionbtn warn"><svg viewBox="0 0 24 24"><path d="M21 2l-9.6 9.6"/><path d="M15.5 7.5l3 3"/><circle cx="7.5" cy="15.5" r="5.5"/></svg>Change Password</button>
                                @endif
                                @if (auth()->user()->hasPermission('users', 'delete'))
                                    @if ($user->isOnlyActiveAdministrator())
                                        <button type="button" class="actionbtn danger" disabled title="This is the only active Administrator — assign another Administrator first.">Deactivate</button>
                                    @else
                                        <button type="button" wire:click="confirmDeactivate({{ $user->id }})" class="actionbtn danger">
                                            {{ $user->status === 'active' ? 'Deactivate' : 'Reactivate' }}
                                        </button>
                                    @endif
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
                    <select wire:model="roleId" id="user_role" @disabled($editingUserIsOnlyActiveAdministrator)>
                        <option value="">Select...</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </select>
                    @if ($editingUserIsOnlyActiveAdministrator)
                        <div class="hint">This is the only active Administrator — assign another Administrator first before changing this account's role.</div>
                    @endif
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

            {{-- Password fields only apply to a brand-new account — changing
                 an existing user's password is now exclusively the
                 standalone "Change Password" action (see changePassword()/
                 savePassword()), so editing never shows them at all. --}}
            @unless ($editingUserId)
                <div class="f" style="margin-bottom:0;">
                    <label for="user_password">Password<span class="req">*</span></label>
                    <input type="password" wire:model="password" id="user_password" autocomplete="new-password">
                    <div class="hint">{{ $passwordPolicyHint }}</div>
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>
            @endunless
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
                <p>Are you sure? The account is deactivated, not deleted, its sales and audit history are kept, and it can be reactivated later.</p>
            </div>
            <div class="df">
                <button type="button" class="btn" x-on:click="$dispatch('close')">Cancel</button>
                <button type="button" wire:click="toggleStatus" class="btn danger">Confirm</button>
            </div>
        </div>
    </x-modal>

    <x-modal name="change-password" max-width="sm">
        <div class="dialog">
            <div class="dh">
                <h2>Change Password</h2>
                @if ($passwordUserId === auth()->id())
                    <p>Sets a new password for your own account. Every other device you're currently signed into will be signed out, this one stays signed in.</p>
                @else
                    <p>Sets a new password for {{ $passwordUserName }} right away, they won't need to know the old one, and every other device they're currently signed into will be signed out.</p>
                @endif
            </div>
            <form wire:submit="savePassword" x-data="{ pw: '' }">
                <div class="db">
                    <div class="f">
                        <label for="new_password">New Password<span class="req">*</span></label>
                        <input type="password" wire:model="newPassword" x-on:input="pw = $event.target.value" id="new_password" autocomplete="new-password">

                        <div class="pwchecklist">
                            <div class="pwcheck" :class="pw.length >= {{ $security->password_min_length }} ? 'met' : ''">
                                <span class="dot"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                                <span>At least {{ $security->password_min_length }} characters</span>
                            </div>
                            @if ($security->password_require_uppercase)
                                <div class="pwcheck" :class="/[A-Z]/.test(pw) ? 'met' : ''">
                                    <span class="dot"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                                    <span>An uppercase letter</span>
                                </div>
                            @endif
                            @if ($security->password_require_lowercase)
                                <div class="pwcheck" :class="/[a-z]/.test(pw) ? 'met' : ''">
                                    <span class="dot"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                                    <span>A lowercase letter</span>
                                </div>
                            @endif
                            @if ($security->password_require_number)
                                <div class="pwcheck" :class="/[0-9]/.test(pw) ? 'met' : ''">
                                    <span class="dot"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                                    <span>A number</span>
                                </div>
                            @endif
                            @if ($security->password_require_symbol)
                                <div class="pwcheck" :class="/[^A-Za-z0-9]/.test(pw) ? 'met' : ''">
                                    <span class="dot"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                                    <span>A symbol</span>
                                </div>
                            @endif
                        </div>
                        @if ($security->password_history_count > 0)
                            <div class="hint">Can't reuse your last {{ $security->password_history_count }} passwords (checked when you save).</div>
                        @endif
                        <x-input-error :messages="$errors->get('newPassword')" class="mt-2" />
                    </div>

                    <div class="f" style="margin-bottom:0;">
                        <label for="confirm_password">Confirm Password<span class="req">*</span></label>
                        <input type="password" wire:model="confirmPassword" id="confirm_password" autocomplete="new-password">
                        <x-input-error :messages="$errors->get('confirmPassword')" class="mt-2" />
                    </div>
                </div>
                <div class="df">
                    <button type="button" class="btn" x-on:click="$dispatch('close')">Cancel</button>
                    <button type="submit" class="btn p">Save</button>
                </div>
            </form>
        </div>
    </x-modal>
</div>
