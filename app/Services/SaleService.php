<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\GeneralSetting;
use App\Models\InventoryMovement;
use App\Models\ModuleSetting;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\SaleLineItemBatch;
use App\Models\SalesSetting;
use App\Models\StoreSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns the one transaction the master project file calls out as needing
 * application-level orchestration: stock-sufficiency checks, FEFO batch
 * selection, and the credit-limit check, all wrapped in one all-or-nothing
 * write. Everything a DB trigger can already enforce on its own — credit
 * sales requiring a customer, voiding reversing inventory — is left to
 * that trigger; this service never re-implements it.
 */
class SaleService
{
    /**
     * @param  array<int, array{product_id: int, quantity: float|string, unit_price?: float|string}>  $cartLines
     *
     * @throws RuntimeException on empty cart, insufficient stock, disabled/missing customer credit, or a discount over the configured cap
     */
    public function completeSale(
        array $cartLines,
        ?int $customerId,
        int $paymentMethodId,
        ?string $referenceNumber,
        string $discountType,
        float $discountValue,
        ?string $discountReason,
        User $cashier,
    ): Sale {
        if (empty($cartLines)) {
            throw new RuntimeException('Cannot complete a sale with no items.');
        }

        return DB::transaction(function () use ($cartLines, $customerId, $paymentMethodId, $referenceNumber, $discountType, $discountValue, $discountReason, $cashier) {
            $paymentMethod = PaymentMethod::where('is_enabled', true)->findOrFail($paymentMethodId);
            $isCredit = $paymentMethod->code === 'credit';

            $customer = $customerId ? Customer::lockForUpdate()->findOrFail($customerId) : null;

            if ($isCredit) {
                if (! ModuleSetting::enabled('customer_credit')) {
                    throw new RuntimeException('Customer credit is currently disabled.');
                }

                if (! $customer) {
                    throw new RuntimeException('Credit sales require a customer to be selected.');
                }

                if (! $customer->credit_enabled) {
                    throw new RuntimeException("\"{$customer->name}\" does not have credit enabled.");
                }
            }

            $salesSettings = SalesSetting::current();
            $generalSettings = GeneralSetting::current();

            [$lineData, $subtotal] = $this->buildLines($cartLines, $salesSettings);

            $discountType = $discountType === 'none' ? 'none' : $discountType;
            $discountValue = $discountType === 'none' ? 0.0 : $discountValue;

            $discountAmount = match ($discountType) {
                'fixed' => $discountValue,
                'percentage' => round($subtotal * $discountValue / 100, 2),
                default => 0.0,
            };

            $effectivePercentage = $subtotal > 0 ? ($discountAmount / $subtotal * 100) : 0;

            if ($effectivePercentage > (float) $salesSettings->max_discount_percentage) {
                throw new RuntimeException("Discount exceeds the maximum allowed ({$salesSettings->max_discount_percentage}%).");
            }

            $discountAmount = min($discountAmount, $subtotal);

            $taxableAmount = $subtotal - $discountAmount;
            $taxAmount = $generalSettings->tax_enabled
                ? round($taxableAmount * (float) $generalSettings->tax_rate / 100, 2)
                : 0.0;

            $totalAmount = round($taxableAmount + $taxAmount, 2);

            if ($isCredit) {
                $newBalance = (float) $customer->outstanding_balance + $totalAmount;

                if ($newBalance > (float) $customer->credit_limit) {
                    $available = $customer->availableCredit();

                    throw new RuntimeException("This sale would exceed \"{$customer->name}\"'s credit limit (available: {$available}).");
                }
            }

            $sale = Sale::create([
                'receipt_number' => Sale::generateReceiptNumber(),
                'sale_date' => now(),
                'cashier_id' => $cashier->id,
                'customer_id' => $customer?->id,
                'subtotal' => $subtotal,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'discount_reason' => $discountAmount > 0 ? $discountReason : null,
                'discount_applied_by' => $discountAmount > 0 ? $cashier->id : null,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'status' => 'completed',
            ]);

            $this->writeLinesAndDeductStock($sale, $lineData, $cashier);

            $sale->payment()->create([
                'payment_method_id' => $paymentMethod->id,
                'amount' => $totalAmount,
                'reference_number' => $referenceNumber,
                'received_by' => $cashier->id,
                'paid_at' => now(),
            ]);

            if ($isCredit) {
                $newBalance = (float) $customer->outstanding_balance + $totalAmount;
                $customer->outstanding_balance = $newBalance;
                $customer->save();

                CreditTransaction::create([
                    'customer_id' => $customer->id,
                    'sale_id' => $sale->id,
                    'type' => 'credit_sale',
                    'amount' => $totalAmount,
                    'balance_after' => $newBalance,
                    'created_by' => $cashier->id,
                ]);
            }

            $this->generateReceipt($sale, $lineData, $paymentMethod, $generalSettings);

            AuditLog::record('create', 'sales', 'Sale', $sale->id);

            return $sale->fresh(['lineItems.product', 'payment.paymentMethod', 'customer']);
        });
    }

    public function voidSale(Sale $sale, string $reason, User $voidedBy): void
    {
        if ($sale->status !== 'completed') {
            throw new RuntimeException('Only completed sales can be voided.');
        }

        // Flips status only — trg_sales_void_reverses_inventory (AFTER
        // UPDATE ON sales) owns reversing the batches, inventory_movements,
        // and any credit-balance charge. Re-implementing that here would
        // either duplicate the reversal or race with the trigger's own.
        $sale->update([
            'status' => 'voided',
            'voided_by' => $voidedBy->id,
            'voided_at' => now(),
            'void_reason' => $reason,
        ]);

        AuditLog::record('void', 'sales', 'Sale', $sale->id, ['status' => 'completed'], ['status' => 'voided']);
    }

    /**
     * Validates stock sufficiency and selects FEFO batch allocations for
     * every line before anything is written — a shortfall on line 3
     * shouldn't leave lines 1–2 already committed.
     *
     * @return array{0: array<int, array{product: Product, quantity: float, unit_price: float, subtotal: float, allocations: array<int, array{batch: Batch, qty: float}>}>, 1: float}
     */
    private function buildLines(array $cartLines, SalesSetting $salesSettings): array
    {
        $lineData = [];
        $subtotal = 0.0;

        foreach ($cartLines as $line) {
            $product = Product::findOrFail($line['product_id']);
            $quantity = (float) $line['quantity'];

            if ($quantity <= 0) {
                throw new RuntimeException("Invalid quantity for \"{$product->name}\".");
            }

            $unitPrice = isset($line['unit_price']) ? (float) $line['unit_price'] : (float) $product->selling_price;
            $lineSubtotal = round($quantity * $unitPrice, 2);
            $subtotal += $lineSubtotal;

            $batches = Batch::where('product_id', $product->id)
                ->where('status', 'active')
                ->where('qty_remaining', '>', 0)
                ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
                ->lockForUpdate()
                ->get();

            $available = (float) $batches->sum('qty_remaining');

            if ($available < $quantity && ! $salesSettings->allow_negative_stock_sale) {
                throw new RuntimeException("Not enough stock for \"{$product->name}\" — only {$available} available.");
            }

            $remaining = $quantity;
            $allocations = [];

            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min((float) $batch->qty_remaining, $remaining);

                if ($take <= 0) {
                    continue;
                }

                $allocations[] = ['batch' => $batch, 'qty' => $take];
                $remaining -= $take;
            }

            // If $remaining > 0 here, allow_negative_stock_sale let the sale
            // through with more stock committed than batches can back —
            // the shortfall isn't allocated to any batch. That's a known,
            // monitored gap (v_integrity_line_batch_mismatch), not a bug.

            $lineData[] = [
                'product' => $product,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $lineSubtotal,
                'allocations' => $allocations,
            ];
        }

        return [$lineData, $subtotal];
    }

    private function writeLinesAndDeductStock(Sale $sale, array $lineData, User $cashier): void
    {
        foreach ($lineData as $line) {
            $lineItem = $sale->lineItems()->create([
                'product_id' => $line['product']->id,
                'selling_unit_id' => $line['product']->selling_unit_id,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'subtotal' => $line['subtotal'],
            ]);

            foreach ($line['allocations'] as $allocation) {
                $batch = $allocation['batch'];
                $take = $allocation['qty'];

                SaleLineItemBatch::create([
                    'sale_line_item_id' => $lineItem->id,
                    'batch_id' => $batch->id,
                    'quantity_deducted' => $take,
                ]);

                $previousQty = (float) $batch->qty_remaining;
                $newQty = $previousQty - $take;
                $batch->qty_remaining = $newQty;
                $batch->save();

                InventoryMovement::create([
                    'product_id' => $line['product']->id,
                    'batch_id' => $batch->id,
                    'movement_type' => 'sale',
                    'quantity' => -$take,
                    'previous_qty' => $previousQty,
                    'new_qty' => $newQty,
                    'reference_table' => 'sales',
                    'reference_id' => $sale->id,
                    'reason' => "Sold via {$sale->receipt_number}",
                    'user_id' => $cashier->id,
                ]);
            }
        }
    }

    private function generateReceipt(Sale $sale, array $lineData, PaymentMethod $paymentMethod, GeneralSetting $generalSettings): void
    {
        $storeSettings = StoreSetting::current();

        Receipt::create([
            'sale_id' => $sale->id,
            'receipt_number' => $sale->receipt_number,
            'business_snapshot' => [
                'business_name' => $generalSettings->business_name,
                'address' => $generalSettings->address,
                'contact_phone' => $generalSettings->contact_phone,
                'contact_email' => $generalSettings->contact_email,
                'currency_code' => $generalSettings->currency_code,
                'receipt_business_info' => $storeSettings?->receipt_business_info,
            ],
            'line_items_snapshot' => array_map(fn ($line) => [
                'product_name' => $line['product']->name,
                'quantity' => (string) $line['quantity'],
                'unit' => $line['product']->sellingUnit->name,
                'unit_price' => (string) $line['unit_price'],
                'subtotal' => (string) $line['subtotal'],
            ], $lineData),
            'totals_snapshot' => [
                'subtotal' => (string) $sale->subtotal,
                'discount_type' => $sale->discount_type,
                'discount_amount' => (string) $sale->discount_amount,
                'tax_amount' => (string) $sale->tax_amount,
                'total_amount' => (string) $sale->total_amount,
                'payment_method' => $paymentMethod->name,
            ],
        ]);
    }
}
