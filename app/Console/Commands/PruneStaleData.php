<?php

namespace App\Console\Commands;

use App\Models\LoginSession;
use App\Models\SystemNotification;
use Illuminate\Console\Command;

/**
 * Laravel-native replacement for the master project file's
 * ev_daily_maintenance_cleanup MySQL EVENT (Part F.1) — that file's own
 * header note says scheduled jobs should move to Laravel's Schedule facade
 * on this stack rather than living as MySQL EVENTs. Same retention windows
 * as the original: login_sessions 30 days past expiry, read notifications
 * 90 days old.
 */
class PruneStaleData extends Command
{
    protected $signature = 'pos:prune';

    protected $description = 'Purge expired login sessions and old read notifications';

    public function handle(): int
    {
        $sessions = LoginSession::where('expires_at', '<', now()->subDays(30))->delete();
        $this->info("Deleted {$sessions} expired login session(s) older than 30 days.");

        $notifications = SystemNotification::where('is_read', true)
            ->where('created_at', '<', now()->subDays(90))
            ->delete();
        $this->info("Deleted {$notifications} read notification(s) older than 90 days.");

        return self::SUCCESS;
    }
}
