<?php

use App\Models\SecuritySetting;
use App\Rules\PasswordPolicy;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $password = '';
    public string $password_confirmation = '';

    public function with(): array
    {
        return ['security' => SecuritySetting::current()];
    }

    public function openModal(): void
    {
        $this->reset('password', 'password_confirmation');
        $this->resetValidation();
        $this->dispatch('open-modal', 'change-password');
    }

    /**
     * Update the password for the currently authenticated user.
     *
     * Validated as two separate rules (password_confirmation same:password)
     * rather than Laravel's 'confirmed' rule on password — 'confirmed'
     * attaches a mismatch error to the password field itself, not the
     * confirm field, which is the wrong field to shake/focus/flag red when
     * what's actually wrong is what was retyped, not the password itself.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'password' => ['required', 'string', new PasswordPolicy(Auth::user())],
                'password_confirmation' => ['required', 'string', 'same:password'],
            ], [
                'password_confirmation.same' => "Those don't match — retype your new password here.",
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // The error bag isn't populated yet at this point (Livewire
            // only builds it from the exception after it leaves this
            // method) — check the exception's own validator instead.
            if ($e->validator->errors()->has('password_confirmation')) {
                $this->dispatch('password-mismatch');
            }

            throw $e;
        }

        // Keeps this session alive — self-service shouldn't log you out
        // of the very session you used to make the change.
        Auth::user()->setPassword($validated['password'], session()->getId());

        $this->reset('password', 'password_confirmation');

        $this->dispatch('close-modal', 'change-password');
        $this->dispatch('flash-message', message: 'Password changed.', variant: 'success');
    }
}; ?>

<div>
    <button type="button" wire:click="openModal" class="btn p" style="width:100%; justify-content:center;">
        <svg viewBox="0 0 24 24"><path d="M15.75 7.5a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/><path d="M11.25 9.75L3 18v3h3l8.25-8.25"/></svg>
        {{ __('Change Password') }}
    </button>

    <x-modal name="change-password" max-width="sm">
        <div class="dialog">
            <div class="dh">
                <h2>{{ __('Change Password') }}</h2>
                <p>{{ __("Sets a new password right away. Every other device you're currently signed into will be signed out, this one stays signed in.") }}</p>
            </div>

            <form
                wire:submit="updatePassword"
                x-data="{ pw: '', shake: false }"
                x-on:password-mismatch.window="
                    shake = true;
                    $refs.confirmInput.focus();
                    setTimeout(() => shake = false, 500);
                "
            >
                <div class="db">
                    <div class="f">
                        <label for="update_password_password">{{ __('New Password') }}<span class="req">*</span></label>
                        <input wire:model="password" id="update_password_password" name="password" type="password" x-on:input="pw = $event.target.value" autocomplete="new-password">

                        <div class="pwchecklist">
                            <div class="pwcheck" :class="pw.length >= {{ $security->password_min_length }} ? 'met' : ''">
                                <span class="dot"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                                <span>{{ __('At least :n characters', ['n' => $security->password_min_length]) }}</span>
                            </div>
                            @if ($security->password_require_uppercase)
                                <div class="pwcheck" :class="/[A-Z]/.test(pw) ? 'met' : ''">
                                    <span class="dot"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                                    <span>{{ __('An uppercase letter') }}</span>
                                </div>
                            @endif
                            @if ($security->password_require_lowercase)
                                <div class="pwcheck" :class="/[a-z]/.test(pw) ? 'met' : ''">
                                    <span class="dot"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                                    <span>{{ __('A lowercase letter') }}</span>
                                </div>
                            @endif
                            @if ($security->password_require_number)
                                <div class="pwcheck" :class="/[0-9]/.test(pw) ? 'met' : ''">
                                    <span class="dot"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                                    <span>{{ __('A number') }}</span>
                                </div>
                            @endif
                            @if ($security->password_require_symbol)
                                <div class="pwcheck" :class="/[^A-Za-z0-9]/.test(pw) ? 'met' : ''">
                                    <span class="dot"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                                    <span>{{ __('A symbol') }}</span>
                                </div>
                            @endif
                        </div>
                        @if ($security->password_history_count > 0)
                            <div class="hint">{{ __("Can't reuse your last :n passwords (checked when you save).", ['n' => $security->password_history_count]) }}</div>
                        @endif
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>

                    <div class="f" style="margin-bottom:0;" :class="shake ? 'shake' : ''">
                        <label for="update_password_password_confirmation">{{ __('Confirm Password') }}<span class="req">*</span></label>
                        <input
                            wire:model="password_confirmation"
                            x-ref="confirmInput"
                            id="update_password_password_confirmation"
                            name="password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            @class(['err' => $errors->has('password_confirmation')])
                        >
                        <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                    </div>
                </div>
                <div class="df">
                    <button type="button" class="btn" x-on:click="$dispatch('close')">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn p">{{ __('Save') }}</button>
                </div>
            </form>
        </div>
    </x-modal>
</div>
