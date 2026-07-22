<?php

namespace App\Livewire\Suppliers;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\Supplier;
use Livewire\Component;
use Livewire\WithPagination;

class SupplierManager extends Component
{
    use WithPagination, AuthorizesModuleActions;

    public string $search = '';

    public ?int $editingSupplierId = null;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $notes = '';

    public string $status = 'active';

    public ?int $supplierIdPendingDeactivation = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.suppliers.supplier-manager', [
            'suppliers' => Supplier::withCount('products')
                ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
                ->orderBy('name')
                ->paginate(10),
        ]);
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    public function create(): void
    {
        $this->authorizeAction('suppliers', 'create');

        $this->reset(['editingSupplierId', 'name', 'phone', 'email', 'address', 'notes']);
        $this->status = 'active';
        $this->resetValidation();

        $this->dispatch('open-modal', 'supplier-form');
    }

    public function edit(int $supplierId): void
    {
        $this->authorizeAction('suppliers', 'update');

        $supplier = Supplier::findOrFail($supplierId);

        $this->editingSupplierId = $supplier->id;
        $this->name = $supplier->name;
        $this->phone = (string) $supplier->phone;
        $this->email = (string) $supplier->email;
        $this->address = (string) $supplier->address;
        $this->notes = (string) $supplier->notes;
        $this->status = $supplier->status;
        $this->resetValidation();

        $this->dispatch('open-modal', 'supplier-form');
    }

    public function save(): void
    {
        $isCreating = ! $this->editingSupplierId;
        $this->authorizeAction('suppliers', $isCreating ? 'create' : 'update');

        $validated = $this->validate();

        $previous = null;

        if ($isCreating) {
            $supplier = Supplier::create($validated);
        } else {
            $supplier = Supplier::findOrFail($this->editingSupplierId);
            $previous = $supplier->only(['name', 'phone', 'email', 'address', 'notes', 'status']);
            $supplier->update($validated);
        }

        AuditLog::record(
            $isCreating ? 'create' : 'update',
            'suppliers',
            'Supplier',
            $supplier->id,
            $previous,
            $supplier->only(['name', 'phone', 'email', 'address', 'notes', 'status']),
        );

        $this->dispatch('close-modal', 'supplier-form');
        $this->dispatch('flash-message', message: $isCreating ? 'Supplier created.' : 'Supplier updated.', variant: 'success');
    }

    public function confirmDeactivate(int $supplierId): void
    {
        $this->authorizeAction('suppliers', 'delete');

        $this->supplierIdPendingDeactivation = $supplierId;
        $this->dispatch('open-modal', 'confirm-deactivate-supplier');
    }

    public function toggleStatus(): void
    {
        $this->authorizeAction('suppliers', 'delete');

        $supplier = Supplier::findOrFail($this->supplierIdPendingDeactivation);
        $previous = $supplier->only(['status']);

        $supplier->status = $supplier->status === 'active' ? 'inactive' : 'active';
        $supplier->save();

        AuditLog::record('update', 'suppliers', 'Supplier', $supplier->id, $previous, $supplier->only(['status']));

        $this->dispatch('close-modal', 'confirm-deactivate-supplier');
        $this->dispatch('flash-message', message: $supplier->status === 'active' ? 'Supplier reactivated.' : 'Supplier deactivated.', variant: 'success');
        $this->supplierIdPendingDeactivation = null;
    }
}
