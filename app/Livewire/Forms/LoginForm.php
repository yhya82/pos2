<?php

namespace App\Livewire\Forms;

use App\Models\SecuritySetting;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Form;

class LoginForm extends Form
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    /**
     * Attempt to authenticate the request's credentials.
     *
     * Two independent lockout mechanisms are layered here: Laravel's own
     * IP+email RateLimiter (cheap, generic brute-force throttling) and the
     * schema's own account-level lockout (security_settings-configurable,
     * persisted on the user row — the SRS-specified behavior). "Remember
     * me" is deliberately not offered: the schema has no remember_token
     * column, and session lifetime is governed explicitly by
     * security_settings.session_timeout_minutes instead of a long-lived
     * cookie.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = User::where('email', $this->email)->first();

        $this->ensureAccountIsActive($user);
        $this->ensureAccountIsNotLocked($user);

        if (! Auth::attempt($this->only(['email', 'password']))) {
            RateLimiter::hit($this->throttleKey());

            if ($user) {
                $this->registerFailedAttempt($user);
            }

            throw ValidationException::withMessages([
                'form.email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ])->save();

        // The login_sessions row is created by the caller, after it
        // regenerates the session ID — not here. Session::regenerate()
        // swaps the session ID for security (prevents session fixation),
        // and login_sessions.token_hash needs to match whatever ID is
        // actually still valid on the NEXT request, not the one that's
        // about to be discarded.
    }

    /**
     * A deactivated user (Users screen "Deactivate" action) must not be
     * able to log in at all — status alone, independent of the lockout
     * mechanism below, since deactivation isn't a failed-attempt penalty.
     */
    protected function ensureAccountIsActive(?User $user): void
    {
        if (! $user || $user->status === 'active') {
            return;
        }

        throw ValidationException::withMessages([
            'form.email' => 'This account has been deactivated. Contact an administrator.',
        ]);
    }

    /**
     * Blocks the attempt outright — without even checking the password —
     * once security_settings.max_failed_login_attempts has been reached,
     * for the configured lockout_duration_minutes.
     */
    protected function ensureAccountIsNotLocked(?User $user): void
    {
        if (! $user || ! $user->locked_until || $user->locked_until->isPast()) {
            return;
        }

        $minutes = (int) ceil(now()->diffInMinutes($user->locked_until, true));

        throw ValidationException::withMessages([
            'form.email' => "Too many failed login attempts. This account is locked for another {$minutes} minute(s).",
        ]);
    }

    protected function registerFailedAttempt(User $user): void
    {
        $security = SecuritySetting::current();
        $attempts = $user->failed_login_attempts + 1;

        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $attempts >= $security->max_failed_login_attempts
                ? now()->addMinutes($security->lockout_duration_minutes)
                : null,
        ])->save();
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'form.email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the authentication rate limiting throttle key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}
