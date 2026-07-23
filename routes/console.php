<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Production-readiness scheduled jobs (master project file Part F.1),
// translated to Laravel's own scheduler per that file's own header note —
// requires a real `* * * * * php artisan schedule:run` cron entry (or
// `php artisan schedule:work` for local/dev) to actually fire; registering
// them here alone doesn't run them without that entry point.
Schedule::command('pos:prune')->daily();
Schedule::command('pos:check-integrity')->daily();
Schedule::command('pos:backup')->dailyAt('02:00');
Schedule::command('pos:health-check')->everyFifteenMinutes();
