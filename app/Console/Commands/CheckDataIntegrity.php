<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\SystemNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Laravel-native replacement for the master project file's
 * ev_daily_integrity_check MySQL EVENT (Part F.1) — polls the four
 * v_integrity_* views (the cross-row rules a MySQL CHECK constraint
 * structurally cannot express: a sale needs ≥1 line, a completed sale
 * needs a payment, a line's allocated batch quantities must sum to its own
 * quantity, a customer's stored balance must match the credit_transactions
 * ledger). Deliberately NOT gated by the notifications module/category
 * toggle the way business notifications are — this is an ops/integrity
 * signal for Administrators, not something a store owner should be able to
 * silence via Settings.
 */
class CheckDataIntegrity extends Command
{
    protected $signature = 'pos:check-integrity';

    protected $description = 'Poll the data-integrity views and notify Administrators if any are non-empty';

    public function handle(): int
    {
        $counts = [
            'sales without line items' => DB::table('v_integrity_sales_without_lines')->count(),
            'completed sales without payment' => DB::table('v_integrity_completed_sales_without_payment')->count(),
            'sale line / batch quantity mismatches' => DB::table('v_integrity_line_batch_mismatch')->count(),
            'customer credit balance mismatches' => DB::table('v_integrity_credit_balance_mismatch')->count(),
        ];

        $issues = array_filter($counts);

        foreach ($counts as $label => $count) {
            $count > 0
                ? $this->warn("{$label}: {$count}")
                : $this->info("{$label}: 0");
        }

        if ($issues === []) {
            $this->info('No data-integrity issues found.');

            return self::SUCCESS;
        }

        $summary = collect($issues)
            ->map(fn ($count, $label) => "{$label}: {$count}")
            ->implode(', ');

        $administratorRoleId = Role::where('name', 'Administrator')->value('id');

        if ($administratorRoleId) {
            SystemNotification::create([
                'category' => 'user_system',
                'message' => "Data integrity check found issues — {$summary}. Investigate via the v_integrity_* views directly.",
                'target_role_id' => $administratorRoleId,
            ]);
        }

        return self::FAILURE;
    }
}
