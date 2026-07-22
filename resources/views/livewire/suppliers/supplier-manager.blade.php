<div>
    <div class="flex items-center justify-between gap-4 mb-4">
        <div class="w-full max-w-xs">
            <x-text-input wire:model.live.debounce.300ms="search" type="search" placeholder="Search suppliers..." class="w-full" />
        </div>

        @if (auth()->user()->hasPermission('suppliers', 'create'))
            <x-primary-button wire:click="create">
                Create Supplier
            </x-primary-button>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-900/40">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Phone</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Email</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Products</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($suppliers as $supplier)
                    <tr wire:key="supplier-{{ $supplier->id }}">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $supplier->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $supplier->phone }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $supplier->email }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $supplier->products_count }}</td>
                        <td class="px-4 py-3 text-sm">
                            <span @class([
                                'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $supplier->status === 'active',
                                'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $supplier->status === 'inactive',
                            ])>
                                {{ ucfirst($supplier->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-right space-x-3">
                            @if (auth()->user()->hasPermission('suppliers', 'update'))
                                <button wire:click="edit({{ $supplier->id }})" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium">
                                    Edit
                                </button>
                            @endif
                            @if (auth()->user()->hasPermission('suppliers', 'delete'))
                                <button wire:click="confirmDeactivate({{ $supplier->id }})" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 font-medium">
                                    {{ $supplier->status === 'active' ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            No suppliers found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
            {{ $suppliers->links() }}
        </div>
    </div>

    <x-slide-over name="supplier-form" :title="$editingSupplierId ? 'Edit Supplier' : 'Create Supplier'">
        <form wire:submit="save" id="supplier-form" class="space-y-6">
            <div>
                <x-input-label for="supplier_name" value="Name" />
                <x-text-input wire:model="name" id="supplier_name" class="block mt-1 w-full" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="supplier_phone" value="Phone" />
                <x-text-input wire:model="phone" id="supplier_phone" class="block mt-1 w-full" />
                <x-input-error :messages="$errors->get('phone')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="supplier_email" value="Email" />
                <x-text-input wire:model="email" id="supplier_email" type="email" class="block mt-1 w-full" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="supplier_address" value="Address" />
                <textarea wire:model="address" id="supplier_address" rows="2" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                <x-input-error :messages="$errors->get('address')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="supplier_notes" value="Notes" />
                <textarea wire:model="notes" id="supplier_notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                <x-input-error :messages="$errors->get('notes')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="supplier_status" value="Status" />
                <select wire:model="status" id="supplier_status" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </form>

        <x-slot name="footer">
            <x-secondary-button x-on:click="show = false">Cancel</x-secondary-button>
            <x-primary-button type="submit" form="supplier-form">Save</x-primary-button>
        </x-slot>
    </x-slide-over>

    <x-confirm-modal
        name="confirm-deactivate-supplier"
        title="Confirm"
        message="Are you sure? Products from this supplier keep their assignment but it stops being selectable for new ones while inactive."
        confirm-label="Confirm"
    >
        <x-danger-button wire:click="toggleStatus">Confirm</x-danger-button>
    </x-confirm-modal>
</div>
