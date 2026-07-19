<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\SchoolClass;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * One-off backfill (user request 2026-07-18: "populate all Creche/
 * Reception across the schools with this type of report"). Idempotent,
 * safe to re-run: only ever sets evaluation_style to narrative for a
 * class matching the name, never touches a class an admin has already
 * deliberately set either way is not distinguishable from the default,
 * so this simply asserts the intended default rather than trying to
 * detect a prior manual change; running it again is a no-op for a
 * school where it already applied.
 */
class BackfillNarrativeEvaluation extends Command
{
    protected $signature = 'skulos:backfill-narrative-evaluation';

    protected $description = "Set every school's Creche/Reception class(es) to Narrative Evaluation (comment based, no scores)";

    public function handle(TenantManager $tenants): int
    {
        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')->get();
        $totalUpdated = 0;

        foreach ($schools as $school) {
            try {
                $tenants->use($school);
            } catch (\Throwable $e) {
                $this->error("Skipping {$school->slug}: {$e->getMessage()}");

                continue;
            }

            $updated = SchoolClass::where(function ($q) {
                $q->where('name', 'like', '%creche%')->orWhere('name', 'like', '%reception%');
            })->update(['evaluation_style' => SchoolClass::EVALUATION_STYLE_NARRATIVE]);

            $tenants->forget();

            if ($updated > 0) {
                $this->info("{$school->slug}: {$updated} class(es) set to Narrative Evaluation.");
                $totalUpdated += $updated;
            }
        }

        $this->info("Done. {$totalUpdated} class(es) updated across {$schools->count()} school(s).");

        return self::SUCCESS;
    }
}
