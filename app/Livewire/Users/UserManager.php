<?php

namespace App\Livewire\Users;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SecuritySetting;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class UserManager extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $editingUserId = null;

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $phone = '';

    public ?int $roleId = null;

    public string $status = 'active';

    public string $password = '';

    public ?int $userIdPendingDeactivation = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.users.user-manager', [
            'users' => User::with('role')
                ->when($this->search, fn ($query) => $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('username', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%");
                }))
                ->orderBy('name')
                ->paginate(10),
            'roles' => Role::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    protected function rules(): array
    {
        $passwordMin = SecuritySetting::current()->password_min_length;

        return [
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:100', Rule::unique('users', 'username')->ignore($this->editingUserId)],
            'email' => ['nullable', 'string', 'email', 'max:150', Rule::unique('users', 'email')->ignore($this->editingUserId)],
            'phone' => ['nullable', 'string', 'max:30'],
            'roleId' => ['required', 'exists:roles,id'],
            'status' => ['required', 'in:active,inactive'],
            'password' => [$this->editingUserId ? 'nullable' : 'required', 'string', "min:{$passwordMin}"],
        ];
    }

    public function create(): void
    {
        $this->reset(['editingUserId', 'name', 'username', 'email', 'phone', 'roleId', 'password']);
        $this->status = 'active';
        $this->resetValidation();

        $this->dispatch('open-modal', 'user-form');
    }

    public function edit(int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->username = $user->username;
        $this->email = (string) $user->email;
        $this->phone = (string) $user->phone;
        $this->roleId = $user->role_id;
        $this->status = $user->status;
        $this->password = '';
        $this->resetValidation();

        $this->dispatch('open-modal', 'user-form');
    }

    public function save(): void
    {
        $validated = $this->validate();

        $attributes = [
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'] ?: null,
            'phone' => $validated['phone'] ?: null,
            'role_id' => $validated['roleId'],
            'status' => $validated['status'],
        ];

        if (filled($this->password)) {
            $attributes['password_hash'] = $this->password;
        }

        $previous = null;
        $isCreating = ! $this->editingUserId;

        if ($isCreating) {
            $user = User::create($attributes);
        } else {
            $user = User::findOrFail($this->editingUserId);
            $previous = $user->only(['name', 'username', 'email', 'phone', 'role_id', 'status']);
            $user->update($attributes);
        }

        AuditLog::record(
            $isCreating ? 'create' : 'update',
            'users',
            'User',
            $user->id,
            $previous,
            $user->only(['name', 'username', 'email', 'phone', 'role_id', 'status']),
        );

        $this->dispatch('close-modal', 'user-form');
        $this->dispatch('flash-message', message: $isCreating ? 'User created.' : 'User updated.', variant: 'success');
    }

    public function confirmDeactivate(int $userId): void
    {
        if ($userId === auth()->id()) {
            $this->dispatch('flash-message', message: "You can't deactivate your own account.", variant: 'error');

            return;
        }

        $this->userIdPendingDeactivation = $userId;
        $this->dispatch('open-modal', 'confirm-deactivate-user');
    }

    public function toggleStatus(): void
    {
        $user = User::findOrFail($this->userIdPendingDeactivation);
        $previous = $user->only(['status']);

        $user->status = $user->status === 'active' ? 'inactive' : 'active';
        $user->save();

        AuditLog::record('update', 'users', 'User', $user->id, $previous, $user->only(['status']));

        $this->dispatch('close-modal', 'confirm-deactivate-user');
        $this->dispatch('flash-message', message: $user->status === 'active' ? 'User reactivated.' : 'User deactivated.', variant: 'success');
        $this->userIdPendingDeactivation = null;
    }
}
