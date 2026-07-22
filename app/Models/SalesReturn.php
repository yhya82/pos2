<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesReturn extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'return_number',
        'original_sale_id',
        'processed_by',
        'reason',
        'refund_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'refund_amount' => 'decimal:2',
        ];
    }

    public function originalSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'original_sale_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(SalesReturnLineItem::class, 'return_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(ReturnReceipt::class, 'return_id');
    }

    public static function generateReturnNumber(): string
    {
        $today = now()->format('Ymd');
        $countToday = static::whereDate('created_at', now()->toDateString())->count() + 1;

        return "RET-{$today}-".str_pad((string) $countToday, 4, '0', STR_PAD_LEFT);
    }
}
