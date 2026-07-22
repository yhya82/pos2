<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A frozen JSON snapshot taken at sale time — deliberately decoupled from
 * live product/price/business-settings data so a reprint months later
 * shows exactly what the customer originally saw, even if prices or the
 * business's own settings have since changed.
 */
class Receipt extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'sale_id',
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

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
