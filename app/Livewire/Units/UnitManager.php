<?php

namespace App\Livewire\Units;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\Unit;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Units have no dedicated entry in the seeded permissions catalog (Part E,
 * Section 15) — they're a product-configuration concern, not a distinct
 * SRS-listed module (Sec. 20.3's sidebar has no "Units" item either), so
 * this reuses the products permission set rather than inventing new
 * permission rows the reviewed schema never defined.
 */
class UnitManager extends Component
{
    use WithPagination, AuthorizesModuleActions;

    public string $search = '';

    public ?int $editingUnitId = null;

    public string $name = '';

    public ?int $unitIdPendingDeactivation = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.units.unit-manager', [
            'units' => Unit::withCount(['productsAsPurchaseUnit', 'productsAsSellingUnit'])
                ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
                ->orderBy('name')
                ->paginate(10),
        ]);
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', Rule::unique('units', 'name')->ignore($this->editingUnitId)],
        ];
    }

    public function create(): void
    {
        $this->authorizeAction('products', 'create');

        $this->reset(['editingUnitId', 'name']);
        $this->resetValidation();

        $this->dispatch('open-modal', 'unit-form');
    }

    public function edit(int $unitId): void
    {
        $this->authorizeAction('products', 'update');

        $unit = Unit::findOrFail($unitId);

        $this->editingUnitId = $unit->id;
        $this->name = $unit->name;
        $this->resetValidation();

        $this->dispatch('open-modal', 'unit-form');
    }

    public function save(): void
    {
        $isCreating = ! $this->editingUnitId;
        $this->authorizeAction('products', $isCreating ? 'create' : 'update');

        $validated = $this->validate();

        $previous = null;

        if ($isCreating) {
            $unit = Unit::create($validated + ['is_active' => true]);
        } else {
            $unit = Unit::findOrFail($this->editingUnitId);
            $previous = $unit->only(['name']);
            $unit->update($validated);
        }

        AuditLog::record(
            $isCreating ? 'create' : 'update',
            'units',
            'Unit',
            $unit->id,
            $previous,
            $unit->only(['name']),
        );

        $this->dispatch('close-modal', 'unit-form');
        $this->dispatch('flash-message', message: $isCreating ? 'Unit created.' : 'Unit updated.', variant: 'success');
    }

    public function confirmDeactivate(int $unitId): void
    {
        $this->authorizeAction('products', 'delete');

        $this->unitIdPendingDeactivation = $unitId;
        $this->dispatch('open-modal', 'confirm-deactivate-unit');
    }

    public function toggleStatus(): void
    {
        $this->authorizeAction('products', 'delete');

        $unit = Unit::findOrFail($this->unitIdPendingDeactivation);
        $previous = $unit->only(['is_active']);

        $unit->is_active = ! $unit->is_active;
        $unit->save();

        AuditLog::record('update', 'units', 'Unit', $unit->id, $previous, $unit->only(['is_active']));

        $this->dispatch('close-modal', 'confirm-deactivate-unit');
        $this->dispatch('flash-message', message: $unit->is_active ? 'Unit reactivated.' : 'Unit deactivated.', variant: 'success');
        $this->unitIdPendingDeactivation = null;
    }
}
