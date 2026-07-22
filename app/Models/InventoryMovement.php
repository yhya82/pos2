<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (DB triggers block UPDATE/DELETE — trg_inventory_movements_*).
 * Every stock-changing event gets one row here; Phase 03 is only the
 * 'stock_received' writer, the rest (sale, return, damaged, expired,
 * adjustment) come from later phases.
 */
class InventoryMovement extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'batch_id',
        'movement_type',
        'quantity',
        'previous_qty',
        'new_qty',
        'reference_table',
        'reference_id',
        'reason',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'previous_qty' => 'decimal:3',
            'new_qty' => 'decimal:3',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
