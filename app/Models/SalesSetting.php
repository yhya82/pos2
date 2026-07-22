<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesSetting extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'default_payment_method_id',
        'max_discount_percentage',
        'allow_negative_stock_sale',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'max_discount_percentage' => 'decimal:2',
            'allow_negative_stock_sale' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::find(1);
    }
}
