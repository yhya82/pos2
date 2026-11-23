<?php

use App\Models\SecuritySetting;
use App\Rules\PasswordPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function with(): array
    {
        return ['passwordPolicyHint' => SecuritySetting::current()->passwordPolicyHint()];
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', 'confirmed', new PasswordPolicy(Auth::user())],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        // Keeps this session alive — self-service shouldn't log you out
        // of the very session you used to make the change.
        Auth::user()->setPassword($validated['password'], session()->getId());

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }
}; ?>

<div class="card">
    <div class="cardhead">
        <span class="ic"><svg viewBox="0 0 24 24"><path d="M15.75 7.5a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/><path d="M11.25 9.75L3 18v3h3l8.25-8.25"/></svg></span>
        <h3>{{ __('Change Password') }}</h3>
    </div>

    <form wire:submit="updatePassword" class="cardbody">
        <div class="f">
            <label for="update_password_current_password">{{ __('Current Password') }}<span class="req">*</span></label>
            <input wire:model="current_password" id="update_password_current_password" name="current_password" type="password" autocomplete="current-password">
            <div class="hint">{{ __("Needed to confirm it's really you.") }}</div>
            <x-input-error :messages="$errors->get('current_password')" class="mt-2" />
        </div>

        <div class="f">
            <label for="update_password_password">{{ __('New Password') }}<span class="req">*</span></label>
            <input wire:model="password" id="update_password_password" name="password" type="password" autocomplete="new-password">
            <div class="hint">{{ $passwordPolicyHint }}</div>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="f">
            <label for="update_password_password_confirmation">{{ __('Confirm Password') }}<span class="req">*</span></label>
            <input wire:model="password_confirmation" id="update_password_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <button type="submit" class="btn p">
            <svg viewBox="0 0 24 24"><path d="M15.75 7.5a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/><path d="M11.25 9.75L3 18v3h3l8.25-8.25"/></svg>
            {{ __('Update Password') }}
        </button>

        <x-action-message class="saved" on="password-updated">
            {{ __('Saved.') }}
        </x-action-message>
    </form>
</div>
