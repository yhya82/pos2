<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    /**
     * Without sales,view_all, a user only ever sees sales they themselves
     * made — with it, whatever its permissions otherwise let it open.
     * Apply to every query a user can browse.
     */
    public function scopeVisibleTo($query, User $user)
    {
        return $user->hasPermission('sales', 'view_all') ? $query : $query->where('cashier_id', $user->id);
    }

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

    /**
     * Every return line ever processed against this sale's own line items
     * — used for the "X of Y items returned" summary on Sales History,
     * via withSum() against this and lineItems() together rather than a
     * per-row query.
     */
    public function returnedLineItems(): HasManyThrough
    {
        return $this->hasManyThrough(SalesReturnLineItem::class, SaleLineItem::class, 'sale_id', 'sale_line_item_id');
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
     * Every return processed against this sale — used to total up how
     * much has been refunded so far, shown on the sale's own receipt
     * alongside the frozen original total.
     */
    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'original_sale_id');
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
