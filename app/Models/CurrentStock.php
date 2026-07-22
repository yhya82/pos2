<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only mapping onto the v_current_stock view (Part E, Section 14) —
 * current stock on hand per product, derived from active batches. Never
 * written to from the app; the view itself is the single source of truth
 * for "what does this product have on hand right now."
 */
class CurrentStock extends Model
{
    protected $table = 'v_current_stock';

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty_on_hand' => 'decimal:3',
            'min_stock_level' => 'decimal:3',
            'is_low_stock' => 'boolean',
        ];
    }
}
