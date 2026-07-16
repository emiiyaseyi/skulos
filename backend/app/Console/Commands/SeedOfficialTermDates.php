<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
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

            $tenants->forget();
            $this->info("{$school->slug}: {$updated} row(s) updated.");
        }

        return self::SUCCESS;
    }
}
