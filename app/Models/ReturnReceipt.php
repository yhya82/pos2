<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frozen JSON snapshot for a return, same reasoning as Receipt (Sec. 11.1
 * "Generating return receipts") — decoupled from live sale/product data so
 * a reprint later shows exactly what was refunded at the time.
 */
class ReturnReceipt extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'return_id',
        'receipt_number',
        'business_snapshot',
        'line_items_snapshot',
        'totals_snapshot',
        'print_status',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'business_snapshot' => 'array',
            'line_items_snapshot' => 'array',
            'totals_snapshot' => 'array',
            'printed_at' => 'datetime',
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'return_id');
    }
}
