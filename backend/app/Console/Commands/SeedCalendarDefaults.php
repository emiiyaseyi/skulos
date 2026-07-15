<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Calendar\CalendarService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * Keeps every school's calendar topped up with the current and next
 * calendar year's default notable days (user directive 2026-07-15:
 * "ALWAYS DO IT FOR EVERY YEAR GOING FORWARD TOO. ITS DEFAULT"). Seeds
 * both the current and next year every run so a school never has an empty
 * calendar for the remainder of the year it's in, or the one right after,
 * regardless of exactly when in the year this command happens to run.
 * Idempotent (CalendarService::seedDefaults() dedupes per title+year for
 * dated entries), safe to run as often as the schedule fires.
 */
class SeedCalendarDefaults extends Command
{
    protected $signature = 'skulos:seed-calendar-defaults {year? : Seed only this specific year instead of current+next}';

    protected $description = "Seed every provisioned school's calendar with the current (and next) year's default notable days and public holidays";

    public function handle(TenantManager $tenants, CalendarService $calendar): int
    {
        $years = $this->argument('year') ? [(int) $this->argument('year')] : [now()->year, now()->year + 1];
        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')->get();

        foreach ($schools as $school) {
            try {
                $tenants->use($school);
            } catch (\Throwable $e) {
                $this->error("Skipping {$school->slug}: {$e->getMessage()}");

                continue;
            }

            $total = 0;
            foreach ($years as $year) {
                $total += $calendar->seedDefaults($year);
            }
            $tenants->forget();

            $this->info("{$school->slug}: {$total} event(s) added for ".implode(', ', $years).'.');
        }

        return self::SUCCESS;
    }
}
