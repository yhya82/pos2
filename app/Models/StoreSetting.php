<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreSetting extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'opening_time',
        'closing_time',
        'receipt_business_info',
        'updated_by',
    ];

    public static function current(): ?self
    {
        return static::find(1);
    }
}
