<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginSession extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'token_hash',
        'ip_address',
        'user_agent',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a new session for a just-authenticated user, keyed by a hash
     * of the framework session ID — that's the same session PHP already
     * ties the auth cookie to, so it's a reasonable "this browser session"
     * token without inventing a second one.
     */
    public static function startFor(User $user, string $sessionId, string $ipAddress, ?string $userAgent): self
    {
        return static::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $sessionId),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'expires_at' => now()->addMinutes(SecuritySetting::current()->session_timeout_minutes),
        ]);
    }

    public static function findActiveBySessionId(string $sessionId): ?self
    {
        return static::where('token_hash', hash('sha256', $sessionId))
            ->whereNull('revoked_at')
            ->first();
    }

    public static function revokeBySessionId(string $sessionId): void
    {
        static::where('token_hash', hash('sha256', $sessionId))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
