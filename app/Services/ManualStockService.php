<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
        ?float $sellingPrice = null,
    ): Batch {
        \App\Support\Whole::assert($quantity, 'The quantity received', 1);

        if ($sellingPrice !== null && $sellingPrice <= 0) {
            throw new RuntimeException('The selling price must be above 0.');
        }

        return DB::transaction(function () use ($product, $quantity, $quantityUnit, $unitCost, $receivedDate, $expiryDate, $batchCode, $reason, $user, $sellingPrice) {
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
            //
            // The price this delivery is sold at — the product's current one
            // unless the caller changed it because the new stock warrants it.
            $price = $sellingPrice ?? (float) $product->selling_price;
            $priceChanged = abs($price - (float) $product->selling_price) >= 0.005;

            $updates = [];

            if ($priceChanged) {
                $updates['selling_price'] = $price;
            }

            // Skipped when the cost is not below the selling price (a DB rule
            // forbids the product holding that) — the batch keeps its real
            // cost and the caller surfaces $product->costAbovePriceWarning().
            if ($sellingUnitCost < $price && $sellingUnitCost !== (float) $product->cost_price) {
                $updates['cost_price'] = $sellingUnitCost;
            }

            if ($updates) {
                $finalCost = (float) ($updates['cost_price'] ?? $product->cost_price);

                if ($finalCost >= $price) {
                    throw new RuntimeException("The selling price must be above the product's cost price of ".number_format($finalCost, 2).'. Change the cost price first.');
                }

                $previous = $product->only(array_keys($updates));
                // One update, so a price drop and a cost drop can't trip the
                // cost-below-price rule halfway through.
                $product->update($updates);

                AuditLog::record('update', 'products', 'Product', $product->id, $previous, $product->only(array_keys($updates)));
            }

            if ($priceChanged && $product->promo_discount_type !== 'none') {
                $promoPrice = Product::priceAfterDiscount($price, $product->promo_discount_type, (float) $product->promo_discount_value);
                $floor = $product->breakEvenCost();

                if ($promoPrice < $floor) {
                    throw new RuntimeException("With this product's current promotion the price would be ".number_format($promoPrice, 2).', below its cost of '.number_format($floor, 2).'. Change the promotion first.');
                }
            }

            return $batch;
        });
    }
}
