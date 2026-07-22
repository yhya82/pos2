<div>
    <div class="flex items-center justify-between gap-4 mb-4">
        <div class="flex items-center gap-3">
            <div class="w-full max-w-xs">
                <x-text-input wire:model.live.debounce.300ms="search" type="search" placeholder="Search PO # or supplier..." class="w-full" />
            </div>
            <select wire:model.live="statusFilter" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                <option value="">All statuses</option>
                <option value="draft">Draft</option>
                <option value="ordered">Ordered</option>
                <option value="partially_received">Partially Received</option>
                <option value="received">Received</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>

        @if (auth()->user()->hasPermission('purchase_orders', 'create'))
            <x-primary-button wire:click="create">
                Create Purchase Order
            </x-primary-button>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-900/40">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">PO #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Supplier</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Order Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Lines</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Created By</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($purchaseOrders as $po)
                    <tr wire:key="po-{{ $po->id }}">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100 whitespace-nowrap font-mono">{{ $po->po_number }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $po->supplier->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $po->order_date->format('Y-m-d') }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $po->line_items_count }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $po->creator->name }}</td>
                        <td class="px-4 py-3 text-sm whitespace-nowrap">
                            <span @class([
                                'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $po->status === 'draft',
                                'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' => $po->status === 'ordered',
                                'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => $po->status === 'partially_received',
                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $po->status === 'received',
                                'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' => $po->status === 'cancelled',
                            ])>
                                {{ str($po->status)->headline() }}
                            </span>
                            @if ($po->approved_at)
                                <span class="ml-1 text-xs text-gray-400" title="Approved by {{ $po->approver?->name }} on {{ $po->approved_at->format('Y-m-d') }}">✓ approved</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-right whitespace-nowrap space-x-3">
                            @if (auth()->user()->hasPermission('purchase_orders', 'update'))
                                @if ($po->status === 'draft')
                                    <button wire:click="edit({{ $po->id }})" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium">Edit</button>
                                    @unless ($po->approved_at)
                                        <button wire:click="approve({{ $po->id }})" class="text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white font-medium">Approve</button>
                                    @endunless
                                    <button wire:click="markAsOrdered({{ $po->id }})" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-medium">Mark Ordered</button>
                                @endif
                                @if (in_array($po->status, ['ordered', 'partially_received']))
                                    <button wire:click="openReceive({{ $po->id }})" class="text-emerald-600 hover:text-emerald-800 dark:text-emerald-400 dark:hover:text-emerald-300 font-medium">Receive</button>
                                @endif
                                @if (in_array($po->status, ['draft', 'ordered']))
                                    <button wire:click="confirmCancel({{ $po->id }})" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 font-medium">Cancel</button>
                                @endif
                            @endif
                            @if ($po->status === 'draft' && auth()->user()->hasPermission('purchase_orders', 'delete'))
                                <button wire:click="confirmDelete({{ $po->id }})" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 font-medium">Delete</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            No purchase orders found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
            {{ $purchaseOrders->links() }}
        </div>
    </div>

    <x-slide-over name="po-form" :title="$editingPoId ? 'Edit Purchase Order' : 'Create Purchase Order'" max-width="2xl">
        <form wire:submit="save" id="po-form" class="space-y-6">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="po_supplier" value="Supplier" />
                    <select wire:model="supplierId" id="po_supplier" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select...</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('supplierId')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="po_order_date" value="Order Date" />
                    <x-text-input wire:model="orderDate" id="po_order_date" type="date" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('orderDate')" class="mt-2" />
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-2">
                    <x-input-label value="Line Items" />
                    <button type="button" wire:click="addLine" class="text-sm text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 font-medium">+ Add Line</button>
                </div>
                <x-input-error :messages="$errors->get('lines')" class="mb-2" />

                <div class="space-y-3">
                    @foreach ($lines as $index => $line)
                        <div class="grid grid-cols-12 gap-2 items-start border border-gray-200 dark:border-gray-700 rounded-md p-3" wire:key="line-{{ $index }}">
                            <div class="col-span-4">
                                <select wire:model.live="lines.{{ $index }}.product_id" class="block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Product...</option>
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('lines.'.$index.'.product_id')" class="mt-1" />
                            </div>

                            <div class="col-span-2">
                                <input type="text" wire:model="lines.{{ $index }}.qty_ordered" placeholder="Qty" class="block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <x-input-error :messages="$errors->get('lines.'.$index.'.qty_ordered')" class="mt-1" />
                            </div>

                            <div class="col-span-3">
                                <select wire:model="lines.{{ $index }}.purchase_unit_id" class="block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Unit...</option>
                                    @foreach (\App\Models\Unit::where('is_active', true)->orderBy('name')->get() as $unit)
                                        <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('lines.'.$index.'.purchase_unit_id')" class="mt-1" />
                            </div>

                            <div class="col-span-2">
                                <input type="text" wire:model="lines.{{ $index }}.cost_price" placeholder="Cost" class="block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <x-input-error :messages="$errors->get('lines.'.$index.'.cost_price')" class="mt-1" />
                            </div>

                            <div class="col-span-1 flex justify-end">
                                @if (count($lines) > 1)
                                    <button type="button" wire:click="removeLine({{ $index }})" class="text-red-500 hover:text-red-700 text-sm">✕</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </form>

        <x-slot name="footer">
            <x-secondary-button x-on:click="show = false">Cancel</x-secondary-button>
            <x-primary-button type="submit" form="po-form">Save</x-primary-button>
        </x-slot>
    </x-slide-over>

    <x-slide-over name="receive-form" title="Receive Stock">
        <form wire:submit="receive" id="receive-form" class="space-y-4">
            @forelse ($receivingLines as $index => $line)
                <div class="border border-gray-200 dark:border-gray-700 rounded-md p-3" wire:key="receiving-line-{{ $index }}">
                    <div class="flex items-center justify-between text-sm font-medium text-gray-800 dark:text-gray-100">
                        <span>{{ $line['product_name'] }}</span>
                        <span class="text-gray-500 dark:text-gray-400 font-normal">{{ $line['remaining'] }} remaining</span>
                    </div>

                    <div class="grid grid-cols-2 gap-2 mt-2">
                        <div>
                            <x-input-label value="Qty received now" class="text-xs" />
                            <input type="text" wire:model="receivingLines.{{ $index }}.qty" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <x-input-label value="Batch code" class="text-xs" />
                            <input type="text" wire:model="receivingLines.{{ $index }}.batch_code" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <x-input-label value="Expiry date" class="text-xs" />
                            <input type="date" wire:model="receivingLines.{{ $index }}.expiry_date" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <x-input-label value="Received date" class="text-xs" />
                            <input type="date" wire:model="receivingLines.{{ $index }}.received_date" class="block w-full text-sm mt-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">Nothing left to receive on this order.</p>
            @endforelse
        </form>

        <x-slot name="footer">
            <x-secondary-button x-on:click="show = false">Cancel</x-secondary-button>
            <x-primary-button type="submit" form="receive-form">Receive</x-primary-button>
        </x-slot>
    </x-slide-over>

    <x-confirm-modal
        name="confirm-cancel-po"
        title="Confirm"
        message="Are you sure? Cancelling a purchase order is permanent — it stays in the list for history but can no longer be ordered or received."
        confirm-label="Confirm"
    >
        <x-danger-button wire:click="cancel">Confirm</x-danger-button>
    </x-confirm-modal>

    <x-confirm-modal
        name="confirm-delete-po"
        title="Confirm"
        message="Are you sure? This draft purchase order will be permanently deleted — this only works for drafts that were never sent to the supplier."
        confirm-label="Delete"
    >
        <x-danger-button wire:click="delete">Delete</x-danger-button>
    </x-confirm-modal>
</div>
