<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'notes',
        'status',
    ];

    /**
     * The one accepted form of a supplier's number: +220 and nine digits, the
     * same as customers and users. Anything else on file (an old 7-digit
     * number, blank) is flagged for updating rather than silently trusted.
     */
    public function hasValidPhone(): bool
    {
        return (bool) preg_match('/^\+220\d{9}$/', (string) $this->phone);
    }

    /** Display-only: +220 123456789. Null when there's no valid number to format. */
    public function formattedPhone(): ?string
    {
        return $this->hasValidPhone() ? substr($this->phone, 0, 4).' '.substr($this->phone, 4) : null;
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
