<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Each school picks its own backup schedule (daily/weekly/monthly + time
// of day, to the minute); this checks every minute for anything due right
// now, matching the chosen time exactly. Requires a real cron entry
// running `php artisan schedule:run` every minute in production (standard
// Laravel/cPanel setup), nothing runs on its own otherwise. Was
// `->hourly()` until 2026-07-10, which only ever fired at the top of the
// hour, so any schedule not set to a "HH:00" time could never actually
// match and silently never ran.
Schedule::command('backups:run-scheduled')->everyMinute()->withoutOverlapping();
