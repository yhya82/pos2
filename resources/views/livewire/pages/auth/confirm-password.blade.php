<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $password = '';

    /**
     * Confirm the current user's password.
     *
     * This screen is reachable by anyone already holding a valid session
     * (a stolen cookie, an unlocked terminal) and, unlike the login screen,
     * used to call Auth::guard('web')->validate() directly with no lockout
     * at all — unlimited, unthrottled guesses against the real password,
     * completely bypassing the account-lockout control the login screen
     * enforces. It now shares that exact same lockout with LoginForm via
     * User::isLockedOut()/registerFailedLoginAttempt().
     */
    public function confirmPassword(): void
    {
        $this->validate([
            'password' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        if ($user->isLockedOut()) {
            $minutes = $user->lockoutMinutesRemaining();

            throw ValidationException::withMessages([
                'password' => "Too many failed attempts. This account is locked for another {$minutes} minute(s).",
            ]);
        }

        if (! Auth::guard('web')->validate([
            'email' => $user->email,
            'password' => $this->password,
        ])) {
            $result = $user->registerFailedLoginAttempt();

            AuditLog::security('login_failed', $user->id, [
                'email' => $user->email,
                'known_account' => true,
                'attempts' => $result['attempts'],
                'context' => 'password_confirmation',
            ], 'User');

            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        session(['auth.password_confirmed_at' => time()]);

        $this->redirectIntended(default: $user->defaultLandingRoute(), navigate: true);
    }
}; ?>

<div>
    <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        {{ __('This is a secure area of the application. Please confirm your password before continuing.') }}
    </div>

    <form wire:submit="confirmPassword">
        <!-- Password -->
        <div>
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input wire:model="password"
                          id="password"
                          class="block mt-1 w-full"
                          type="password"
                          name="password"
                          required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="flex justify-end mt-4">
            <x-primary-button>
                {{ __('Confirm') }}
            </x-primary-button>
        </div>
    </form>
</div>
