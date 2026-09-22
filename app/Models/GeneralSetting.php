<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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

    public function logoUrl(): ?string
    {
        return $this->business_logo_url ? Storage::disk('public')->url($this->business_logo_url) : null;
    }

    /**
     * The store's own number is held to the same form as customers', users'
     * and suppliers': +220 and nine digits.
     */
    public function hasValidContactPhone(): bool
    {
        return (bool) preg_match('/^\+220\d{9}$/', (string) $this->contact_phone);
    }

    /** As printed on receipts: +220 831234567. Whatever is on file if it isn't a valid number. */
    public function contactPhoneDisplay(): ?string
    {
        if ($this->hasValidContactPhone()) {
            return substr($this->contact_phone, 0, 4).' '.substr($this->contact_phone, 4);
        }

        return $this->contact_phone ?: null;
    }
}
