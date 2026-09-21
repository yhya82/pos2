<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
