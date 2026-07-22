<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton settings row (id is always 1, enforced by the schema's own
 * CHECK constraint). Only the fields the layout shell needs right now are
 * used here — Phase 10 builds the full settings-editing screens.
 */
class GeneralSetting extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'business_name',
        'business_logo_url',
        'contact_phone',
        'contact_email',
        'address',
        'currency_code',
        'date_format',
        'time_format',
        'tax_enabled',
        'tax_rate',
        'updated_by',
    ];

    public static function current(): ?self
    {
        return static::find(1);
    }
}
