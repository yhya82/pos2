<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton settings row (id is always 1, enforced by the schema's own
 * CHECK constraint). Backs login lockout and session-timeout enforcement.
 */
class SecuritySetting extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'session_timeout_minutes',
        'max_failed_login_attempts',
        'lockout_duration_minutes',
        'password_min_length',
        'password_history_count',
        'password_expiry_days',
        'password_require_uppercase',
        'password_require_lowercase',
        'password_require_number',
        'password_require_symbol',
        'password_reset_token_ttl_minutes',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'password_require_uppercase' => 'boolean',
            'password_require_lowercase' => 'boolean',
            'password_require_number' => 'boolean',
            'password_require_symbol' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::find(1);
    }

    /**
     * The actual configured rules, in one sentence — shown under every
     * "New Password" field in the app (Profile, the Users edit form, the
     * Users index's standalone Change Password action, and the forced
     * change page) so it's defined once, not duplicated per screen. Only
     * mentions the character classes genuinely required right now, so it
     * can never claim a rule PasswordPolicy itself doesn't also enforce.
     */
    public function passwordPolicyHint(): string
    {
        $classes = array_filter([
            $this->password_require_uppercase ? 'an uppercase letter' : null,
            $this->password_require_lowercase ? 'a lowercase letter' : null,
            $this->password_require_number ? 'a number' : null,
            $this->password_require_symbol ? 'a symbol' : null,
        ]);

        $sentence = "At least {$this->password_min_length} characters";
        $sentence .= $classes ? ', with '.$this->joinWithAnd($classes).'.' : '.';

        if ($this->password_history_count > 0) {
            $sentence .= " Can't reuse your last {$this->password_history_count} passwords.";
        }

        return $sentence;
    }

    private function joinWithAnd(array $items): string
    {
        $items = array_values($items);

        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
