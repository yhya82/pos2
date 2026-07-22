<div>
    <div class="flex items-center justify-between gap-4 mb-4">
        <div class="w-full max-w-xs">
            <x-text-input wire:model.live.debounce.300ms="search" type="search" placeholder="Search name or phone..." class="w-full" />
        </div>

        @if (auth()->user()->hasPermission('customers', 'create'))
            <x-primary-button wire:click="create">
                Create Customer
            </x-primary-button>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-900/40">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Phone</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Email</th>
                    @if ($creditModuleEnabled)
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Outstanding</th>
                    @endif
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($customers as $customer)
                    <tr wire:key="customer-{{ $customer->id }}">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100 whitespace-nowrap">
                            <a href="{{ route('customers.show', $customer) }}" wire:navigate class="hover:text-indigo-600 dark:hover:text-indigo-400">{{ $customer->name }}</a>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $customer->phone }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $customer->email }}</td>
                        @if ($creditModuleEnabled)
                            <td class="px-4 py-3 text-sm text-right tabular-nums text-gray-600 dark:text-gray-400 whitespace-nowrap">
                                {{ $customer->credit_enabled ? number_format($customer->outstanding_balance, 2) : '—' }}
                            </td>
                        @endif
                        <td class="px-4 py-3 text-sm whitespace-nowrap">
                            <span @class([
                                'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $customer->status === 'active',
                                'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $customer->status === 'inactive',
                            ])>
                                {{ ucfirst($customer->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-right whitespace-nowrap space-x-3">
                            @if (auth()->user()->hasPermission('customers', 'update'))
                                <button wire:click="edit({{ $customer->id }})" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium">Edit</button>
                            @endif
                            @if (auth()->user()->hasPermission('customers', 'delete'))
                                <button wire:click="confirmDeactivate({{ $customer->id }})" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 font-medium">
                                    {{ $customer->status === 'active' ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No customers found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
            {{ $customers->links() }}
        </div>
    </div>

    <x-slide-over name="customer-form" :title="$editingCustomerId ? 'Edit Customer' : 'Create Customer'">
        <form wire:submit="save" id="customer-form" class="space-y-6">
            <div>
                <x-input-label for="customer_name" value="Name" />
                <x-text-input wire:model="name" id="customer_name" class="block mt-1 w-full" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="customer_phone" value="Phone" />
                <x-text-input wire:model="phone" id="customer_phone" class="block mt-1 w-full" />
                <x-input-error :messages="$errors->get('phone')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="customer_email" value="Email" />
                <x-text-input wire:model="email" id="customer_email" type="email" class="block mt-1 w-full" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="customer_address" value="Address" />
                <textarea wire:model="address" id="customer_address" rows="2" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                <x-input-error :messages="$errors->get('address')" class="mt-2" />
            </div>

            @if ($creditModuleEnabled)
                <div>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model.live="creditEnabled" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 focus:ring-indigo-500">
                        Credit Enabled
                    </label>
                </div>

                <div x-show="$wire.creditEnabled">
                    <x-input-label for="customer_credit_limit" value="Credit Limit" />
                    <x-text-input wire:model="creditLimit" id="customer_credit_limit" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('creditLimit')" class="mt-2" />
                </div>
            @endif

            <div>
                <x-input-label for="customer_status" value="Status" />
                <select wire:model="status" id="customer_status" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </form>

        <x-slot name="footer">
            <x-secondary-button x-on:click="show = false">Cancel</x-secondary-button>
            <x-primary-button type="submit" form="customer-form">Save</x-primary-button>
        </x-slot>
    </x-slide-over>

    <x-confirm-modal
        name="confirm-deactivate-customer"
        title="Confirm"
        message="Are you sure? Their purchase and credit history is kept, and the account can be reactivated later."
        confirm-label="Confirm"
    >
        <x-danger-button wire:click="toggleStatus">Confirm</x-danger-button>
    </x-confirm-modal>
</div>
