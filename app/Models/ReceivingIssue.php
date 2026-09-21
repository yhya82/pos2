<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Goods that arrived damaged or never arrived against a purchase order —
 * the record of the loss (valued at cost) and the claim against the supplier
 * for it, which stays "owed" until it is credited or waived.
 */
class ReceivingIssue extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'purchase_order_id',
        'purchase_order_line_item_id',
        'product_id',
        'supplier_id',
        'issue_type',
        'qty',
        'unit_cost',
        'loss_value',
        'reason',
        'claim_status',
        'credited_amount',
        'resolution_note',
        'resolved_by',
        'resolved_at',
        'reported_by',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'loss_value' => 'decimal:2',
            'credited_amount' => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLineItem::class, 'purchase_order_line_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** What the supplier still owes for this issue. */
    public function outstanding(): float
    {
        return $this->claim_status === 'owed' ? (float) $this->loss_value : 0.0;
    }
}
