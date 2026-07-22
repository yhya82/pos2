<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of batches.qty_remaining from a manual stock adjustment
 * (Sec. 20.11 "stock adjustments"). Receiving (Phase 03) and sales/returns
 * (later phases) each own their own quantity changes through their own
 * service — this one is specifically for corrections, damage, and expiry
 * write-offs a person enters by hand.
 */
class InventoryAdjustmentService
{
    public const TYPE_CORRECTION_ADD = 'correction_add';

    public const TYPE_CORRECTION_REMOVE = 'correction_remove';

    public const TYPE_DAMAGED = 'damaged';

    public const TYPE_EXPIRED = 'expired';

    /**
     * @throws RuntimeException if the adjustment would take the batch below zero
     */
    public function adjust(Batch $batch, string $adjustmentType, float $quantity, string $reason, User $user): void
    {
        DB::transaction(function () use ($batch, $adjustmentType, $quantity, $reason, $user) {
            $batch = Batch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            $delta = $adjustmentType === self::TYPE_CORRECTION_ADD ? $quantity : -$quantity;
            $movementType = $adjustmentType === self::TYPE_CORRECTION_ADD || $adjustmentType === self::TYPE_CORRECTION_REMOVE
                ? 'adjustment'
                : $adjustmentType;

            $previousQty = (float) $batch->qty_remaining;
            $newQty = $previousQty + $delta;

            if ($newQty < 0) {
                throw new RuntimeException(
                    "This would take the batch below zero — it only has {$previousQty} remaining."
                );
            }

            $batch->qty_remaining = $newQty;
            $batch->save();

            InventoryMovement::create([
                'product_id' => $batch->product_id,
                'batch_id' => $batch->id,
                'movement_type' => $movementType,
                'quantity' => $delta,
                'previous_qty' => $previousQty,
                'new_qty' => $newQty,
                'reference_table' => null,
                'reference_id' => null,
                'reason' => $reason,
                'user_id' => $user->id,
            ]);

            AuditLog::record(
                'adjustment',
                'inventory',
                'Batch',
                $batch->id,
                ['qty_remaining' => $previousQty],
                ['qty_remaining' => $newQty],
            );
        });
    }
}
