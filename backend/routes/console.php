<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Processes queued jobs (announcement/broadcast emails, audit follow-up
// 2026-07-30: these used to Mail::send() inline in the HTTP request that
// triggered them, a real timeout risk for a large "all" audience send).
// --stop-when-empty exits once the queue is drained rather than running
// forever, since this is invoked fresh every minute by cron via
// schedule:run, not a persistent daemon; --max-time is a safety cap so a
// slow SMTP server can never make one run overlap the next minute's tick
// (withoutOverlapping() already guards this too, belt and suspenders).
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();

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

// Platform database backup (2026-07-21): every school's own tenant
// database has had scheduled backups since 2026-07-09, the shared
// platform database (schools, users, plans, billing, licenses) never
// did, a genuine gap found during a deployment readiness review. Off
// peak, once a day, same as the other maintenance jobs.
Schedule::command('platform:backup')->dailyAt('02:30')->withoutOverlapping();

// Admissions module (2026-07-30): archives any applicant account whose
// temporary application window has lapsed, even if they never came back
// to trigger EnsureApplicantActive's own inline check. Daily is enough,
// the window itself is measured in weeks (default 30 days), not hours.
Schedule::command('admissions:archive-expired-applicants')->dailyAt('01:00')->withoutOverlapping();

// SKULOS Assessment Hub, Phase 2b: the client-side countdown already
// auto-submits on its own; this is only the server-side backstop for a
// crashed/closed/offline browser tab, so it needs to run every minute,
// not daily — an exam deadline matters within minutes, not weeks.
Schedule::command('cbt:auto-submit-expired-sessions')->everyMinute()->withoutOverlapping();

// Audit log hardening (2026-07-20). Export is hourly so a durable,
// external copy of new audit activity never sits unshipped for long;
// verify is once daily, off peak, since walking every row on every
// connection is the heavier of the two and a break, once introduced,
// stays a break until fixed, an hourly cadence would only spam duplicate
// alerts. Both loop every provisioned school in addition to the platform.
Schedule::command('audit-logs:export')->hourly()->withoutOverlapping();
Schedule::command('audit-logs:verify')->dailyAt('05:00')->withoutOverlapping();
