<?php

namespace App\Livewire\Forms;

use App\Models\AuditLog;
use App\Models\LoginSession;
use App\Models\ModuleSetting;
use App\Models\NotificationSetting;
use App\Models\SecuritySetting;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
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
     * One lockout mechanism: the schema's own account-level lockout
     * (security_settings-configurable, persisted on the user row — the
     * SRS-specified behavior). A generic IP+email rate limiter used to
     * sit in front of this too, but it was a second, non-configurable
     * "try again in N seconds" wall with its own hardcoded 5-attempts/
     * 60-second threshold — unrelated to Security Settings, and
     * confusing to hit before the real, configured lockout ever applied.
     * It added no real protection an unknown email doesn't already lack
     * (there's no account row to protect there either way), so it was
     * removed rather than reconciled with this one. "Remember me" is
     * deliberately not offered: the schema has no remember_token column,
     * and session lifetime is governed explicitly by
     * security_settings.session_timeout_minutes instead of a long-lived
     * cookie.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $user = User::where('email', $this->email)->first();

        $this->ensureAccountIsActive($user);
        $this->ensureAccountIsNotLocked($user);

        if (! Auth::attempt($this->only(['email', 'password']))) {
            $message = $user
                ? $this->failedAttemptMessage($this->registerFailedAttempt($user))
                : 'Invalid credentials.';

            // Never the password — only who they tried to be. An unknown email
            // is logged too: someone guessing usernames is worth seeing.
            AuditLog::security('login_failed', $user?->id, [
                'email' => $this->email,
                'known_account' => (bool) $user,
                'attempts' => $user?->failed_login_attempts,
            ], 'User');

            throw ValidationException::withMessages([
                'form.email' => $message,
            ]);
        }

        AuditLog::security('login', $user->id, ['email' => $user->email], 'User');

        $this->flagIfNewDevice($user);

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
     * Flags (never blocks) a login from an IP this user hasn't signed in
     * from before — checked against login_sessions history, which is
     * why this runs before LoginSession::startFor() creates this login's
     * own row (otherwise every login would "recognize" itself). Logged
     * to the Security audit trail and, if the notifications module and
     * its "System" category are both on, surfaced to the user in-app.
     */
    protected function flagIfNewDevice(User $user): void
    {
        $ip = request()->ip();

        $seenBefore = LoginSession::where('user_id', $user->id)->where('ip_address', $ip)->exists();

        if ($seenBefore) {
            return;
        }

        AuditLog::security('login_new_device', $user->id, ['email' => $user->email, 'ip' => $ip], 'User');

        try {
            if (ModuleSetting::enabled('notifications') && NotificationSetting::where('category', 'user_system')->value('is_enabled')) {
                SystemNotification::create([
                    'category' => 'user_system',
                    'message' => "A new sign-in to your account was detected from {$ip}.",
                    'target_user_id' => $user->id,
                    'related_table' => 'users',
                    'related_id' => $user->id,
                    'is_read' => false,
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
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

        AuditLog::security('login_blocked', $user->id, ['email' => $user->email, 'reason' => 'account deactivated'], 'User');

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

        AuditLog::security('login_blocked', $user->id, ['email' => $user->email, 'reason' => 'account locked', 'minutes_left' => $minutes], 'User');

        throw ValidationException::withMessages([
            'form.email' => "Too many failed login attempts. This account is locked for another {$minutes} minute(s).",
        ]);
    }

    /**
     * @return array{attempts: int, locked: bool} so the caller can word
     *         the response differently the instant an account actually
     *         locks, versus a failed attempt that still has some left.
     */
    protected function registerFailedAttempt(User $user): array
    {
        $security = SecuritySetting::current();
        $attempts = $user->failed_login_attempts + 1;

        $locks = $attempts >= $security->max_failed_login_attempts;

        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $locks ? now()->addMinutes($security->lockout_duration_minutes) : null,
        ])->save();

        if ($locks) {
            AuditLog::security('account_locked', $user->id, ['email' => $user->email, 'failed_attempts' => $attempts, 'locked_minutes' => $security->lockout_duration_minutes], 'User');
        }

        return ['attempts' => $attempts, 'locked' => $locks];
    }

    /**
     * @param  array{attempts: int, locked: bool}  $result
     */
    protected function failedAttemptMessage(array $result): string
    {
        if ($result['locked']) {
            $minutes = SecuritySetting::current()->lockout_duration_minutes;

            return "Too many failed login attempts. This account is locked for {$minutes} minute(s).";
        }

        $remaining = SecuritySetting::current()->max_failed_login_attempts - $result['attempts'];

        if ($remaining <= 0) {
            return 'Invalid credentials.';
        }

        $word = Str::plural('attempt', $remaining);

        return "Invalid credentials. {$remaining} {$word} remaining before this account is locked.";
    }
}
