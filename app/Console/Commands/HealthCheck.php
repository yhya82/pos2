<?php

namespace App\Console\Commands;

use App\Models\BackupRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Laravel-native equivalent of the master project file's
 * pos_external_health_check.sh (Part F.4), trimmed to what's actually
 * checkable from inside this app rather than a standalone ops box: DB
 * connectivity, the four v_integrity_* views, disk space under storage/,
 * and backup staleness (the shell script's "stale scheduled event" check,
 * translated to "has pos:backup actually produced a completed run
 * recently" since Laravel's own scheduler replaces the MySQL EVENTs that
 * check was originally written against).
 *
 * TLS-enforcement verification and MySQL-account-level checks from the
 * original script are deliberately not reproduced here — those are DB
 * *server* configuration concerns (see pos_production_readiness.sql),
 * outside what an application-level Artisan command can meaningfully test.
 *
 * Exit code 0 = healthy, 1 = problem found — wire this into cron/a
 * monitoring agent the same way the original shell script documents.
 */
class HealthCheck extends Command
{
    protected $signature = 'pos:health-check';

    protected $description = 'Check DB connectivity, data integrity, disk space, and backup freshness';

    private int $problems = 0;

    public function handle(): int
    {
        $this->checkConnectivity();
        $this->checkIntegrityViews();
        $this->checkDiskSpace();
        $this->checkBackupFreshness();

        if ($this->problems > 0) {
            $this->error("SUMMARY: {$this->problems} problem(s) found.");

            return self::FAILURE;
        }

        $this->info('SUMMARY: all checks passed.');

        return self::SUCCESS;
    }

    private function reportProblem(string $message): void
    {
        $this->error("PROBLEM: {$message}");
        $this->problems++;
    }

    private function checkConnectivity(): void
    {
        try {
            DB::select('SELECT 1');
            $this->info('OK: database connection is alive.');
        } catch (Throwable $e) {
            $this->reportProblem("cannot connect to the database: {$e->getMessage()}");
        }
    }

    private function checkIntegrityViews(): void
    {
        $views = [
            'v_integrity_sales_without_lines',
            'v_integrity_completed_sales_without_payment',
            'v_integrity_line_batch_mismatch',
            'v_integrity_credit_balance_mismatch',
        ];

        foreach ($views as $view) {
            try {
                $count = DB::table($view)->count();

                if ($count > 0) {
                    $this->reportProblem("{$view} has {$count} row(s) — a data-integrity rule was violated somewhere.");
                } else {
                    $this->info("OK: {$view} is clean.");
                }
            } catch (Throwable $e) {
                $this->reportProblem("could not query {$view}: {$e->getMessage()}");
            }
        }
    }

    private function checkDiskSpace(): void
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total === 0) {
            $this->line("INFO: could not determine disk usage for {$path}.");

            return;
        }

        $usedPct = round((1 - $free / $total) * 100, 1);
        $this->line("INFO: disk usage at {$path}: {$usedPct}%");

        if ($usedPct >= 90) {
            $this->reportProblem("disk usage critical: {$usedPct}% (threshold 90%)");
        } elseif ($usedPct >= 80) {
            $this->reportProblem("disk usage high: {$usedPct}% (threshold 80%)");
        } else {
            $this->info('OK: disk usage is within normal range.');
        }
    }

    private function checkBackupFreshness(): void
    {
        $latest = BackupRecord::where('status', 'completed')->latest('completed_at')->first();

        if (! $latest) {
            $this->reportProblem('no completed backup has ever been recorded.');

            return;
        }

        if ($latest->completed_at->lt(now()->subHours(26))) {
            $this->reportProblem("most recent completed backup is from {$latest->completed_at} — over 26 hours ago.");

            return;
        }

        $this->info("OK: most recent completed backup was {$latest->completed_at}.");
    }
}
