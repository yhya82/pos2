<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\GeneralSetting;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\SaleLineItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnLineItem;
use App\Models\ReturnReceipt;
use App\Models\StoreSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Processes a return against a completed sale. Sellable items go back to
 * the same batch(es) the original sale drew from; damaged items are
 * restored then immediately written back off, giving a clean two-step
 * audit trail ("returned", then "damaged") instead of silently never
 * touching inventory. Neither of these has a trigger behind it — only the
 * status-sync trigger (sales_returns → sales.status = 'refunded') does —
 * so this service owns both, plus the credit-balance refund the schema
 * also leaves to application code.
 */
class ReturnService
{
    /**
     * @param  array<int, array{sale_line_item_id: int, quantity: float|string, condition_type: string, reason?: ?string}>  $lines
     *
     * @throws RuntimeException if the sale isn't completed, nothing is selected, or a line exceeds what's still returnable
     */
    public function processReturn(Sale $originalSale, array $lines, ?string $overallReason, User $processedBy): SalesReturn
    {
        if ($originalSale->status !== 'completed') {
            throw new RuntimeException('Only completed sales can have a return processed against them.');
        }

        $lines = array_values(array_filter($lines, fn ($l) => (float) ($l['quantity'] ?? 0) > 0));

        if (empty($lines)) {
            throw new RuntimeException('Select at least one item to return.');
        }

        return DB::transaction(function () use ($originalSale, $lines, $overallReason, $processedBy) {
            $refundAmount = 0.0;
            $lineData = [];

            foreach ($lines as $line) {
                $saleLineItem = SaleLineItem::where('sale_id', $originalSale->id)
                    ->with(['product', 'batches'])
                    ->findOrFail($line['sale_line_item_id']);

                $quantity = (float) $line['quantity'];
                $conditionType = $line['condition_type'];

                if (! in_array($conditionType, ['sellable', 'damaged'], true)) {
                    throw new RuntimeException('Invalid condition type.');
                }

                $remaining = $saleLineItem->remainingReturnableQty();

                if ($quantity > $remaining) {
                    throw new RuntimeException("Can't return {$quantity} of \"{$saleLineItem->product->name}\" — only {$remaining} eligible.");
                }

                $refundAmount += round($quantity * (float) $saleLineItem->unit_price, 2);

                $lineData[] = [
                    'saleLineItem' => $saleLineItem,
                    'quantity' => $quantity,
                    'conditionType' => $conditionType,
                    'reason' => $line['reason'] ?? null,
                ];
            }

            $salesReturn = SalesReturn::create([
                'return_number' => SalesReturn::generateReturnNumber(),
                'original_sale_id' => $originalSale->id,
                'processed_by' => $processedBy->id,
                'reason' => $overallReason,
                'refund_amount' => $refundAmount,
                'status' => 'completed',
            ]);

            foreach ($lineData as $line) {
                SalesReturnLineItem::create([
                    'return_id' => $salesReturn->id,
                    'sale_line_item_id' => $line['saleLineItem']->id,
                    'product_id' => $line['saleLineItem']->product_id,
                    'quantity' => $line['quantity'],
                    'condition_type' => $line['conditionType'],
                    'reason' => $line['reason'],
                ]);

                $this->restoreInventory($line['saleLineItem'], $line['quantity'], $line['conditionType'], $salesReturn, $processedBy);
            }

            $this->refundCreditIfApplicable($originalSale, $refundAmount, $processedBy);

            // trg_sales_returns_sync_status_ins (AFTER INSERT ON
            // sales_returns) flips the original sale's status to
            // 'refunded' automatically now that this row exists with
            // status = 'completed' — not this service's job.

            $this->generateReceipt($salesReturn, $lineData, $originalSale);

            AuditLog::record('create', 'returns', 'SalesReturn', $salesReturn->id);

            return $salesReturn->fresh(['lineItems.product', 'originalSale']);
        });
    }

    private function restoreInventory(SaleLineItem $saleLineItem, float $quantity, string $conditionType, SalesReturn $salesReturn, User $user): void
    {
        $remainingToAllocate = $quantity;

        foreach ($saleLineItem->batches as $allocation) {
            if ($remainingToAllocate <= 0) {
                break;
            }

            $batch = Batch::where('id', $allocation->batch_id)->lockForUpdate()->first();

            if (! $batch) {
                continue;
            }

            $restoreQty = min((float) $allocation->quantity_deducted, $remainingToAllocate);

            if ($restoreQty <= 0) {
                continue;
            }

            $previousQty = (float) $batch->qty_remaining;
            $afterReturn = $previousQty + $restoreQty;
            $batch->qty_remaining = $afterReturn;
            $batch->save();

            InventoryMovement::create([
                'product_id' => $saleLineItem->product_id,
                'batch_id' => $batch->id,
                'movement_type' => 'return',
                'quantity' => $restoreQty,
                'previous_qty' => $previousQty,
                'new_qty' => $afterReturn,
                'reference_table' => 'sales_returns',
                'reference_id' => $salesReturn->id,
                'reason' => "Returned via {$salesReturn->return_number}",
                'user_id' => $user->id,
            ]);

            if ($conditionType === 'damaged') {
                $afterDamage = $afterReturn - $restoreQty;
                $batch->qty_remaining = $afterDamage;
                $batch->save();

                InventoryMovement::create([
                    'product_id' => $saleLineItem->product_id,
                    'batch_id' => $batch->id,
                    'movement_type' => 'damaged',
                    'quantity' => -$restoreQty,
                    'previous_qty' => $afterReturn,
                    'new_qty' => $afterDamage,
                    'reference_table' => 'sales_returns',
                    'reference_id' => $salesReturn->id,
                    'reason' => "Damaged on return via {$salesReturn->return_number} — written off",
                    'user_id' => $user->id,
                ]);
            }

            $remainingToAllocate -= $restoreQty;
        }
    }

    private function refundCreditIfApplicable(Sale $originalSale, float $refundAmount, User $processedBy): void
    {
        $payment = $originalSale->payment;

        if (! $payment || $payment->paymentMethod->code !== 'credit' || ! $originalSale->customer_id) {
            return;
        }

        $customer = Customer::where('id', $originalSale->customer_id)->lockForUpdate()->first();

        if (! $customer) {
            return;
        }

        $previousBalance = (float) $customer->outstanding_balance;
        $applied = min($previousBalance, $refundAmount);
        $newBalance = $previousBalance - $applied;

        $customer->outstanding_balance = $newBalance;
        $customer->save();

        CreditTransaction::create([
            'customer_id' => $customer->id,
            'sale_id' => $originalSale->id,
            'type' => 'payment',
            'amount' => $applied,
            'balance_after' => $newBalance,
            'created_by' => $processedBy->id,
        ]);
    }

    private function generateReceipt(SalesReturn $salesReturn, array $lineData, Sale $originalSale): void
    {
        $generalSettings = GeneralSetting::current();
        $storeSettings = StoreSetting::current();

        ReturnReceipt::create([
            'return_id' => $salesReturn->id,
            'receipt_number' => $salesReturn->return_number,
            'business_snapshot' => [
                'business_name' => $generalSettings->business_name,
                'address' => $generalSettings->address,
                'contact_phone' => $generalSettings->contact_phone,
                'contact_email' => $generalSettings->contact_email,
                'currency_code' => $generalSettings->currency_code,
                'receipt_business_info' => $storeSettings?->receipt_business_info,
            ],
            'line_items_snapshot' => array_map(fn ($line) => [
                'product_name' => $line['saleLineItem']->product->name,
                'quantity' => (string) $line['quantity'],
                'condition_type' => $line['conditionType'],
                'unit_price' => (string) $line['saleLineItem']->unit_price,
                'subtotal' => (string) round($line['quantity'] * (float) $line['saleLineItem']->unit_price, 2),
            ], $lineData),
            'totals_snapshot' => [
                'original_receipt_number' => $originalSale->receipt_number,
                'refund_amount' => (string) $salesReturn->refund_amount,
            ],
        ]);
    }
}
