<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\Score;
use App\Services\Results\ResultService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off rescale of already-seeded demo scores (user directive
 * 2026-07-16: "THE 62.2% Term Average SHOULD STILL GO HIGHER UP TO 80%
 * (UPDATE THE STUDENT SEEDED ASSESSMENT RECORD TO ACHIEVE THIS)").
 *
 * PopulateStudentScores never overwrites a Score row that already exists
 * (its own "never overwrite a real teacher's marks" safety rule), so
 * simply raising its random ability range and re-running it does nothing
 * for a school that is already fully scored, like greenfield. This
 * multiplies every existing Score by one factor per school, computed so
 * the school's overall average lands on --target, clamped at each
 * component's own max_score so a rescaled score never exceeds what it is
 * actually out of. Preserves relative student ranking/spread (everyone
 * moves by the same factor), so Students At Risk and broadsheet ordering
 * stay meaningful, only the whole distribution shifts.
 */
class RescaleStudentScores extends Command
{
    protected $signature = 'schools:rescale-scores {school? : A specific school slug, otherwise every provisioned school} {--target=80 : Target overall average percentage}';

    protected $description = 'Rescale already-seeded Score rows so a school\'s overall average lands on --target, preserving relative spread';

    public function handle(TenantManager $tenants, ResultService $results): int
    {
        $slug = $this->argument('school');
        $target = (float) $this->option('target');

        $schools = $slug
            ? School::where('slug', $slug)->whereNotNull('database')->whereNotNull('provisioned_at')->get()
            : School::whereNotNull('database')->whereNotNull('provisioned_at')->get();

        if ($schools->isEmpty()) {
            $this->error($slug ? "No provisioned school found for slug \"{$slug}\"." : 'No provisioned schools found.');

            return self::FAILURE;
        }

        foreach ($schools as $school) {
            $tenants->use($school);

            // Memory found the hard way against real seeded data (2026-07-16):
            // Score::all() on a real school (thousands of rows across every
            // student x subject x component x term x session) exhausted the
            // default 128M limit. chunkById keeps memory flat regardless of
            // school size, the same fix already applied to the test suite's
            // own memory ceiling for image processing earlier this session.
            if (! Score::exists()) {
                $this->line("Skipping {$school->name} ({$school->slug}): no scores yet.");
                $tenants->forget();

                continue;
            }

            $currentAverage = $this->overallPercentage($school, $results);
            if ($currentAverage === null || $currentAverage <= 0) {
                $this->line("Skipping {$school->name} ({$school->slug}): could not compute a current average.");
                $tenants->forget();

                continue;
            }

            $factor = $target / $currentAverage;
            if ($factor <= 1.001) {
                $this->line("Skipping {$school->name} ({$school->slug}): already at or above {$target}% ({$currentAverage}%).");
                $tenants->forget();

                continue;
            }

            $maxByComponent = AssessmentComponent::pluck('max_score', 'id');

            Score::orderBy('id')->chunkById(500, function ($scores) use ($maxByComponent, $factor) {
                DB::connection('tenant')->transaction(function () use ($scores, $maxByComponent, $factor) {
                    foreach ($scores as $score) {
                        $max = (float) ($maxByComponent[$score->assessment_component_id] ?? $score->score);
                        $rescaled = min($max, round(((float) $score->score) * $factor));
                        if ($rescaled != $score->score) {
                            $score->forceFill(['score' => $rescaled])->save();
                        }
                    }
                });
            });

            $newAverage = $this->overallPercentage($school, $results);
            $this->line("{$school->name} ({$school->slug}): {$currentAverage}% -> {$newAverage}% (factor ".round($factor, 3).')');
            $tenants->forget();
        }

        return self::SUCCESS;
    }

    /** The average subject percentage across every term this school has scores in, not just the current one. */
    protected function overallPercentage(School $school, ResultService $results): ?float
    {
        $termIds = Score::distinct()->pluck('term_id');
        $perTerm = $termIds->map(fn ($termId) => $results->termAveragePercentage($termId))->filter();

        return $perTerm->isEmpty() ? null : round($perTerm->avg(), 1);
    }
}
