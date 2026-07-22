<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns the one multi-step transaction the master project file calls out
 * explicitly: receiving a PO line item creates a Batch, advances
 * qty_received, and logs the compensating inventory_movements row, all or
 * nothing. This is the only writer of `batches` from receiving — nothing
 * else should insert a batch row outside this service.
 */
class PurchaseReceivingService
{
    /**
     * @param  array<int, array{line_item_id: int, qty: float|string, batch_code?: ?string, expiry_date?: ?string, received_date?: ?string}>  $receipts
     *
     * @throws RuntimeException if any line would receive more than it has remaining
     */
    public function receive(PurchaseOrder $purchaseOrder, array $receipts, User $receivedBy): void
    {
        DB::transaction(function () use ($purchaseOrder, $receipts, $receivedBy) {
            foreach ($receipts as $receipt) {
                $qty = (float) ($receipt['qty'] ?? 0);

                if ($qty <= 0) {
                    continue;
                }

                $lineItem = $purchaseOrder->lineItems()
                    ->whereKey($receipt['line_item_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $remaining = $lineItem->remainingQty();

                if ($qty > $remaining) {
                    throw new RuntimeException(
                        "Can't receive {$qty} for \"{$lineItem->product->name}\" — only {$remaining} remaining on this order."
                    );
                }

                $batch = Batch::create([
                    'product_id' => $lineItem->product_id,
                    'purchase_order_line_item_id' => $lineItem->id,
                    'batch_code' => $receipt['batch_code'] ?? null ?: null,
                    'qty_received' => $qty,
                    'qty_remaining' => $qty,
                    'unit_cost' => $lineItem->cost_price,
                    'expiry_date' => $receipt['expiry_date'] ?? null ?: null,
                    'received_date' => $receipt['received_date'] ?? null ?: now()->toDateString(),
                    'status' => 'active',
                ]);

                $lineItem->increment('qty_received', $qty);

                InventoryMovement::create([
                    'product_id' => $lineItem->product_id,
                    'batch_id' => $batch->id,
                    'movement_type' => 'stock_received',
                    'quantity' => $qty,
                    'previous_qty' => 0,
                    'new_qty' => $qty,
                    'reference_table' => 'purchase_orders',
                    'reference_id' => $purchaseOrder->id,
                    'reason' => "Received against {$purchaseOrder->po_number}",
                    'user_id' => $receivedBy->id,
                ]);
            }

            $purchaseOrder->load('lineItems');

            $totalReceived = $purchaseOrder->lineItems->sum('qty_received');
            $allReceived = $purchaseOrder->lineItems->every(fn ($line) => $line->qty_received >= $line->qty_ordered);

            $previousStatus = $purchaseOrder->status;
            $purchaseOrder->status = $allReceived ? 'received' : ($totalReceived > 0 ? 'partially_received' : $previousStatus);
            $purchaseOrder->save();

            AuditLog::record(
                'receive',
                'purchase_orders',
                'PurchaseOrder',
                $purchaseOrder->id,
                ['status' => $previousStatus],
                ['status' => $purchaseOrder->status],
            );
        });
    }
}
