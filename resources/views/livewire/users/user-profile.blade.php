@php
    $initials = collect(preg_split('/\s+/', trim($user->name)))->filter()->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->take(2)->implode('');
@endphp

<div class="users-roles-skin">
    <div class="profile-head">
        <span class="avatar">{{ $initials ?: '?' }}</span>
        <div>
            <div class="nm">
                <h2>{{ $user->name }}</h2>
                <span @class(['status', 'ok' => $user->status === 'active', 'plain' => $user->status !== 'active'])>{{ ucfirst($user->status) }}</span>
            </div>
            <div class="meta">
                <span class="rolechip" style="margin-right:6px;"><svg viewBox="0 0 24 24"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/></svg>{{ $user->role->name }}</span>
                &middot; @{{ $user->username }}
                @if ($user->email) &middot; {{ $user->email }} @endif
                @if ($user->phone) &middot; {{ $user->formattedPhone() }} @endif
            </div>
        </div>
        <div class="figs">
            <div class="lbl">Last Login</div>
            <div class="val">{{ $user->last_login_at?->format('Y-m-d h:i A') ?? 'Never' }}</div>
        </div>
    </div>

    @if ($canEdit)
        <div style="display:grid; grid-template-columns: 3fr 2fr; gap:16px; align-items:start;">
            <div class="card">
                <div class="cardhead">
                    <span class="ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.4"/><path d="M5.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/></svg></span>
                    <h3>Account Details</h3>
                    @unless ($editingDetails)
                        <button type="button" wire:click="editDetails" class="editbtn" aria-label="Edit account details" title="Edit">
                            <svg viewBox="0 0 24 24"><path d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/><path d="M15.75 6.75l2.5 2.5"/></svg>
                        </button>
                    @endunless
                </div>

                @if ($editingDetails)
                    <form wire:submit="save" style="padding:20px;">
                        <div class="f">
                            <label for="profile_name">Name<span class="req">*</span></label>
                            <input type="text" wire:model="name" id="profile_name">
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>

                        <div class="f grid2">
                            <div>
                                <label for="profile_username">Username<span class="req">*</span></label>
                                <input type="text" wire:model="username" id="profile_username">
                                <x-input-error :messages="$errors->get('username')" class="mt-2" />
                            </div>
                            <div>
                                <label for="profile_email">Email</label>
                                <input type="email" wire:model="email" id="profile_email">
                                <x-input-error :messages="$errors->get('email')" class="mt-2" />
                            </div>
                        </div>

                        <div class="f">
                            <label for="profile_phone">Phone<span class="req">*</span></label>
                            <div class="prefix">
                                <span>+220</span>
                                <input type="text" wire:model="phone" id="profile_phone" placeholder="831234567" maxlength="9" inputmode="numeric">
                            </div>
                            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                        </div>

                        <div class="f grid2" style="margin-bottom:0;">
                            <div>
                                <label for="profile_role">Role<span class="req">*</span></label>
                                <select wire:model="roleId" id="profile_role">
                                    @foreach ($roles as $role)
                                        <option value="{{ $role->id }}">{{ $role->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('roleId')" class="mt-2" />
                            </div>
                            <div>
                                <label for="profile_status">Status<span class="req">*</span></label>
                                <select wire:model="status" id="profile_status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                                <x-input-error :messages="$errors->get('status')" class="mt-2" />
                            </div>
                        </div>

                        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                            <button type="button" wire:click="cancelEditDetails" class="btn">Cancel</button>
                            <button type="submit" class="btn p">Save</button>
                        </div>
                    </form>
                @else
                    <div style="padding:20px;">
                        <div class="viewrow"><span class="k">Name</span><span class="v">{{ $user->name }}</span></div>
                        <div class="viewrow"><span class="k">Username</span><span class="v">&commat;{{ $user->username }}</span></div>
                        <div class="viewrow"><span class="k">Email</span><span class="v">{{ $user->email ?: '—' }}</span></div>
                        <div class="viewrow"><span class="k">Phone</span><span class="v">{{ $user->phone ? $user->formattedPhone() : '—' }}</span></div>
                        <div class="viewrow"><span class="k">Role</span><span class="v">{{ $user->role->name }}</span></div>
                        <div class="viewrow"><span class="k">Status</span><span class="v">{{ ucfirst($user->status) }}</span></div>
                    </div>
                @endif
            </div>

            <div class="card">
                <div class="cardhead">
                    <span class="ic"><svg viewBox="0 0 24 24"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/><path d="M12 8v5M12 15.3h.01"/></svg></span>
                    <h3>Role &amp; Permissions</h3>
                </div>
                <div style="padding:20px;">
                    <div class="viewrow"><span class="k">Role</span><span class="v">{{ $user->role->name }}</span></div>
                    <div class="viewrow"><span class="k">Permissions granted</span><span class="v">{{ $permissionCount }}</span></div>

                    @if (auth()->user()->hasPermission('roles', 'view'))
                        <a href="{{ route('roles.index') }}" wire:navigate class="btn outline" style="margin-top:12px;">Manage roles &amp; permissions</a>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
