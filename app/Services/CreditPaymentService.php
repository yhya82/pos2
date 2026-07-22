<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records a customer paying down their credit balance. There's no trigger
 * for this side of the ledger (only the sale-void reversal writes a
 * 'payment'-type row on its own) — same reasoning as SaleService's credit
 * charge: a balance mutation + ledger entry that has to happen together is
 * application-level orchestration, not something a single-row trigger can
 * express.
 */
class CreditPaymentService
{
    /**
     * @throws RuntimeException if the amount is invalid or exceeds what's owed
     */
    public function recordPayment(Customer $customer, float $amount, User $receivedBy): CreditTransaction
    {
        if ($amount <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($customer, $amount, $receivedBy) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $previousBalance = (float) $customer->outstanding_balance;

            if ($amount > $previousBalance) {
                throw new RuntimeException("Payment of {$amount} exceeds the outstanding balance of {$previousBalance}.");
            }

            $newBalance = $previousBalance - $amount;
            $customer->outstanding_balance = $newBalance;
            $customer->save();

            $transaction = CreditTransaction::create([
                'customer_id' => $customer->id,
                'sale_id' => null,
                'type' => 'payment',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'created_by' => $receivedBy->id,
            ]);

            AuditLog::record(
                'payment',
                'customers',
                'Customer',
                $customer->id,
                ['outstanding_balance' => $previousBalance],
                ['outstanding_balance' => $newBalance],
            );

            return $transaction;
        });
    }
}
