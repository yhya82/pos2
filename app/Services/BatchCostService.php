<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fixes a batch that was received at the wrong cost (a typo, or a cost
 * entered per pack instead of per piece). Stock valuation and the profit
 * reports read each batch's own unit_cost, so correcting the product's
 * reference cost never fixed a batch that was already recorded wrong — this
 * is the only way to.
 *
 * Deliberately a separate, audited action rather than a field on the
 * adjustment form: it rewrites a cost that past sales were already costed
 * against, so the profit reported for units already sold from this batch
 * changes too. That's the point of a correction, but it should be a
 * conscious, recorded one.
 *
 * Often the wrong number is the price, not the cost (a 20 cost against a 5
 * price could be either), so the same correction can also set the product's
 * selling price — in one transaction, so a rule failure leaves both untouched.
 */
class BatchCostService
{
    /**
     * @return string|null a note when the product's reference cost price followed the correction
     *
     * @throws RuntimeException if nothing changes, or a value breaks a price rule
     */
    public function correct(Batch $batch, float $newUnitCost, string $reason, ?float $newSellingPrice = null): ?string
    {
        if ($newUnitCost < 0) {
            throw new RuntimeException('A cost cannot be negative.');
        }

        if ($newSellingPrice !== null && $newSellingPrice <= 0) {
            throw new RuntimeException('The selling price must be above 0.');
        }

        return DB::transaction(function () use ($batch, $newUnitCost, $reason, $newSellingPrice): ?string {
            $batch = Batch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($batch->product_id)->lockForUpdate()->firstOrFail();

            $previousCost = (float) $batch->unit_cost;
            $previousPrice = (float) $product->selling_price;

            $costChanged = abs($previousCost - $newUnitCost) >= 0.005;
            $priceChanged = $newSellingPrice !== null && abs($previousPrice - $newSellingPrice) >= 0.005;

            if (! $costChanged && ! $priceChanged) {
                throw new RuntimeException('Nothing to change — enter a different cost or selling price.');
            }

            if ($costChanged) {
                $batch->unit_cost = $newUnitCost;
                $batch->save();

                AuditLog::record(
                    'update',
                    'inventory',
                    'Batch',
                    $batch->id,
                    ['unit_cost' => $previousCost],
                    ['unit_cost' => $newUnitCost, 'reason' => $reason],
                );
            }

            if ($priceChanged) {
                if ($newSellingPrice <= (float) $product->cost_price) {
                    throw new RuntimeException("The selling price must be above the product's cost price of ".number_format((float) $product->cost_price, 2).'. Change the cost price first.');
                }

                $product->selling_price = $newSellingPrice;
                $product->save();

                // The floor is read after the batch fix above, so correcting
                // an inflated cost and lowering the price can go together.
                if ($product->promo_discount_type !== 'none') {
                    $promoPrice = Product::priceAfterDiscount($newSellingPrice, $product->promo_discount_type, (float) $product->promo_discount_value);
                    $floor = $product->breakEvenCost();

                    if ($promoPrice < $floor) {
                        throw new RuntimeException('With this product\'s current promotion the price would be '.number_format($promoPrice, 2).', below its cost of '.number_format($floor, 2).'. Change the promotion first.');
                    }
                }

                AuditLog::record(
                    'update',
                    'products',
                    'Product',
                    $product->id,
                    ['selling_price' => $previousPrice],
                    ['selling_price' => $newSellingPrice, 'reason' => $reason],
                );
            }

            return $costChanged ? $this->followWithReferenceCost($batch, $product) : null;
        });
    }

    /**
     * products.cost_price is the reference cost receiving keeps in step with
     * the newest batch. Correcting that same batch should move it too, or the
     * product and its stock disagree — the confusion this exists to avoid.
     * Older batches don't define it, and a cost that isn't below the selling
     * price can't be held by the product (a database rule), so those are left.
     */
    private function followWithReferenceCost(Batch $batch, Product $product): ?string
    {
        $newest = Batch::where('product_id', $product->id)->orderByDesc('received_date')->orderByDesc('id')->first();

        $cost = (float) $batch->unit_cost;

        if (! $newest || $newest->id !== $batch->id
            || $cost >= (float) $product->selling_price
            || abs($cost - (float) $product->cost_price) < 0.005) {
            return null;
        }

        $previous = $product->only(['cost_price']);
        $product->update(['cost_price' => $cost]);

        AuditLog::record('update', 'products', 'Product', $product->id, $previous, $product->only(['cost_price']));

        return "The product's cost price was updated to ".number_format($cost, 2).' to match.';
    }
}
