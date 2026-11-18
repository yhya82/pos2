@php
    $money = fn ($amount) => new \Illuminate\Support\HtmlString(
        '<span class="ccy">'.e($currencyCode).'</span> '.number_format($amount, 2)
    );
@endphp

<div class="customers-skin">
    <div class="top">
        <div>
            <h1>Customers</h1>
            <p>Everyone the store has on file, credit customers included</p>
        </div>
        @if (auth()->user()->hasPermission('customers', 'create'))
            <button type="button" wire:click="create" class="btn p">
                <svg viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M12 5v14M5 12h14"/></svg>
                Create Customer
            </button>
        @endif
    </div>

    <div class="filters">
        <div class="search">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search name or phone…">
        </div>
    </div>

    <div class="card">
        @php $showActions = auth()->user()->hasPermission('customers', 'update') || auth()->user()->hasPermission('customers', 'delete'); @endphp
        <table class="tbl">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Email</th>
                    @if ($creditModuleEnabled)
                        <th class="num">Outstanding</th>
                    @endif
                    <th>Status</th>
                    @if ($showActions)
                        <th class="actions">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($customers as $customer)
                    <tr wire:key="customer-{{ $customer->id }}">
                        <td><a href="{{ route('customers.show', $customer) }}" wire:navigate class="link">{{ $customer->name }}</a></td>
                        <td class="mut">{{ $customer->formattedPhone() }}</td>
                        <td class="mut">{{ $customer->email ?? '—' }}</td>
                        @if ($creditModuleEnabled)
                            <td @class(['num', 'mut' => ! $customer->credit_enabled])>
                                {{ $customer->credit_enabled ? $money($customer->outstanding_balance) : '—' }}
                            </td>
                        @endif
                        <td>
                            <span @class(['status', 'ok' => $customer->status === 'active', 'plain' => $customer->status !== 'active'])>{{ ucfirst($customer->status) }}</span>
                        </td>
                        @if ($showActions)
                            <td class="actions">
                                @if (auth()->user()->hasPermission('customers', 'update'))
                                    <button type="button" wire:click="edit({{ $customer->id }})" class="actionbtn">Edit</button>
                                @endif
                                @if (auth()->user()->hasPermission('customers', 'delete'))
                                    <button type="button" wire:click="confirmDeactivate({{ $customer->id }})" class="actionbtn danger">
                                        {{ $customer->status === 'active' ? 'Deactivate' : 'Reactivate' }}
                                    </button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $creditModuleEnabled ? ($showActions ? 6 : 5) : ($showActions ? 5 : 4) }}" class="empty">No customers found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="pager">
            <span>Showing {{ $customers->firstItem() ?? 0 }}–{{ $customers->lastItem() ?? 0 }} of {{ $customers->total() }}</span>
            @if ($customers->hasPages())
                <div class="pages">
                    @foreach ($customers->getUrlRange(max(1, $customers->currentPage() - 2), min($customers->lastPage(), $customers->currentPage() + 2)) as $page => $url)
                        <button type="button" wire:click="gotoPage({{ $page }})" aria-current="{{ $page === $customers->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <x-slide-over name="customer-form" :title="$editingCustomerId ? 'Edit Customer' : 'Create Customer'">
        <form wire:submit="save" id="customer-form">
            <div class="f">
                <label for="customer_name">Name</label>
                <input type="text" wire:model="name" id="customer_name">
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div class="f">
                <label for="customer_phone">Phone</label>
                <div class="prefix">
                    <span>+220</span>
                    <input type="text" wire:model="phone" id="customer_phone" placeholder="831234567" maxlength="9">
                </div>
                <x-input-error :messages="$errors->get('phone')" class="mt-2" />
            </div>

            <div class="f">
                <label for="customer_email">Email</label>
                <input type="email" wire:model="email" id="customer_email">
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div class="f">
                <label for="customer_address">Address</label>
                <textarea wire:model="address" id="customer_address" rows="2"></textarea>
                <x-input-error :messages="$errors->get('address')" class="mt-2" />
            </div>

            @if ($creditModuleEnabled)
                <div class="f">
                    <label class="check"><input type="checkbox" wire:model.live="creditEnabled"> Credit enabled</label>
                </div>

                <div class="f" x-show="$wire.creditEnabled">
                    <label for="customer_credit_limit">Credit limit</label>
                    <input type="text" wire:model="creditLimit" id="customer_credit_limit">
                    <x-input-error :messages="$errors->get('creditLimit')" class="mt-2" />
                </div>
            @endif

            <div class="f">
                <label for="customer_status">Status</label>
                <select wire:model="status" id="customer_status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </form>

        <x-slot name="footer">
            <button type="button" x-on:click="show = false" class="btn">Cancel</button>
            <button type="submit" form="customer-form" class="btn p">Save</button>
        </x-slot>
    </x-slide-over>

    <x-modal name="confirm-deactivate-customer" max-width="sm">
        <div class="dialog">
            <div class="dh">
                <h2>Confirm</h2>
                <p>Are you sure? Their purchase and credit history is kept, and the account can be reactivated later.</p>
            </div>
            <div class="df">
                <button type="button" class="btn" x-on:click="$dispatch('close')">Cancel</button>
                <button type="button" wire:click="toggleStatus" class="btn danger">Confirm</button>
            </div>
        </div>
    </x-modal>
</div>
