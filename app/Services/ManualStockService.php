<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a batch with no purchase order behind it — batches.
 * purchase_order_line_item_id is nullable specifically "for manual/opening
 * stock" per the schema's own column comment, but until now nothing
 * actually wrote one. PurchaseReceivingService is explicitly scoped to PO
 * receiving ("the only writer of batches from receiving"), so this is a
 * separate service rather than bolting a PO-less path onto that one.
 */
class ManualStockService
{
    /**
     * @param  string  $quantityUnit  'purchase' or 'selling' — whichever unit
     *                                $quantity and $unitCost were entered in.
     *                                Converted to the batch's always-selling-
     *                                unit terms via Product::toSellingQty()/
     *                                toSellingUnitCost() before writing.
     */
    public function receive(
        Product $product,
        float $quantity,
        string $quantityUnit,
        float $unitCost,
        string $receivedDate,
        ?string $expiryDate,
        ?string $batchCode,
        ?string $reason,
        User $user,
    ): Batch {
        return DB::transaction(function () use ($product, $quantity, $quantityUnit, $unitCost, $receivedDate, $expiryDate, $batchCode, $reason, $user) {
            $sellingQty = $product->toSellingQty($quantity, $quantityUnit);
            $sellingUnitCost = $product->toSellingUnitCost($unitCost, $quantityUnit);

            $batch = Batch::create([
                'product_id' => $product->id,
                'purchase_order_line_item_id' => null,
                'batch_code' => $batchCode,
                'qty_received' => $sellingQty,
                'qty_remaining' => $sellingQty,
                'unit_cost' => $sellingUnitCost,
                'expiry_date' => $expiryDate,
                'received_date' => $receivedDate,
                'status' => 'active',
            ]);

            InventoryMovement::create([
                'product_id' => $product->id,
                'batch_id' => $batch->id,
                'movement_type' => 'stock_received',
                'quantity' => $sellingQty,
                'previous_qty' => 0,
                'new_qty' => $sellingQty,
                'reference_table' => null,
                'reference_id' => null,
                'reason' => $reason ?: 'Manual stock entry',
                'user_id' => $user->id,
            ]);

            AuditLog::record('create', 'inventory', 'Batch', $batch->id, null, $batch->only([
                'product_id', 'batch_code', 'qty_received', 'unit_cost', 'expiry_date', 'received_date', 'status',
            ]));

            // Same reference-cost sync as PurchaseReceivingService —
            // products.cost_price is always per selling unit, so this
            // reuses $sellingUnitCost (already computed above for the
            // batch itself) regardless of which unit this entry was made in.
            if ($sellingUnitCost !== (float) $product->cost_price) {
                $previousCost = $product->only(['cost_price']);
                $product->update(['cost_price' => $sellingUnitCost]);

                AuditLog::record('update', 'products', 'Product', $product->id, $previousCost, $product->only(['cost_price']));
            }

            return $batch;
        });
    }
}
