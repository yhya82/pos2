<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Minimal shell for now — Phase 01 adds role management UI and
 * permission-assignment screens on top of this.
 */
class Role extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'name',
        'description',
        'status',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withPivot('granted_at');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
