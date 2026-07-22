<div>
    <div class="flex items-center justify-between gap-4 mb-4">
        <div class="w-full max-w-xs">
            <x-text-input wire:model.live.debounce.300ms="search" type="search" placeholder="Search units..." class="w-full" />
        </div>

        @if (auth()->user()->hasPermission('products', 'create'))
            <x-primary-button wire:click="create">
                Create Unit
            </x-primary-button>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-900/40">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Products Using It</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($units as $unit)
                    <tr wire:key="unit-{{ $unit->id }}">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $unit->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $unit->products_as_purchase_unit_count + $unit->products_as_selling_unit_count }}</td>
                        <td class="px-4 py-3 text-sm">
                            <span @class([
                                'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $unit->is_active,
                                'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => ! $unit->is_active,
                            ])>
                                {{ $unit->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-right space-x-3">
                            @if (auth()->user()->hasPermission('products', 'update'))
                                <button wire:click="edit({{ $unit->id }})" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium">
                                    Edit
                                </button>
                            @endif
                            @if (auth()->user()->hasPermission('products', 'delete'))
                                <button wire:click="confirmDeactivate({{ $unit->id }})" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 font-medium">
                                    {{ $unit->is_active ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            No units found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
            {{ $units->links() }}
        </div>
    </div>

    <x-slide-over name="unit-form" :title="$editingUnitId ? 'Edit Unit' : 'Create Unit'">
        <form wire:submit="save" id="unit-form" class="space-y-6">
            <div>
                <x-input-label for="unit_name" value="Name" />
                <x-text-input wire:model="name" id="unit_name" class="block mt-1 w-full" placeholder="e.g. Carton, Bottle, Kg" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
        </form>

        <x-slot name="footer">
            <x-secondary-button x-on:click="show = false">Cancel</x-secondary-button>
            <x-primary-button type="submit" form="unit-form">Save</x-primary-button>
        </x-slot>
    </x-slide-over>

    <x-confirm-modal
        name="confirm-deactivate-unit"
        title="Confirm"
        message="Are you sure? Products already using this unit are unaffected, but it stops being selectable for new products while inactive."
        confirm-label="Confirm"
    >
        <x-danger-button wire:click="toggleStatus">Confirm</x-danger-button>
    </x-confirm-modal>
</div>
