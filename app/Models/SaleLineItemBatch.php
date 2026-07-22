<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleLineItemBatch extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'sale_line_item_id',
        'batch_id',
        'quantity_deducted',
    ];

    protected function casts(): array
    {
        return [
            'quantity_deducted' => 'decimal:3',
        ];
    }

    public function saleLineItem(): BelongsTo
    {
        return $this->belongsTo(SaleLineItem::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
