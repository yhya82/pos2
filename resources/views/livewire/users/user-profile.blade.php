<div>
    <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl ring-1 ring-gray-900/5 dark:ring-white/10 p-4 mb-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $user->name }}</h2>
                    <span @class([
                        'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $user->status === 'active',
                        'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $user->status === 'inactive',
                    ])>{{ ucfirst($user->status) }}</span>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ '@'.$user->username }} · {{ $user->role->name }}
                    @if ($user->email) · {{ $user->email }} @endif
                    @if ($user->phone) · {{ $user->phone }} @endif
                </p>
            </div>

            <div class="text-right">
                <div class="text-xs text-gray-500 dark:text-gray-400">Last Login</div>
                <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $user->last_login_at?->format('Y-m-d H:i') ?? 'Never' }}</div>
            </div>
        </div>
    </div>

    @if ($canEdit)
        <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl ring-1 ring-gray-900/5 dark:ring-white/10 p-4 mb-4 max-w-2xl">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-4">Account Details</h3>
            <form wire:submit="save" class="space-y-4">
                <div>
                    <x-input-label for="profile_name" value="Name" />
                    <x-text-input wire:model="name" id="profile_name" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="profile_username" value="Username" />
                        <x-text-input wire:model="username" id="profile_username" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('username')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="profile_email" value="Email" />
                        <x-text-input wire:model="email" id="profile_email" type="email" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="profile_phone" value="Phone" />
                        <x-text-input wire:model="phone" id="profile_phone" placeholder="+2201234567" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="profile_password" value="New Password (optional)" />
                        <x-text-input wire:model="password" id="profile_password" type="password" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="profile_role" value="Role" />
                        <select wire:model="roleId" id="profile_role" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('roleId')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="profile_status" value="Status" />
                        <select wire:model="status" id="profile_status" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <x-input-error :messages="$errors->get('status')" class="mt-2" />
                    </div>
                </div>

                <div class="flex justify-end">
                    <x-primary-button type="submit">Save</x-primary-button>
                </div>
            </form>
        </div>
    @endif
</div>
