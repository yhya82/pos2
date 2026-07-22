<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minimal shell so Product::batches() (used by the product list's stock/
 * expiry columns) has something to relate to — batch receiving, FEFO
 * deduction, and the rest of Sec. 4's design are Phase 04's work, not this
 * one.
 */
class Batch extends Model
{
    protected $fillable = [
        'product_id',
        'purchase_order_line_item_id',
        'batch_code',
        'qty_received',
        'qty_remaining',
        'unit_cost',
        'expiry_date',
        'received_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'received_date' => 'date',
            'qty_received' => 'decimal:3',
            'qty_remaining' => 'decimal:3',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
