<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Each school picks its own backup schedule (daily/weekly/monthly + time
// of day); this checks hourly for anything due right now. Requires a real
// cron entry running `php artisan schedule:run` every minute in production
// (standard Laravel/cPanel setup) — nothing runs on its own otherwise.
Schedule::command('backups:run-scheduled')->hourly();
