<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleLineItem extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'sale_id',
        'product_id',
        'selling_unit_id',
        'quantity',
        'unit_price',
        'line_discount_type',
        'line_discount_amount',
        'line_discount_reason',
        'line_discount_applied_by',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'line_discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sellingUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'selling_unit_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(SaleLineItemBatch::class);
    }

    public function returnLineItems(): HasMany
    {
        return $this->hasMany(SalesReturnLineItem::class);
    }

    /**
     * How much of this line is still eligible to be returned — originally
     * sold quantity minus whatever prior returns already claimed. There's
     * no DB constraint for this (the schema doesn't track it per-batch),
     * so ReturnService checks it here before processing a new return.
     */
    public function remainingReturnableQty(): float
    {
        return (float) $this->quantity - (float) $this->returnLineItems()->sum('quantity');
    }
}
