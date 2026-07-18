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

// Dbase recommendation (standing rule): "anything that grows unbounded"
// needs a retention/cleanup policy. Weekly, off peak hours, so it never
// competes with the every minute backup check above.
Schedule::command('skulos:retention-cleanup')->weeklyOn(1, '02:00')->withoutOverlapping();

// Dbase recommendation: "weekly scheduled MySQL maintenance (optimize/
// analyze/repair tables) per school database."
Schedule::command('skulos:optimize-tables')->weeklyOn(1, '03:00')->withoutOverlapping();

// Keeps every school's calendar topped up with the current and next
// year's default notable days/public holidays automatically, forever,
// not just once at provisioning time (user directive 2026-07-15: "ALWAYS
// DO IT FOR EVERY YEAR GOING FORWARD TOO. ITS DEFAULT"). Monthly is far
// more often than actually needed (a year only changes once), but the
// command is fully idempotent (dedupes per title+year), so running it
// monthly instead of trying to land exactly on January 1st just means the
// year rolls over correctly no matter which day the cron happens to catch
// it on.
Schedule::command('skulos:seed-calendar-defaults')->monthlyOn(1, '04:00')->withoutOverlapping();

// Birthday & Anniversary Notifications (user directive 2026-07-16, "ALL
// THERE": build out the remaining Reports & Analytics/Communication
// "Soon" items). Early morning, before the school day starts; idempotent
// (checked per person/category/day), safe if the schedule ever fires
// twice in the same day.
Schedule::command('skulos:notify-birthdays-anniversaries')->dailyAt('06:00')->withoutOverlapping();

// Self-hosted license check-in (user request 2026-07-18). Safe to run on
// every deployment, including the main platform's own: the command
// itself no-ops unless config('skulos.self_hosted') is true.
Schedule::command('license:check')->hourly()->withoutOverlapping();
