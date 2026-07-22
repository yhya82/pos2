<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    private ?Collection $permissionCache = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'password_hash',
        'role_id',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }

    /**
     * The schema's users.password_hash column replaces Laravel's stock
     * `password` column — the auth guard reads the auth password through
     * this accessor rather than assuming a `password` column exists.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * True if this user's role has been granted the given module/action
     * permission. Backs the permission-aware sidebar; the full role and
     * permission management screens are built out in the next phase.
     *
     * The role's permissions are loaded once per request and cached on the
     * instance — the sidebar checks this for every nav item, and that
     * shouldn't mean one query per item.
     */
    public function hasPermission(string $module, string $action): bool
    {
        $this->permissionCache ??= $this->role->permissions()->get(['module', 'action']);

        return $this->permissionCache->contains(
            fn ($permission) => $permission->module === $module && $permission->action === $action
        );
    }
}
