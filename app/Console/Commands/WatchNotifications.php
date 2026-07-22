<?php

namespace App\Console\Commands;

use App\Events\NotificationCreated;
use App\Models\SystemNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A long-running process, not a scheduled task — Laravel's scheduler can't
 * run more often than once a minute, and that's too slow for "real-time."
 * Almost every system_notifications row is created by a DB trigger, not
 * PHP, so this is the only place that can notice a new row exists at all;
 * it polls the DB itself (on a short interval) and broadcasts each new row
 * over Reverb the moment it's found, marking broadcast_at so it's never
 * sent twice.
 *
 * Every broadcast attempt is individually caught: a transient failure
 * (Reverb momentarily unreachable, a network hiccup) logs and moves on to
 * the next notification and the next poll, instead of taking the whole
 * watcher down — confirmed the hard way, when a stale Reverb process
 * caused one broadcast to fail and killed the entire loop.
 *
 * Run alongside `php artisan serve` and `php artisan reverb:start`:
 *   php artisan notifications:watch
 */
class WatchNotifications extends Command
{
    protected $signature = 'notifications:watch {--interval=2 : Seconds between polls}';

    protected $description = 'Broadcast newly created system_notifications rows over Reverb in near real time';

    public function handle(): int
    {
        $interval = max(1, (int) $this->option('interval'));

        $this->info("Watching system_notifications for unbroadcast rows every {$interval}s. Ctrl+C to stop.");

        while (true) {
            try {
                $this->broadcastPending();
            } catch (Throwable $e) {
                $this->error("Poll failed, will retry next cycle: {$e->getMessage()}");
                Log::warning('notifications:watch poll failed', ['exception' => $e]);
            }

            sleep($interval);
        }
    }

    private function broadcastPending(): void
    {
        SystemNotification::whereNull('broadcast_at')
            ->orderBy('id')
            ->chunkById(50, function ($notifications) {
                foreach ($notifications as $notification) {
                    try {
                        event(new NotificationCreated($notification));

                        $notification->forceFill(['broadcast_at' => now()])->save();

                        $this->line("Broadcast #{$notification->id}: {$notification->message}");
                    } catch (Throwable $e) {
                        // Deliberately NOT marking broadcast_at — leave it
                        // NULL so the next poll retries this same
                        // notification instead of silently dropping it.
                        $this->error("Failed to broadcast #{$notification->id}, will retry: {$e->getMessage()}");
                        Log::warning('notifications:watch broadcast failed', [
                            'notification_id' => $notification->id,
                            'exception' => $e,
                        ]);
                    }
                }
            });
    }
}
