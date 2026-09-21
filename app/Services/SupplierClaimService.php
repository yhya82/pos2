<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ReceivingIssue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Settles a claim against a supplier for goods that arrived damaged or never
 * arrived: either they credited it (fully or in part — whatever they actually
 * agreed to give back) or the shop waived it. Once settled it never goes back
 * to "owed", so what a supplier still owes is always what's in the ledger.
 */
class SupplierClaimService
{
    /**
     * @param  string  $action  'credited' or 'waived'
     * @param  float|null  $amount  credited: what the supplier actually gave back (up to the loss)
     *
     * @throws RuntimeException if the claim is already settled or the input is invalid
     */
    public function resolve(ReceivingIssue $issue, string $action, ?float $amount, ?string $note, User $resolvedBy): void
    {
        if (! in_array($action, ['credited', 'waived'], true)) {
            throw new RuntimeException('Choose whether the supplier credited this or the claim is waived.');
        }

        $note = $note !== null ? trim($note) : null;

        DB::transaction(function () use ($issue, $action, $amount, $note, $resolvedBy) {
            $issue = ReceivingIssue::whereKey($issue->id)->lockForUpdate()->firstOrFail();

            if ($issue->claim_status !== 'owed') {
                throw new RuntimeException('This claim has already been settled.');
            }

            if ($action === 'credited') {
                if ($amount === null || $amount <= 0) {
                    throw new RuntimeException('Enter the amount the supplier credited.');
                }

                if ($amount > (float) $issue->loss_value + 0.0001) {
                    throw new RuntimeException('The credit can\'t be more than the claim ('.number_format((float) $issue->loss_value, 2).').');
                }
            } elseif ($note === null || $note === '') {
                throw new RuntimeException('Say why the claim is being waived — it goes in the audit log.');
            }

            $previous = ['claim_status' => 'owed', 'credited_amount' => (float) $issue->credited_amount];

            $issue->claim_status = $action;
            $issue->credited_amount = $action === 'credited' ? round($amount, 2) : 0;
            $issue->resolution_note = $note ?: null;
            $issue->resolved_by = $resolvedBy->id;
            $issue->resolved_at = now();
            $issue->save();

            AuditLog::record('update', 'purchase_orders', 'ReceivingIssue', $issue->id, $previous, [
                'claim_status' => $issue->claim_status,
                'credited_amount' => (float) $issue->credited_amount,
                'note' => $note,
            ]);
        });
    }
}
