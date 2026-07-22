<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rows here are almost always trigger- or event-generated (low stock,
 * credit limit, expiry sweep), not created by application code — this
 * model exists to read and mark them read, per R19 / SRS Sec. 20.17.
 *
 * There's no per-user read-tracking: is_read is one column on the row
 * itself, so a role-targeted notification (target_role_id) is shared
 * read/unread state across everyone who holds that role. That's the
 * schema's actual design (a single boolean, not a junction table), not an
 * omission introduced here.
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
        'broadcast_at',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'created_at' => 'datetime',
            'broadcast_at' => 'datetime',
        ];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function ($q) use ($user) {
            $q->where('target_user_id', $user->id)
                ->orWhere('target_role_id', $user->role_id);
        });
    }

    public static function unreadCountFor(User $user): int
    {
        return static::where('is_read', false)->visibleTo($user)->count();
    }
}
