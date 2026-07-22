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
        'password_reset_token_ttl_minutes',
        'updated_by',
    ];

    public static function current(): self
    {
        return static::find(1);
    }
}
