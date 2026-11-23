<?php

namespace App\Rules;

use App\Models\AuditLog;
use App\Models\SecuritySetting;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The one rule behind every "set a password" screen in the app (self-
 * service, admin create/reset, forgot-password, the forced change after
 * expiry) — length, required character classes, and reuse-history are
 * all configured once in Settings > Security and enforced identically
 * everywhere, rather than each screen rolling its own min:N string.
 *
 * $user is who the password is being set FOR, used only for the reuse
 * check — pass null when there's no one to compare against yet (a
 * brand-new account being created has no prior passwords to reuse).
 */
class PasswordPolicy implements ValidationRule
{
    public function __construct(private readonly ?User $user = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;
        $security = SecuritySetting::current();

        if (mb_strlen($value) < $security->password_min_length) {
            $fail("Password must be at least {$security->password_min_length} characters.");
        }

        if ($security->password_require_uppercase && ! preg_match('/[A-Z]/', $value)) {
            $fail('Password must include an uppercase letter.');
        }

        if ($security->password_require_lowercase && ! preg_match('/[a-z]/', $value)) {
            $fail('Password must include a lowercase letter.');
        }

        if ($security->password_require_number && ! preg_match('/[0-9]/', $value)) {
            $fail('Password must include a number.');
        }

        if ($security->password_require_symbol && ! preg_match('/[^A-Za-z0-9]/', $value)) {
            $fail('Password must include a symbol (e.g. ! @ # $).');
        }

        if ($this->user && $this->user->passwordWasUsedRecently($value)) {
            $fail("That was one of your last {$security->password_history_count} passwords — choose a different one.");

            AuditLog::security('password_reused_blocked', $this->user->id, ['email' => $this->user->email], 'User');
        }
    }
}
