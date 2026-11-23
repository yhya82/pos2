<?php

use App\Livewire\Actions\Logout;
use App\Rules\PasswordPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * EnsurePasswordNotExpired is what actually keeps someone pinned to
     * this page — this just does the change itself, the same way
     * everywhere else a password gets set does.
     */
    public function updatePassword(): void
    {
        try {
            $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', 'confirmed', new PasswordPolicy(Auth::user())],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        // Keeps this session alive — they're sitting in it right now, so
        // force-logout-on-change shouldn't also sign them out of it.
        Auth::user()->setPassword($this->password, session()->getId());

        // The retroactive-policy trigger (EnsurePasswordNotExpired) has no
        // timestamp to re-derive from like expiry does — it's cleared
        // explicitly, here, now that the password actually complies.
        session()->forget('must_change_password');

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    public function logoutInstead(Logout $logout): void
    {
        $logout();

        $this->redirect(route('login'), navigate: true);
    }
}; ?>

<div>
    <div class="flex flex-col items-center text-center mb-8">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Update your password</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Your password needs to be updated before you can continue — it's either expired or no longer meets the current password policy.</p>
    </div>

    <form wire:submit="updatePassword" class="space-y-5">
        <div>
            <x-input-label for="current_password" :value="__('Current Password')" />
            <x-text-input wire:model="current_password" id="current_password" class="block mt-1 w-full py-2.5" type="password" autocomplete="current-password" required autofocus />
            <x-input-error :messages="$errors->get('current_password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" :value="__('New Password')" />
            <x-text-input wire:model="password" id="password" class="block mt-1 w-full py-2.5" type="password" autocomplete="new-password" required />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input wire:model="password_confirmation" id="password_confirmation" class="block mt-1 w-full py-2.5" type="password" autocomplete="new-password" required />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between gap-4 pt-1">
            <button type="button" wire:click="logoutInstead" class="text-sm text-gray-500 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800">
                Log out instead
            </button>

            <x-primary-button class="ms-auto w-full sm:w-auto justify-center">
                Update password
            </x-primary-button>
        </div>
    </form>
</div>
