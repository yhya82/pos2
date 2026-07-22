<?php

namespace App\Livewire\Roles;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

class RoleManager extends Component
{
    use WithPagination, AuthorizesModuleActions;

    public string $search = '';

    public ?int $editingRoleId = null;

    #[Validate('required|string|max:100')]
    public string $name = '';

    #[Validate('nullable|string|max:255')]
    public string $description = '';

    #[Validate('required|in:active,inactive')]
    public string $status = 'active';

    /** @var array<int, int> */
    public array $selectedPermissions = [];

    public ?int $roleIdPendingDeactivation = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.roles.role-manager', [
            'roles' => Role::withCount('users')
                ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
                ->orderBy('name')
                ->paginate(10),
            'permissionsByModule' => Permission::orderBy('module')->orderBy('action')->get()->groupBy('module'),
        ]);
    }

    public function create(): void
    {
        $this->authorizeAction('roles', 'create');

        $this->reset(['editingRoleId', 'name', 'description', 'selectedPermissions']);
        $this->status = 'active';
        $this->resetValidation();

        $this->dispatch('open-modal', 'role-form');
    }

    public function edit(int $roleId): void
    {
        $this->authorizeAction('roles', 'update');

        $role = Role::findOrFail($roleId);

        $this->editingRoleId = $role->id;
        $this->name = $role->name;
        $this->description = (string) $role->description;
        $this->status = $role->status;
        $this->selectedPermissions = $role->permissions()->pluck('permissions.id')->all();
        $this->resetValidation();

        $this->dispatch('open-modal', 'role-form');
    }

    public function save(): void
    {
        $isCreating = ! $this->editingRoleId;
        $this->authorizeAction('roles', $isCreating ? 'create' : 'update');

        $this->validate();

        $previous = null;

        if ($isCreating) {
            $role = Role::create([
                'name' => $this->name,
                'description' => $this->description ?: null,
                'status' => $this->status,
            ]);
        } else {
            $role = Role::findOrFail($this->editingRoleId);
            $previous = $role->only(['name', 'description', 'status']);
            $role->update([
                'name' => $this->name,
                'description' => $this->description ?: null,
                'status' => $this->status,
            ]);
        }

        $role->permissions()->sync($this->selectedPermissions);

        AuditLog::record(
            $isCreating ? 'create' : 'update',
            'roles',
            'Role',
            $role->id,
            $previous,
            $role->only(['name', 'description', 'status']),
        );

        $this->dispatch('close-modal', 'role-form');
        $this->dispatch('flash-message', message: $isCreating ? 'Role created.' : 'Role updated.', variant: 'success');
    }

    public function confirmDeactivate(int $roleId): void
    {
        $this->authorizeAction('roles', 'delete');

        $this->roleIdPendingDeactivation = $roleId;
        $this->dispatch('open-modal', 'confirm-deactivate-role');
    }

    public function toggleStatus(): void
    {
        $this->authorizeAction('roles', 'delete');

        $role = Role::withCount('users')->findOrFail($this->roleIdPendingDeactivation);

        if ($role->status === 'active' && $role->users_count > 0) {
            $this->dispatch('flash-message', message: "Can't deactivate \"{$role->name}\" — {$role->users_count} user(s) still have this role. Reassign them first.", variant: 'error');
            $this->dispatch('close-modal', 'confirm-deactivate-role');

            return;
        }

        $previous = $role->only(['status']);
        $role->status = $role->status === 'active' ? 'inactive' : 'active';
        $role->save();

        AuditLog::record('update', 'roles', 'Role', $role->id, $previous, $role->only(['status']));

        $this->dispatch('close-modal', 'confirm-deactivate-role');
        $this->dispatch('flash-message', message: $role->status === 'active' ? 'Role reactivated.' : 'Role deactivated.', variant: 'success');
        $this->roleIdPendingDeactivation = null;
    }
}
