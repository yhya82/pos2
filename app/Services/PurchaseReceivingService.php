<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReceivingIssue;
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
     * @param  array<int, array{line_item_id: int, qty: float|string, batch_code?: ?string, expiry_date?: ?string, received_date?: ?string, unit_cost?: float|string|null, selling_price?: float|string|null}>  $receipts
     *         unit_cost is per purchase unit and overrides the order line's cost when the delivery was invoiced differently;
     *         selling_price is per selling unit and, when it differs, becomes the product's price.
     *         qty / damaged_qty are in the purchase unit by default; qty_unit / damaged_unit = 'selling' gives them
     *         in selling units (pieces) instead, so "2 pieces out of a packet" is exact rather than 0.167 of one.
     *         damaged_qty (with damaged_reason) is units that arrived unsellable: they use up the ordered quantity
     *         but never become stock, and are recorded as a loss and a claim against the supplier.
     *         close_short (with short_reason) closes whatever is still outstanding on the line as missing — it will
     *         never arrive — so the order can finish; recorded the same way. Whether the caller may close short is
     *         the caller's decision.
     *
     * @return array<int, string> Things worth the receiver's attention (e.g. a cost now above the selling price) — never a reason to have refused the delivery.
     *
     * @throws RuntimeException if any line would receive more than it has remaining
     */
    public function receive(PurchaseOrder $purchaseOrder, array $receipts, User $receivedBy): array
    {
        $warnings = [];

        DB::transaction(function () use ($purchaseOrder, $receipts, $receivedBy, &$warnings) {
            foreach ($receipts as $receipt) {
                $qty = (float) ($receipt['qty'] ?? 0);
                $damaged = (float) ($receipt['damaged_qty'] ?? 0);
                $closeShort = ! empty($receipt['close_short']);
                $qtyUnit = ($receipt['qty_unit'] ?? 'purchase') === 'selling' ? 'selling' : 'purchase';
                $damagedUnit = ($receipt['damaged_unit'] ?? 'purchase') === 'selling' ? 'selling' : 'purchase';

                if ($qty < 0 || $damaged < 0) {
                    throw new RuntimeException('Quantities can\'t be negative.');
                }

                // Counted in whole units, in whichever unit each was entered (a packet, a piece).
                \App\Support\Whole::assert($qty, 'The quantity received');
                \App\Support\Whole::assert($damaged, 'The damaged quantity');

                if ($qty <= 0 && $damaged <= 0 && ! $closeShort) {
                    continue;
                }

                $lineItem = $purchaseOrder->lineItems()
                    ->whereKey($receipt['line_item_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                // Everything is checked in selling units (pieces) — the exact
                // figure — so a fraction of a packet can't sneak past the order.
                $product = $lineItem->product;
                $conv = (float) $product->conversion_qty > 0 ? (float) $product->conversion_qty : 1.0;
                $goodPieces = round($product->toSellingQty($qty, $qtyUnit), 3);
                $badPieces = round($product->toSellingQty($damaged, $damagedUnit), 3);
                $remainingPk = $lineItem->remainingQty();
                $remainingPieces = round($remainingPk * $conv, 3);

                if ($goodPieces + $badPieces > $remainingPieces + 0.0005) {
                    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3), '0'), '.') ?: '0';

                    // One unit both ways: no need to say the same number twice.
                    $remainingText = $product->purchase_unit_id === $product->selling_unit_id
                        ? $fmt($remainingPk)
                        : sprintf('%s %s (%s %s)', $fmt($remainingPieces), $product->sellingUnit->name, $fmt($remainingPk), $product->purchaseUnit->name);

                    $already = round((float) $lineItem->qty_ordered - $remainingPk, 6);

                    throw new RuntimeException(sprintf(
                        'That\'s more than was ordered for "%s": you entered %s%s, but only %s remaining on this order%s. Please lower the quantity.',
                        $product->name,
                        $fmt($goodPieces + $badPieces) . ($product->purchase_unit_id === $product->selling_unit_id ? '' : ' '.$product->sellingUnit->name),
                        $badPieces > 0 ? sprintf(' (%s good + %s damaged)', $fmt($goodPieces), $fmt($badPieces)) : '',
                        $remainingText,
                        $already > 0.0000005
                            ? sprintf(' (%s %s ordered, %s already received)', $fmt($lineItem->qty_ordered), $product->purchaseUnit->name, $fmt($already))
                            : '',
                    ));
                }

                // The line is tracked in purchase units, to six decimals.
                $goodPk = min($remainingPk, round($goodPieces / $conv, 6));
                $badPk = min(round($remainingPk - $goodPk, 6), round($badPieces / $conv, 6));

                if ($damaged > 0 && trim((string) ($receipt['damaged_reason'] ?? '')) === '') {
                    throw new RuntimeException("Say what was wrong with the damaged \"{$lineItem->product->name}\" — it goes on the supplier claim.");
                }

                if ($closeShort && trim((string) ($receipt['short_reason'] ?? '')) === '') {
                    throw new RuntimeException("Say why the rest of \"{$lineItem->product->name}\" won't arrive — it goes on the supplier claim.");
                }

                // qty_ordered/qty_received on the line item stay in the line's
                // purchase unit throughout the PO's life (that's what
                // remainingQty() compares against) — only the Batch actually
                // written gets converted to the always-selling-unit terms
                // everything downstream (POS, Adjust Stock, min_stock_level)
                // expects.
                // What was actually paid, when the receiver says it differs from the order.
                $paidPerPurchaseUnit = isset($receipt['unit_cost']) && $receipt['unit_cost'] !== ''
                    ? (float) $receipt['unit_cost']
                    : (float) $lineItem->cost_price;
                $newPrice = isset($receipt['selling_price']) && $receipt['selling_price'] !== '' ? (float) $receipt['selling_price'] : null;

                if ($paidPerPurchaseUnit < 0 || ($newPrice !== null && $newPrice <= 0)) {
                    throw new RuntimeException("\"{$product->name}\": the cost can't be negative and the selling price must be above 0.");
                }

                if ($qty > 0) {
                $sellingQty = $goodPieces;
                $sellingUnitCost = $product->toSellingUnitCost($paidPerPurchaseUnit, 'purchase');

                $batch = Batch::create([
                    'product_id' => $lineItem->product_id,
                    'purchase_order_line_item_id' => $lineItem->id,
                    'batch_code' => $receipt['batch_code'] ?? null ?: null,
                    'qty_received' => $sellingQty,
                    'qty_remaining' => $sellingQty,
                    'unit_cost' => $sellingUnitCost,
                    'expiry_date' => $receipt['expiry_date'] ?? null ?: null,
                    'received_date' => $receipt['received_date'] ?? null ?: now()->toDateString(),
                    'status' => 'active',
                ]);

                $lineItem->increment('qty_received', $goodPk);

                // Keep the product's reference cost current — it's meant to
                // reflect the last price actually paid, but nothing wrote it
                // on receiving until now, so it went stale after any price
                // change. products.cost_price is always per selling unit,
                // so this reuses the same $sellingUnitCost already computed
                // above for the batch itself, not the line's raw
                // purchase-unit cost.
                //
                // A product's cost can't exceed its selling price (DB check),
                // and the delivery can't be refused — so when the new cost
                // is above the price, the batch keeps its real cost, the
                // product's reference cost is left alone, and a warning goes
                // back to the receiver.
                $price = $newPrice ?? (float) $product->selling_price;
                $priceChanged = abs($price - (float) $product->selling_price) >= 0.005;

                $updates = [];

                if ($priceChanged) {
                    $updates['selling_price'] = $price;
                }

                if ($sellingUnitCost < $price && $sellingUnitCost !== (float) $product->cost_price) {
                    $updates['cost_price'] = $sellingUnitCost;
                }

                if ($updates) {
                    // Only reachable with a price change: a cost sync alone
                    // always lands below the (unchanged) price.
                    $finalCost = (float) ($updates['cost_price'] ?? $product->cost_price);

                    if ($finalCost >= $price) {
                        throw new RuntimeException("\"{$product->name}\": the selling price must be above the product's cost price of ".number_format($finalCost, 2).'. Change the cost price first.');
                    }

                    $previous = $product->only(array_keys($updates));
                    // One update, so a price drop and a cost drop can't trip
                    // the cost-below-price rule halfway through.
                    $product->update($updates);

                    AuditLog::record('update', 'products', 'Product', $product->id, $previous, $product->only(array_keys($updates)));
                }

                if ($priceChanged && $product->promo_discount_type !== 'none') {
                    $promoPrice = Product::priceAfterDiscount($price, $product->promo_discount_type, (float) $product->promo_discount_value);
                    $floor = $product->breakEvenCost();

                    if ($promoPrice < $floor) {
                        throw new RuntimeException("\"{$product->name}\": with its current promotion the price would be ".number_format($promoPrice, 2).', below its cost of '.number_format($floor, 2).'. Change the promotion first.');
                    }
                }

                // A cost that isn't below the price is never a reason to
                // refuse a delivery — the batch keeps its real cost and the
                // receiver is told.
                if ($warning = $product->costAbovePriceWarning($sellingUnitCost)) {
                    $warnings[] = $warning;
                }
                InventoryMovement::create([
                    'product_id' => $lineItem->product_id,
                    'batch_id' => $batch->id,
                    'movement_type' => 'stock_received',
                    'quantity' => $sellingQty,
                    'previous_qty' => 0,
                    'new_qty' => $sellingQty,
                    'reference_table' => 'purchase_orders',
                    'reference_id' => $purchaseOrder->id,
                    'reason' => "Received against {$purchaseOrder->po_number}",
                    'user_id' => $receivedBy->id,
                ]);
                }

                // Units that arrived unsellable: they used up part of the order
                // but never become stock, so there's no batch — just the loss,
                // and a claim against the supplier at what was paid for them.
                if ($damaged > 0) {
                    $lineItem->increment('qty_damaged', $badPk);

                    $warnings[] = $this->recordIssue($purchaseOrder, $lineItem, 'damaged', $badPieces, $product->toSellingUnitCost($paidPerPurchaseUnit, 'purchase'), trim($receipt['damaged_reason']), $receivedBy);
                }

                // What's still outstanding after this delivery, given up on.
                if ($closeShort) {
                    $missingPk = $lineItem->fresh()->remainingQty();
                    $missingPieces = round($missingPk * $conv, 3);

                    if ($missingPieces >= 0.0005) {
                        $lineItem->increment('qty_closed_short', $missingPk);

                        $warnings[] = $this->recordIssue($purchaseOrder, $lineItem, 'missing', $missingPieces, $product->toSellingUnitCost((float) $lineItem->cost_price, 'purchase'), trim($receipt['short_reason']), $receivedBy);
                    }
                }

                // Rounding dust (a hair of a piece) must never keep an order
                // open: fold it into what was received.
                $left = $lineItem->fresh()->remainingQty();

                if ($left > 0 && $left * $conv < 0.0005) {
                    $lineItem->increment('qty_received', $left);
                }
            }

            $purchaseOrder->load('lineItems');

            // A line is finished once every unit is accounted for: received,
            // damaged, or closed short (never coming).
            $accounted = fn ($line) => (float) $line->qty_received + (float) $line->qty_damaged + (float) $line->qty_closed_short;

            $totalReceived = $purchaseOrder->lineItems->sum($accounted);
            $allReceived = $purchaseOrder->lineItems->every(fn ($line) => $accounted($line) >= (float) $line->qty_ordered - 0.000001);

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

        return array_values(array_unique($warnings));
    }
    /**
     * Records one damaged/missing report — quantity in selling units, cost per
     * selling unit, so the loss is exactly pieces × cost — and returns the sentence the receiver
     * sees, so the claim is never a silent side-effect.
     */
    private function recordIssue(PurchaseOrder $po, $lineItem, string $type, float $qty, float $unitCost, string $reason, User $reportedBy): string
    {
        $issue = ReceivingIssue::create([
            'purchase_order_id' => $po->id,
            'purchase_order_line_item_id' => $lineItem->id,
            'product_id' => $lineItem->product_id,
            'supplier_id' => $po->supplier_id,
            'issue_type' => $type,
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'loss_value' => round($qty * $unitCost, 2),
            'reason' => $reason,
            'reported_by' => $reportedBy->id,
        ]);

        AuditLog::record('create', 'purchase_orders', 'ReceivingIssue', $issue->id, null, $issue->only([
            'purchase_order_id', 'product_id', 'issue_type', 'qty', 'loss_value', 'reason',
        ]));

        $qtyText = rtrim(rtrim(number_format($qty, 3), '0'), '.');

        return sprintf(
            '%s %s of "%s" recorded as %s — %s owed by the supplier.',
            $qtyText,
            $lineItem->product->sellingUnit?->name ?? 'units',
            $lineItem->product->name,
            $type === 'damaged' ? 'damaged' : 'missing',
            number_format((float) $issue->loss_value, 2),
        );
    }
}