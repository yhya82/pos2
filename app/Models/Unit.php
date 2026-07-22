<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function productsAsPurchaseUnit(): HasMany
    {
        return $this->hasMany(Product::class, 'purchase_unit_id');
    }

    public function productsAsSellingUnit(): HasMany
    {
        return $this->hasMany(Product::class, 'selling_unit_id');
    }
}
