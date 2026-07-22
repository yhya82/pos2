<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
