<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Minimal shell for now — Phase 01 adds role management UI and
 * permission-assignment screens on top of this.
 */
class Permission extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'module',
        'action',
        'description',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
            ->withPivot('granted_at');
    }
}
