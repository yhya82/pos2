<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'receipt_number',
        'sale_date',
        'cashier_id',
        'customer_id',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'discount_reason',
        'discount_applied_by',
        'tax_amount',
        'total_amount',
        'status',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(SaleLineItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    /**
     * PO-style generated identifier: SALE-{YYYYMMDD}-{0001}. Same
     * not-concurrency-proof caveat as PurchaseOrder::generatePoNumber() —
     * the real guarantee is the column's UNIQUE constraint.
     */
    public static function generateReceiptNumber(): string
    {
        $today = now()->format('Ymd');
        $countToday = static::whereDate('created_at', now()->toDateString())->count() + 1;

        return "SALE-{$today}-".str_pad((string) $countToday, 4, '0', STR_PAD_LEFT);
    }
}
