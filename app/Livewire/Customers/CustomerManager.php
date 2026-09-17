<?php

namespace App\Livewire\Customers;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ModuleSetting;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class CustomerManager extends Component
{
    use WithPagination, AuthorizesModuleActions;

    public string $search = '';

    public ?int $editingCustomerId = null;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public bool $creditEnabled = false;

    public string $creditLimit = '0.00';

    public string $status = 'active';

    public ?int $customerIdPendingDeactivation = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    #[On('echo-private:settings,.ModuleSettingChanged')]
    public function onModuleSettingChanged(): void
    {
        // No-op — render() below re-checks ModuleSetting::enabled() fresh.
    }

    public function render()
    {
        return view('livewire.customers.customer-manager', [
            'customers' => Customer::when($this->search, fn ($query) => $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('phone', 'like', "%{$this->search}%");
                }))
                ->orderBy('name')
                ->paginate(10),
            'creditModuleEnabled' => ModuleSetting::enabled('customer_credit'),
        ]);
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'digits:9'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'creditEnabled' => ['boolean'],
            'creditLimit' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    protected function messages(): array
    {
        return [
            'phone.digits' => 'Phone number must be exactly 9 digits (the +220 prefix is added automatically).',
        ];
    }

    public function create(): void
    {
        $this->authorizeAction('customers', 'create');

        $this->reset(['editingCustomerId', 'name', 'phone', 'email', 'address', 'creditEnabled']);
        $this->creditLimit = '0.00';
        $this->status = 'active';
        $this->resetValidation();

        $this->dispatch('open-modal', 'customer-form');
    }

    public function edit(int $customerId): void
    {
        $this->authorizeAction('customers', 'update');

        $customer = Customer::findOrFail($customerId);

        $this->editingCustomerId = $customer->id;
        $this->name = $customer->name;
        $this->phone = $customer->phone ? substr($customer->phone, 4) : '';
        $this->email = (string) $customer->email;
        $this->address = (string) $customer->address;
        $this->creditEnabled = $customer->credit_enabled;
        $this->creditLimit = (string) $customer->credit_limit;
        $this->status = $customer->status;
        $this->resetValidation();

        $this->dispatch('open-modal', 'customer-form');
    }

    public function save(): void
    {
        $isCreating = ! $this->editingCustomerId;
        $this->authorizeAction('customers', $isCreating ? 'create' : 'update');

        $validated = $this->validate();

        // Uniqueness has to be checked against the full +220-prefixed value
        // actually stored in the column — see UserManager::save() for why.
        $fullPhone = '+220'.$validated['phone'];

        $phoneTaken = Customer::where('phone', $fullPhone)
            ->when($this->editingCustomerId, fn ($q) => $q->where('id', '!=', $this->editingCustomerId))
            ->exists();

        if ($phoneTaken) {
            $this->addError('phone', 'This phone number is already in use.');

            return;
        }

        $attributes = [
            'name' => $validated['name'],
            'phone' => $fullPhone,
            'email' => $validated['email'] ?: null,
            'address' => $validated['address'] ?: null,
            'credit_enabled' => $validated['creditEnabled'],
            'credit_limit' => $validated['creditLimit'],
            'status' => $validated['status'],
        ];

        $previous = null;

        if ($isCreating) {
            $customer = Customer::create($attributes);
        } else {
            $customer = Customer::findOrFail($this->editingCustomerId);
            $previous = $customer->only(array_keys($attributes));
            $customer->update($attributes);
        }

        AuditLog::record(
            $isCreating ? 'create' : 'update',
            'customers',
            'Customer',
            $customer->id,
            $previous,
            $customer->only(array_keys($attributes)),
        );

        $this->dispatch('close-modal', 'customer-form');
        $this->dispatch('flash-message', message: $isCreating ? 'Customer created.' : 'Customer updated.', variant: 'success');
    }

    public function confirmDeactivate(int $customerId): void
    {
        $this->authorizeAction('customers', 'delete');

        $this->customerIdPendingDeactivation = $customerId;
        $this->dispatch('open-modal', 'confirm-deactivate-customer');
    }

    public function toggleStatus(): void
    {
        $this->authorizeAction('customers', 'delete');

        $customer = Customer::findOrFail($this->customerIdPendingDeactivation);

        if ($customer->status === 'active' && (float) $customer->outstanding_balance > 0) {
            $this->dispatch('flash-message', message: "Can't deactivate \"{$customer->name}\" they still have an outstanding balance of {$customer->outstanding_balance}.", variant: 'error');
            $this->dispatch('close-modal', 'confirm-deactivate-customer');

            return;
        }

        $previous = $customer->only(['status']);
        $customer->status = $customer->status === 'active' ? 'inactive' : 'active';
        $customer->save();

        AuditLog::record('update', 'customers', 'Customer', $customer->id, $previous, $customer->only(['status']));

        $this->dispatch('close-modal', 'confirm-deactivate-customer');
        $this->dispatch('flash-message', message: $customer->status === 'active' ? 'Customer reactivated.' : 'Customer deactivated.', variant: 'success');
        $this->customerIdPendingDeactivation = null;
    }
}
