<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every hash a user's password has ever been set to, kept so "can't
 * reuse your last N passwords" (security_settings.password_history_count)
 * has something real to check against. Rows are pruned to the most
 * recent 20 per user in User::setPassword() — there's no UPDATE_AT or
 * edit path, this is append-only in practice even without a trigger
 * enforcing it.
 */
class PasswordHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
