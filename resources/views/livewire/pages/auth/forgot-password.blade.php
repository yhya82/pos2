<?php

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $email = '';

    /**
     * Send a password reset link to the provided email address.
     *
     * Laravel's own broker already throttles repeat requests for the SAME
     * email (one per 60s), but nothing stopped cycling through many
     * DIFFERENT emails rapidly from one IP — a mail-bomb / enumeration-
     * adjacent abuse vector this IP-keyed limiter closes, independent of
     * the broker's own per-email one.
     */
    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $ipKey = 'password-reset-ip:'.request()->ip();

        if (RateLimiter::tooManyAttempts($ipKey, 6)) {
            throw ValidationException::withMessages([
                'email' => 'Too many reset requests from this network. Please wait a minute and try again.',
            ]);
        }

        RateLimiter::hit($ipKey, 60);

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        Password::sendResetLink(
            $this->only('email')
        );

        $this->reset('email');

        // Deliberately the same message whether or not that email matches a
        // real account, and always the success flash, never an inline field
        // error — Laravel's stock 'passwords.user'/'passwords.sent' strings
        // differ in exactly that way, which is an account-enumeration oracle
        // on its own merits regardless of the login screen's own messages.
        session()->flash('status', "If that email address is registered, we've emailed a password reset link to it.");
    }
}; ?>

<div>
    <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        {{ __('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form wire:submit="sendPasswordResetLink">
        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>
                {{ __('Email Password Reset Link') }}
            </x-primary-button>
        </div>
    </form>
</div>
