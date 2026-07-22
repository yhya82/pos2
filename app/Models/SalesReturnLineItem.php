<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnLineItem extends Model
{
    public $timestamps = false;

    protected $table = 'sales_return_line_items';

    protected $fillable = [
        'return_id',
        'sale_line_item_id',
        'product_id',
        'quantity',
        'condition_type',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'return_id');
    }

    public function saleLineItem(): BelongsTo
    {
        return $this->belongsTo(SaleLineItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
