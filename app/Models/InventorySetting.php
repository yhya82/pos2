<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventorySetting extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'low_stock_default_threshold',
        'expiry_alert_days_1',
        'expiry_alert_days_2',
        'expiry_alert_days_3',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'low_stock_default_threshold' => 'decimal:3',
        ];
    }

    public static function current(): self
    {
        return static::find(1);
    }
}
