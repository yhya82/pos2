<?php

namespace App\Livewire\Users;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class UserProfile extends Component
{
    use AuthorizesModuleActions;

    #[Locked]
    public int $userId;

    // --- Editable account details (mirrors UserManager's edit form —
    // this page is "click a user's name" replacing that slide-over with a
    // full page, same fields/rules, not a different capability).
    // Password is deliberately not here — changing an existing user's
    // password is exclusively the standalone "Change Password" action on
    // the Users index now, not an edit-form field. ---
    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $phone = '';

    public ?int $roleId = null;

    public string $status = 'active';

    public bool $editingDetails = false;

    public function mount(User $user): void
    {
        $this->authorizeAction('users', 'view');

        $this->userId = $user->id;
        $this->fillFrom($user);
    }

    private function fillFrom(User $user): void
    {
        $this->name = $user->name;
        $this->username = $user->username;
        $this->email = (string) $user->email;
        $this->phone = $user->phone ? substr($user->phone, 4) : '';
        $this->roleId = $user->role_id;
        $this->status = $user->status;
    }

    public function editDetails(): void
    {
        $this->authorizeAction('users', 'update');

        $this->fillFrom(User::findOrFail($this->userId));
        $this->resetValidation();
        $this->editingDetails = true;
    }

    public function cancelEditDetails(): void
    {
        $this->fillFrom(User::findOrFail($this->userId));
        $this->resetValidation();
        $this->editingDetails = false;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:100', Rule::unique('users', 'username')->ignore($this->userId)],
            'email' => ['nullable', 'string', 'email', 'max:150', Rule::unique('users', 'email')->ignore($this->userId)],
            'phone' => ['required', 'digits:9'],
            'roleId' => ['required', 'exists:roles,id'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    protected function messages(): array
    {
        return [
            'phone.digits' => 'Phone number must be exactly 9 digits (the +220 prefix is added automatically).',
        ];
    }

    public function save(): void
    {
        $this->authorizeAction('users', 'update');

        $validated = $this->validate();

        $user = User::findOrFail($this->userId);

        // Uniqueness has to be checked against the full +220-prefixed value
        // actually stored in the column — see UserManager::save() for why.
        $fullPhone = '+220'.$validated['phone'];

        $phoneTaken = User::where('phone', $fullPhone)->where('id', '!=', $this->userId)->exists();

        if ($phoneTaken) {
            $this->addError('phone', 'This phone number is already in use.');

            return;
        }

        $attributes = [
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'] ?: null,
            'phone' => $fullPhone,
            'role_id' => $validated['roleId'],
            'status' => $validated['status'],
        ];

        $previous = $user->only(['name', 'username', 'email', 'phone', 'role_id', 'status']);
        $user->update($attributes);

        AuditLog::record('update', 'users', 'User', $user->id, $previous, $user->only(['name', 'username', 'email', 'phone', 'role_id', 'status']));

        $this->editingDetails = false;
        $this->dispatch('flash-message', message: 'User updated.', variant: 'success');
    }

    public function render()
    {
        $user = User::with('role.permissions')->findOrFail($this->userId);

        return view('livewire.users.user-profile', [
            'user' => $user,
            'canEdit' => auth()->user()->hasPermission('users', 'update'),
            'roles' => Role::where('status', 'active')->orderBy('name')->get(),
            'permissionCount' => $user->role->permissions->count(),
        ]);
    }
}
