<div>
    <div class="flex items-center justify-between gap-4 mb-4">
        <div class="w-full max-w-xs">
            <x-text-input wire:model.live.debounce.300ms="search" type="search" placeholder="Search roles..." class="w-full" />
        </div>

        @if (auth()->user()->hasPermission('roles', 'create'))
            <x-primary-button wire:click="create">
                Create Role
            </x-primary-button>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-900/40">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Description</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Users</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($roles as $role)
                    <tr wire:key="role-{{ $role->id }}">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $role->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $role->description }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $role->users_count }}</td>
                        <td class="px-4 py-3 text-sm">
                            <span @class([
                                'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $role->status === 'active',
                                'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $role->status === 'inactive',
                            ])>
                                {{ ucfirst($role->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-right space-x-3">
                            @if (auth()->user()->hasPermission('roles', 'update'))
                                <button wire:click="edit({{ $role->id }})" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium">
                                    Edit
                                </button>
                            @endif
                            @if (auth()->user()->hasPermission('roles', 'delete'))
                                <button wire:click="confirmDeactivate({{ $role->id }})" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 font-medium">
                                    {{ $role->status === 'active' ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            No roles found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
            {{ $roles->links() }}
        </div>
    </div>

    <x-slide-over name="role-form" :title="$editingRoleId ? 'Edit Role' : 'Create Role'">
        <form wire:submit="save" id="role-form" class="space-y-6">
            <div>
                <x-input-label for="role_name" value="Name" />
                <x-text-input wire:model="name" id="role_name" class="block mt-1 w-full" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="role_description" value="Description" />
                <textarea wire:model="description" id="role_description" rows="2" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="role_status" value="Status" />
                <select wire:model="status" id="role_status" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div>
                <x-input-label value="Permissions" />
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 mb-3">Grouped by module — check every action this role should be able to perform.</p>

                <div class="space-y-4">
                    @foreach ($permissionsByModule as $module => $permissions)
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">
                                {{ str($module)->headline() }}
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1">
                                @foreach ($permissions as $permission)
                                    <label class="inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                                        <input type="checkbox" wire:model="selectedPermissions" value="{{ $permission->id }}" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 focus:ring-indigo-500">
                                        {{ ucfirst($permission->action) }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </form>

        <x-slot name="footer">
            <x-secondary-button x-on:click="show = false">Cancel</x-secondary-button>
            <x-primary-button type="submit" form="role-form">Save</x-primary-button>
        </x-slot>
    </x-slide-over>

    <x-confirm-modal
        name="confirm-deactivate-role"
        title="Confirm"
        message="Are you sure? Users with this role are unaffected immediately, but the role stops being assignable to new users while inactive."
        confirm-label="Confirm"
    >
        <x-danger-button wire:click="toggleStatus">Confirm</x-danger-button>
    </x-confirm-modal>
</div>
