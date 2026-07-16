<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\CalendarEvent;
use App\Models\Tenant\Term;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * Corrects/fills real Term and AcademicSession start/end dates using the
 * official Lagos State Ministry of Basic & Secondary Education (OEQA)
 * harmonised academic calendar (user request 2026-07-16: "CHECK FOR LAGOS
 * STATE OR OGUN STATE ACADEMIC CALENDAR FOR THE SESSION OF 2024/2025 AND
 * 2025/2026 TO POPULATE THE RESPECTIVE AND ACTIVE TERMS"). A newly created
 * session's terms start with no dates at all (AcademicsController::
 * createSessionWithTerms() intentionally leaves them for a school admin to
 * set), so this is a one time backfill/correction against the catalog in
 * config('skulos.official_term_dates'), matched by session name
 * ("2024/2025 Session", "2025/2026 Session"), not a live runtime read.
 * Idempotent: always writes the config's dates, safe to re-run.
 *
 * Also cleans up the calendar's own undated default placeholders (user
 * report 2026-07-16, screenshot of the calendar management screen: "still
 * cant see date for these under calendar, make sure that all school has
 * it"): "Beginning of Term"/"End of Term"/"Mid Term Break" are exactly the
 * school's own current term's resumption/last-teaching-day/mid-term-break
 * dates, which this same catalog already has, so those three get dated
 * too. "Public Holiday" is a stale, superseded placeholder (config used to
 * seed one generic undated "Public Holiday" row before the specific named
 * holidays existed; already-provisioned schools were never cleaned up
 * when that config entry was replaced), deleted outright. "Art and
 * Culture Day"/"Professional Day"/"Inter House Sports Day" are left
 * alone, deliberately: there is no official date for these, a school
 * genuinely picks its own, inventing one would violate the project's "no
 * dummy/placeholder info" rule.
 */
class SeedOfficialTermDates extends Command
{
    protected $signature = 'skulos:seed-official-term-dates {school? : Only this school\'s slug}';

    protected $description = "Fill/correct a school's 2024/2025 and 2025/2026 session and term dates from the official Lagos State academic calendar";

    public function handle(TenantManager $tenants): int
    {
        $catalog = config('skulos.official_term_dates', []);
        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')
            ->when($this->argument('school'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        foreach ($schools as $school) {
            try {
                $tenants->use($school);
            } catch (\Throwable $e) {
                $this->error("Skipping {$school->slug}: {$e->getMessage()}");

                continue;
            }

            $updated = 0;
            foreach ($catalog as $yearLabel => $dates) {
                $session = AcademicSession::where('name', "{$yearLabel} Session")->first();
                if (! $session) {
                    continue;
                }

                $session->update([
                    'starts_on' => $dates['session']['starts_on'],
                    'ends_on' => $dates['session']['ends_on'],
                ]);
                $updated++;

                foreach ($session->terms as $term) {
                    $termDates = $dates['terms'][$term->sequence] ?? null;
                    if (! $termDates) {
                        continue;
                    }

                    $term->update([
                        'starts_on' => $termDates['starts_on'],
                        'ends_on' => $termDates['ends_on'],
                    ]);
                    $updated++;
                }
            }

            $updated += $this->cleanUpCalendarPlaceholders($catalog);

            $tenants->forget();
            $this->info("{$school->slug}: {$updated} row(s) updated.");
        }

        return self::SUCCESS;
    }

    protected function cleanUpCalendarPlaceholders(array $catalog): int
    {
        $changed = 0;

        $deleted = CalendarEvent::where('is_default', true)
            ->where('title', 'Public Holiday')
            ->whereNull('starts_on')
            ->delete();
        $changed += $deleted;

        $term = Term::current()->with('session')->first();
        if (! $term || ! $term->session) {
            return $changed;
        }
        $yearLabel = str_replace(' Session', '', $term->session->name);
        $termDates = $catalog[$yearLabel]['terms'][$term->sequence] ?? null;
        if (! $termDates) {
            return $changed;
        }

        $changed += CalendarEvent::where('is_default', true)->where('title', 'Beginning of Term')
            ->whereNull('starts_on')->update(['starts_on' => $termDates['starts_on']]);
        $changed += CalendarEvent::where('is_default', true)->where('title', 'End of Term')
            ->whereNull('starts_on')->update(['starts_on' => $termDates['ends_on']]);
        if (isset($termDates['mid_term_break'])) {
            $changed += CalendarEvent::where('is_default', true)->where('title', 'Mid Term Break')
                ->whereNull('starts_on')
                ->update(['starts_on' => $termDates['mid_term_break'][0], 'ends_on' => $termDates['mid_term_break'][1]]);
        }

        return $changed;
    }
}
