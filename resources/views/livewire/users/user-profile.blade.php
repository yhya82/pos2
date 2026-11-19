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
            <div class="val">{{ $user->last_login_at?->format('Y-m-d H:i') ?? 'Never' }}</div>
        </div>
    </div>

    @if ($canEdit)
        <div class="card" style="max-width:640px;">
            <div class="cardhead">
                <span class="ic"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.4"/><path d="M5.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/></svg></span>
                <h3>Account Details</h3>
            </div>
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

                <div class="f grid2">
                    <div>
                        <label for="profile_phone">Phone<span class="req">*</span></label>
                        <div class="prefix">
                            <span>+220</span>
                            <input type="text" wire:model="phone" id="profile_phone" placeholder="831234567" maxlength="9" inputmode="numeric">
                        </div>
                        <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                    </div>
                    <div>
                        <label for="profile_password">New Password (optional)</label>
                        <input type="password" wire:model="password" id="profile_password" autocomplete="new-password">
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>
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

                <div style="display:flex; justify-content:flex-end; margin-top:20px;">
                    <button type="submit" class="btn p">Save</button>
                </div>
            </form>
        </div>
    @endif
</div>
