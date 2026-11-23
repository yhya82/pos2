<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Append-only (DB triggers block UPDATE/DELETE on this table). Settings
 * changes log themselves via triggers already; everything else — Users,
 * Roles, and every entity after them — logs from application code, per
 * the "one rule, one place" split in the master project file: a trigger
 * only owns what a trigger can express on its own.
 */
class AuditLog extends Model
{
    const UPDATED_AT = null;

    /**
     * Severity groupings for the security module's own event types —
     * shared by Audit Logs' tone mapping (Activity and Security tabs) and
     * the Security Center's Login Activity/Threats tabs, so the exact
     * same action is never colored differently depending which page it's
     * viewed from. 'create', 'update', 'void', 'delete', 'adjustment',
     * 'receive', 'payment', 'approve' are other modules' actions and stay
     * hardcoded at each tone-match call site — these only cover the
     * module-agnostic ones security events need.
     */
    const OK_ACTIONS = ['login', 'password_changed', 'session_revoked'];

    const SERIOUS_ACTIONS = ['tamper_ignored', 'access_denied', 'account_locked'];

    const WARN_ACTIONS = ['login_failed', 'login_blocked', 'session_expired', 'login_new_device', 'password_reused_blocked'];

    /**
     * The curated, higher-severity subset shown on the Security Center's
     * Threats tab — every one of these is also in SERIOUS_ACTIONS or
     * WARN_ACTIONS above, so its tone there already matches this list's
     * severity without being redefined a second time.
     */
    const THREAT_ACTIONS = ['tamper_ignored', 'access_denied', 'account_locked', 'login_new_device', 'password_reused_blocked'];

    /**
     * "Needs a look" thresholds for a person's per-period counts — shared
     * by Audit Logs' "Who did what" tab and the Security Center's People
     * tab, so the same person isn't flagged on one page and not the
     * other. Tampering and any lockout flag at 1 (no threshold needed:
     * both are already serious on their own).
     */
    const FLAG_FAILED_LOGINS = 5;

    const FLAG_DENIED = 3;

    const FLAG_NEW_DEVICES = 3;

    const FLAG_REUSE_BLOCKED = 3;

    protected $fillable = [
        'user_id',
        'action',
        'module',
        'record_type',
        'record_id',
        'previous_value',
        'new_value',
        'ip_address',
        'device_info',
    ];

    protected function casts(): array
    {
        return [
            'previous_value' => 'array',
            'new_value' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Security events (module 'security'): logins, failed logins, lockouts,
     * logouts, expired sessions, denied requests, ignored tampering. They
     * happen before a user is signed in, or while one is being refused, so the
     * actor is passed in rather than read from auth(), and a failure to write
     * one must never break the login or page it's describing.
     */
    public static function security(string $action, ?int $userId, ?array $details = null, string $recordType = 'Session'): void
    {
        try {
            static::create([
                'user_id' => $userId,
                'action' => $action,
                'module' => 'security',
                'record_type' => $recordType,
                'record_id' => $userId,
                'previous_value' => null,
                'new_value' => $details,
                'ip_address' => request()->ip(),
                'device_info' => request()->userAgent() ? mb_substr(request()->userAgent(), 0, 255) : null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * A request refused with 403: who, what they tried, and why. Livewire
     * calls arrive as one generic URL, so the component and method are
     * pulled out of the payload to make the entry readable. Marks the request
     * so the global 403 handler doesn't log the same refusal twice.
     */
    public static function accessDenied(?string $why = null, ?string $component = null): void
    {
        $request = request();

        if ($request->attributes->get('denied_logged')) {
            return;
        }

        $request->attributes->set('denied_logged', true);

        $details = [
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'why' => $why,
        ];

        try {
            $first = $request->input('components.0');

            if (is_array($first)) {
                $snapshot = json_decode($first['snapshot'] ?? '[]', true);
                $details['component'] = $snapshot['memo']['name'] ?? $component;
                $details['call'] = $first['calls'][0]['method'] ?? null;
            } elseif ($component) {
                $details['component'] = $component;
            }
        } catch (\Throwable $e) {
            // The entry is still useful without the extra detail.
        }

        static::security('access_denied', auth()->id(), array_filter($details, fn ($v) => $v !== null), 'Request');
    }

    /**
     * A value someone submitted for something they may not set by hand (a
     * cost, a selling price, closing a line short) that the server ignored
     * and replaced. The form never offers it, so a submission means a
     * doctored request — worth an administrator's eye.
     */
    public static function tamperIgnored(string $what, array $attempted, array $applied): void
    {
        static::security('tamper_ignored', auth()->id(), ['what' => $what, 'attempted' => $attempted, 'applied' => $applied], 'User');
    }

    /**
     * The rolling-window security counts shown on both Audit Logs' alert
     * bar and the Security Center's score card/stat tiles — one
     * definition so "failed logins" etc. always means the same query in
     * both places, not two hand-rolled SUM(...) statements that can
     * quietly drift apart.
     *
     * @return array{failed_logins: int, denied: int, tampering: int, new_devices: int}
     */
    public static function securityCounts(Carbon $since): array
    {
        $counts = DB::table('audit_logs')
            ->where('module', 'security')
            ->where('created_at', '>=', $since)
            ->selectRaw("SUM(action IN ('login_failed','login_blocked')) AS failed_logins,
                SUM(action = 'access_denied') AS denied,
                SUM(action = 'tamper_ignored') AS tampering,
                SUM(action = 'login_new_device') AS new_devices")
            ->first();

        return [
            'failed_logins' => (int) ($counts->failed_logins ?? 0),
            'denied' => (int) ($counts->denied ?? 0),
            'tampering' => (int) ($counts->tampering ?? 0),
            'new_devices' => (int) ($counts->new_devices ?? 0),
        ];
    }

    public static function record(string $action, string $module, string $recordType, int $recordId, ?array $previous = null, ?array $new = null): void
    {
        static::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'module' => $module,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'previous_value' => $previous,
            'new_value' => $new,
            'ip_address' => request()->ip(),
            'device_info' => request()->userAgent(),
        ]);
    }
}
