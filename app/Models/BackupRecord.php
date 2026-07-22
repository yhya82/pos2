<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only from this UI — actually running a backup is a server/ops
 * concern (mysqldump, off-host storage) handled by the scripts from Part F
 * of the master project file, not something this web app should shell_exec
 * on a request. This model just surfaces the history those scripts leave
 * behind, and the Settings > Backup & Restore section explains the split.
 */
class BackupRecord extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'scope',
        'status',
        'file_reference',
        'created_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
