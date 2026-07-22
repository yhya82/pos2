<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Minimal shell so the header's notification bell can show a real unread
 * count — the full notification list/read UI (R19) is built out in
 * Phase 08, not here.
 */
class SystemNotification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'category',
        'message',
        'target_user_id',
        'target_role_id',
        'related_table',
        'related_id',
        'is_read',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
        ];
    }

    public static function unreadCountFor(User $user): int
    {
        return static::where('is_read', false)
            ->where(function ($query) use ($user) {
                $query->where('target_user_id', $user->id)
                    ->orWhere('target_role_id', $user->role_id);
            })
            ->count();
    }
}
