<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLineItem extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'qty_ordered',
        'qty_received',
        'qty_damaged',
        'qty_closed_short',
        'purchase_unit_id',
        'cost_price',
    ];

    protected function casts(): array
    {
        return [
            'qty_ordered' => 'decimal:3',
            'qty_received' => 'decimal:6',
            'qty_damaged' => 'decimal:6',
            'qty_closed_short' => 'decimal:6',
            'cost_price' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'purchase_unit_id');
    }

    public function remainingQty(): float
    {
        // Damaged units did arrive and closed-short units will never arrive:
        // neither is still expected, so neither counts as remaining.
        // Six decimals: a few pieces out of a packet is a repeating fraction,
        // and float subtraction must not leave a 0.0000000001 "remaining".
        return max(0.0, round((float) $this->qty_ordered - (float) $this->qty_received - (float) $this->qty_damaged - (float) $this->qty_closed_short, 6));
    }
}
