<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_number',
        'supplier_id',
        'status',
        'order_date',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderLineItem::class);
    }

    /**
     * PO-{YYYYMMDD}-{0001}. Not concurrency-proof (two POs created in the
     * same request-second could in theory race for the same suffix), but
     * po_number has a real UNIQUE constraint underneath, so a collision
     * surfaces as a clear DB error rather than silent duplicate numbering.
     */
    public static function generatePoNumber(): string
    {
        $today = now()->format('Ymd');
        $countToday = static::whereDate('created_at', now()->toDateString())->count() + 1;

        return "PO-{$today}-".str_pad((string) $countToday, 4, '0', STR_PAD_LEFT);
    }
}
