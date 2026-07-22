<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only mapping onto the v_batch_expiry view (Part E, Section 14) —
 * active batches with a non-null expiry date, soonest first. The view
 * itself already excludes depleted/expired/written-off batches.
 */
class BatchExpiry extends Model
{
    protected $table = 'v_batch_expiry';

    protected $primaryKey = 'batch_id';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'qty_remaining' => 'decimal:3',
            'days_to_expiry' => 'integer',
        ];
    }
}
