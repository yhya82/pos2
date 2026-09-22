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

    /** An old number on file that isn't in the +220 + 9 digits form, shown as a hint while editing. */
    public string $legacyPhone = '';

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
                ->when($this->search, fn ($query) => $query->where(fn ($w) => $w
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('phone', 'like', "%{$this->search}%")))
                ->orderBy('name')
                ->paginate(10),
            // Suppliers whose number isn't +220 and 9 digits (old 7-digit ones, or none).
            'phonesToFix' => Supplier::where(fn ($q) => $q->whereNull('phone')->orWhereRaw('phone NOT REGEXP ?', ['^\+220[0-9]{9}$']))->count(),
        ]);
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'digits:9'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    protected function messages(): array
    {
        return [
            'phone.required' => 'A phone number is required (9 digits — the +220 prefix is added automatically).',
            'phone.digits' => 'Phone number must be exactly 9 digits (the +220 prefix is added automatically).',
        ];
    }

    public function create(): void
    {
        $this->authorizeAction('suppliers', 'create');

        $this->reset(['editingSupplierId', 'name', 'phone', 'legacyPhone', 'email', 'address', 'notes']);
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
        // A valid stored number shows without its +220; an old one can't be
        // trusted, so the box starts empty and the old value is shown as a hint.
        $this->phone = $supplier->hasValidPhone() ? substr($supplier->phone, 4) : '';
        $this->legacyPhone = $supplier->hasValidPhone() ? '' : (string) $supplier->phone;
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

        // Uniqueness is checked against the full +220-prefixed value actually
        // stored, as for customers and users.
        $validated['phone'] = '+220'.$validated['phone'];

        $phoneTaken = Supplier::where('phone', $validated['phone'])
            ->when($this->editingSupplierId, fn ($q) => $q->where('id', '!=', $this->editingSupplierId))
            ->exists();

        if ($phoneTaken) {
            $this->addError('phone', 'This phone number is already used by another supplier.');

            return;
        }

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
