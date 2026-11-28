<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

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
        'theme',
    ];

    /**
     * True once this account has racked up enough failed attempts to trip
     * the configured lockout and that lockout hasn't expired yet. Shared by
     * the login screen and the "confirm password" step-up screen — both are
     * password-guessing surfaces, so both must count failures against, and
     * be refused by, the same account-level lockout.
     */
    public function isLockedOut(): bool
    {
        return (bool) ($this->locked_until && $this->locked_until->isFuture());
    }

    public function lockoutMinutesRemaining(): int
    {
        return (int) ceil(now()->diffInMinutes($this->locked_until, true));
    }

    /**
     * @return array{attempts: int, locked: bool} so the caller can word its
     *         own response differently the instant an account actually
     *         locks, versus a failed attempt that still has some left.
     *
     * An atomic increment()/decrement() instead of read-then-save — two
     * concurrent wrong-password requests against the same account doing a
     * plain read-modify-write could both read the same starting count and
     * under-count the pair of failures right at the lockout boundary.
     */
    public function registerFailedLoginAttempt(): array
    {
        $security = SecuritySetting::current();

        $this->increment('failed_login_attempts');
        $attempts = $this->failed_login_attempts;

        $locks = $attempts >= $security->max_failed_login_attempts;

        if ($locks) {
            $this->forceFill(['locked_until' => now()->addMinutes($security->lockout_duration_minutes)])->save();

            AuditLog::security('account_locked', $this->id, ['email' => $this->email, 'failed_attempts' => $attempts, 'locked_minutes' => $security->lockout_duration_minutes], 'User');
        }

        return ['attempts' => $attempts, 'locked' => $locks];
    }

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
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }

    /**
     * The one place a password is ever actually set — self-service,
     * admin reset, forgot-password, or the forced change after it
     * expires all funnel through here, so history recording, the
     * "password_changed" security log, and the force-logout-other-
     * sessions rule can't be applied in some paths and missed in others.
     *
     * password_changed_at isn't mass-assignable (same reasoning as
     * failed_login_attempts/locked_until: a system-managed security
     * column, not something a form should ever set directly), so this
     * uses forceFill rather than update() like the rest of the model.
     */
    public function setPassword(string $plain, ?string $exceptSessionId = null): void
    {
        $this->forceFill([
            'password_hash' => $plain,
            'password_changed_at' => now(),
        ])->save();

        PasswordHistory::create(['user_id' => $this->id, 'password_hash' => $this->password_hash]);

        // Must keep at least as many rows as security_settings.password_history_count
        // can ever ask passwordWasUsedRecently() to check (admins can configure up to
        // 50) — a hardcoded cap below that would silently let a password reuse slip
        // through unchecked past whatever this number is, with no error anywhere.
        $keep = max(SecuritySetting::current()->password_history_count, 20);

        PasswordHistory::where('user_id', $this->id)
            ->whereNotIn('id', PasswordHistory::where('user_id', $this->id)->latest('id')->limit($keep)->pluck('id'))
            ->delete();

        LoginSession::revokeAllFor($this->id, $exceptSessionId);

        AuditLog::security('password_changed', $this->id, ['email' => $this->email], 'User');
    }

    /**
     * security_settings.password_history_count worth of this user's most
     * recent passwords, checked against a candidate new one. 0 (or the
     * column being otherwise falsy) means the rule is off.
     */
    public function passwordWasUsedRecently(string $plain): bool
    {
        $limit = SecuritySetting::current()->password_history_count;

        if ($limit <= 0) {
            return false;
        }

        return PasswordHistory::where('user_id', $this->id)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->contains(function ($row) use ($plain) {
                try {
                    return Hash::check($plain, $row->password_hash);
                } catch (\RuntimeException $e) {
                    // Hash::check() throws rather than returning false for a
                    // string that isn't a recognized hash at all — real for
                    // the schema's own placeholder history row seeded for a
                    // brand-new install ('INVALID-PLACEHOLDER-...', deliberately
                    // unusable so nobody can log in with a known default).
                    // Unreadable can't possibly match a real plaintext, so
                    // this is "not a match", not an error.
                    return false;
                }
            });
    }

    /**
     * security_settings.password_expiry_days measured from the last time
     * this password was actually changed. 0 means expiry is off; a NULL
     * password_changed_at (shouldn't happen post-migration backfill, but
     * cheap to guard) is treated as "not expired" rather than crashing.
     */
    public function passwordHasExpired(): bool
    {
        $days = SecuritySetting::current()->password_expiry_days;

        if ($days <= 0 || ! $this->password_changed_at) {
            return false;
        }

        return $this->password_changed_at->copy()->addDays($days)->isPast();
    }

    /**
     * Length and required character classes are rules about the plain
     * password itself, which a stored hash can't be re-checked against —
     * the one moment this account's actual password is available again is
     * login, when the plaintext just came in on the form. A password set
     * back when the policy was looser (e.g. an admin later raises the
     * minimum length) only surfaces here, at the next successful login;
     * LoginForm uses this to force a change immediately rather than
     * silently grandfathering it in. Reuse history is deliberately not
     * checked here — the current password is always its own most recent
     * history entry, so it would always "match itself."
     */
    public function passwordMeetsCurrentPolicy(string $plain): bool
    {
        $security = SecuritySetting::current();

        if (mb_strlen($plain) < $security->password_min_length) {
            return false;
        }

        if ($security->password_require_uppercase && ! preg_match('/[A-Z]/', $plain)) {
            return false;
        }

        if ($security->password_require_lowercase && ! preg_match('/[a-z]/', $plain)) {
            return false;
        }

        if ($security->password_require_number && ! preg_match('/[0-9]/', $plain)) {
            return false;
        }

        if ($security->password_require_symbol && ! preg_match('/[^A-Za-z0-9]/', $plain)) {
            return false;
        }

        return true;
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
     * Display-only — phone is always stored as a contiguous +220 + 9 digits
     * (no space), so this doesn't touch what's actually persisted/validated.
     */
    public function formattedPhone(): ?string
    {
        return $this->phone ? substr($this->phone, 0, 4).' '.substr($this->phone, 4) : null;
    }

    /**
     * Store-wide revenue figures and other cashiers' sales are restricted
     * by role, not by the module/action permission grid — there's no
     * "own records only" concept in the permission schema, so this is a
     * direct role-name check rather than a permission lookup.
     */
    public function isCashier(): bool
    {
        return $this->role?->name === 'Cashier';
    }

    /**
     * Where a login (or a forced/confirmed password screen with no
     * "intended" URL to return to) should land. A default Cashier still
     * has enough dashboard-card permissions (inventory/customers view) to
     * not hit the "no visibility" fallback there, so this goes by the
     * same role check as the rest of the cashier-scoping above rather than
     * a dashboard-visibility computation — a cashier's job is the register,
     * not store analytics. Guarded by the real permission too, in case a
     * "Cashier"-named role has had sales,create taken away from it on the
     * Roles screen.
     */
    public function defaultLandingRoute(): string
    {
        return $this->isCashier() && $this->hasPermission('sales', 'create')
            ? route('pos.index', absolute: false)
            : route('dashboard', absolute: false);
    }

    /**
     * Costs, profit, and store-wide takings are the shop's finances, not
     * everyday operations. A real permission (not a role-name check) so it
     * can be granted or withheld from the Roles screen like anything else —
     * every screen and report that hides these figures reads this one
     * method, so they can't disagree with each other.
     */
    public function canSeeFinancials(): bool
    {
        return $this->hasPermission('financials', 'view');
    }

    /**
     * The Administrator role sees and can do everything in the system —
     * including what no permission grid entry is allowed to hand to anyone
     * else (the audit log, refunding other people's sales).
     */
    public function isAdministrator(): bool
    {
        return $this->role?->name === 'Administrator';
    }

    /**
     * True only for an active Administrator who is the last one — the
     * store would otherwise be left with nobody able to manage users,
     * roles, or settings. Used to block deactivating or re-roling this
     * one account; an Administrator who is already inactive, or isn't
     * the only one, is unaffected.
     */
    public function isOnlyActiveAdministrator(): bool
    {
        if (! $this->isAdministrator() || $this->status !== 'active') {
            return false;
        }

        return static::whereHas('role', fn ($q) => $q->where('name', 'Administrator'))
            ->where('status', 'active')
            ->count() === 1;
    }

    /**
     * Refunding a sale someone else made needs the returns.update
     * permission; refunding one's own sale only ever needed the base
     * returns.create right to process a return at all (checked separately,
     * where the return is submitted) — this only widens *which* sales are
     * eligible, it doesn't grant the ability to process one by itself.
     */
    public function canRefundSale(Sale $sale): bool
    {
        return $this->hasPermission('returns', 'update') || (int) $sale->cashier_id === (int) $this->id;
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
