<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\ClassNarrativeTerm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Term;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * One-off backfill (user request 2026-07-18: "populate all Creche/
 * Reception across the schools with this type of report"). Idempotent,
 * safe to re-run: only ever adds a ClassNarrativeTerm row for a class
 * matching the name, for the school's own current term, never touches a
 * class an admin has already deliberately set either way, and never
 * removes a row this or an admin already created.
 *
 * Per-class-per-term (2026-07-26): Narrative Evaluation is no longer a
 * permanent evaluation_style flag, see the class_narrative_terms
 * migration/SchoolClass::isNarrativeEvaluationForTerm(). This command now
 * targets the school's current term only, matching how an admin toggling
 * it by hand on the Narrative Evaluation settings page works, a school
 * still needs to re-run this (or toggle by hand) for a later term.
 */
class BackfillNarrativeEvaluation extends Command
{
    protected $signature = 'skulos:backfill-narrative-evaluation';

    protected $description = "Set every school's Creche/Reception class(es) to Narrative Evaluation (comment based, no scores) for its current term";

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

            $term = Term::current()->first();
            if (! $term) {
                $tenants->forget();

                continue;
            }

            $classIds = SchoolClass::where(function ($q) {
                $q->where('name', 'like', '%creche%')->orWhere('name', 'like', '%reception%');
            })->pluck('id');

            $updated = 0;
            foreach ($classIds as $classId) {
                $row = ClassNarrativeTerm::firstOrCreate(['school_class_id' => $classId, 'term_id' => $term->id]);
                if ($row->wasRecentlyCreated) {
                    $updated++;
                }
            }

            $tenants->forget();

            if ($updated > 0) {
                $this->info("{$school->slug}: {$updated} class(es) set to Narrative Evaluation for the current term.");
                $totalUpdated += $updated;
            }
        }

        $this->info("Done. {$totalUpdated} class(es) updated across {$schools->count()} school(s).");

        return self::SUCCESS;
    }
}
