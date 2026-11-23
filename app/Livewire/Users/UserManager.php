<?php

namespace App\Livewire\Users;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Rules\PasswordPolicy;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class UserManager extends Component
{
    use WithPagination, AuthorizesModuleActions;

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

    public ?int $passwordUserId = null;

    public string $passwordUserName = '';

    public string $newPassword = '';

    public string $confirmPassword = '';

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
            'passwordPolicyHint' => $this->passwordPolicyHint(),
            'security' => SecuritySetting::current(),
        ]);
    }

    protected function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:100', Rule::unique('users', 'username')->ignore($this->editingUserId)],
            'email' => ['nullable', 'string', 'email', 'max:150', Rule::unique('users', 'email')->ignore($this->editingUserId)],
            'phone' => ['required', 'digits:9'],
            'roleId' => ['required', 'exists:roles,id'],
            'status' => ['required', 'in:active,inactive'],
            // Only asked for when creating a brand-new account — changing
            // an existing user's password is exclusively the standalone
            // "Change Password" action now (changePassword()/
            // savePassword()), so editing never touches this at all.
            'password' => $this->editingUserId
                ? ['nullable']
                : ['required', 'string', new PasswordPolicy(null)],
        ];

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'phone.digits' => 'Phone number must be exactly 9 digits (the +220 prefix is added automatically).',
        ];
    }

    public function create(): void
    {
        $this->authorizeAction('users', 'create');

        $this->reset(['editingUserId', 'name', 'username', 'email', 'phone', 'roleId', 'password']);
        $this->status = 'active';
        $this->resetValidation();

        $this->dispatch('open-modal', 'user-form');
    }

    public function edit(int $userId): void
    {
        $this->authorizeAction('users', 'update');

        $user = User::findOrFail($userId);

        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->username = $user->username;
        $this->email = (string) $user->email;
        $this->phone = $user->phone ? substr($user->phone, 4) : '';
        $this->roleId = $user->role_id;
        $this->status = $user->status;
        $this->password = '';
        $this->resetValidation();

        $this->dispatch('open-modal', 'user-form');
    }

    public function save(): void
    {
        $this->authorizeAction('users', $this->editingUserId ? 'update' : 'create');

        $validated = $this->validate();

        // Uniqueness has to be checked against the full +220-prefixed value
        // actually stored in the column — the form only collects the local
        // 9 digits, so Rule::unique('users', 'phone') on that raw input
        // would never match anything and let duplicates through silently.
        $fullPhone = '+220'.$validated['phone'];

        $phoneTaken = User::where('phone', $fullPhone)
            ->when($this->editingUserId, fn ($q) => $q->where('id', '!=', $this->editingUserId))
            ->exists();

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

        $previous = null;
        $isCreating = ! $this->editingUserId;

        if ($isCreating) {
            $user = User::create($attributes);
            // A brand-new account has no session of its own yet to protect.
            $user->setPassword($this->password);
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

    /**
     * A standalone "reset this person's password" action, separate from
     * the full Edit form — same underlying effect (PasswordPolicy +
     * User::setPassword(), so history/expiry/complexity and the
     * force-logout-other-sessions rule all still apply), just reachable
     * in one click from the Actions column instead of opening the whole
     * edit form to fill one field.
     */
    public function changePassword(int $userId): void
    {
        $this->authorizeAction('users', 'update');

        $user = User::findOrFail($userId);

        $this->passwordUserId = $user->id;
        $this->passwordUserName = $user->name;
        $this->newPassword = '';
        $this->confirmPassword = '';
        $this->resetValidation();

        $this->dispatch('open-modal', 'change-password');
    }

    public function savePassword(): void
    {
        $this->authorizeAction('users', 'update');

        $user = User::findOrFail($this->passwordUserId);
        $isOwnAccount = $user->id === auth()->id();

        $validated = $this->validate([
            'newPassword' => ['required', 'string', new PasswordPolicy($user)],
            'confirmPassword' => ['required', 'string', 'same:newPassword'],
        ], [
            'confirmPassword.same' => "Confirm Password doesn't match the new password.",
        ]);

        // No current-password proof required, self or other — this is an
        // admin tool (users,update is already required to get here), not
        // the self-service Profile page's own password form. An admin
        // resetting someone else's password has no session of their own
        // on that account to protect, so every one of their sessions is
        // revoked; changing your own keeps this session alive.
        $user->setPassword($validated['newPassword'], $isOwnAccount ? session()->getId() : null);

        $this->dispatch('close-modal', 'change-password');
        $this->dispatch('flash-message', message: "Password changed for {$user->name}.", variant: 'success');

        $this->reset(['passwordUserId', 'passwordUserName', 'newPassword', 'confirmPassword']);
    }

    private function passwordPolicyHint(): string
    {
        return SecuritySetting::current()->passwordPolicyHint();
    }

    public function confirmDeactivate(int $userId): void
    {
        $this->authorizeAction('users', 'delete');

        if ($userId === auth()->id()) {
            $this->dispatch('flash-message', message: "You can't deactivate your own account.", variant: 'error');

            return;
        }

        $this->userIdPendingDeactivation = $userId;
        $this->dispatch('open-modal', 'confirm-deactivate-user');
    }

    public function toggleStatus(): void
    {
        $this->authorizeAction('users', 'delete');

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
