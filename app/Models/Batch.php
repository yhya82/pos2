<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Minimal shell so Product::batches() (used by the product list's stock/
 * expiry columns) has something to relate to — batch receiving, FEFO
 * deduction, and the rest of Sec. 4's design are Phase 04's work, not this
 * one.
 */
class Batch extends Model
{
    use HasFactory;

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

    /**
     * A prefilled, still-editable suggestion for the "Batch Code" field —
     * batch_code itself is free text with no uniqueness constraint
     * (purely a human label; nothing in the system keys off it), so this
     * is cosmetic, not a real identifier. Letters-only prefix (punctuation
     * and digits in the product name are dropped) plus the product's own
     * id keeps two different products from ever suggesting the same
     * prefix, and the running count is this product's own batch number —
     * "BEL9-1", "BEL9-2", ... for product #9 ("Bel Aqua").
     */
    public static function suggestCodeFor(Product $product): string
    {
        $letters = preg_replace('/[^A-Za-z]/', '', $product->name);
        $prefix = Str::upper(Str::substr($letters, 0, 3)) ?: 'BAT';

        $sequence = static::where('product_id', $product->id)->count() + 1;

        return "{$prefix}{$product->id}-{$sequence}";
    }
}
