<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per notification category (inventory/sales/customer/user_system)
 * — not a singleton like most other settings tables.
 */
class NotificationSetting extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'category',
        'is_enabled',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }
}
