<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Score;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentTermRemark;
use App\Models\Tenant\SubjectRemark;
use App\Models\Tenant\Term;
use App\Services\Results\CommentBank;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Populates every real student with live scores and comments across every
 * term of every session (user request 2026-07-12: "develop everything
 * needed for the report card, the grading system and also populate each
 * student with live scores, comments etc", extended 2026-07-13: "the real
 * scores should be for all the terms as i want two sessions per school"),
 * so the report card review/approval workflow, and its cumulative
 * previous-terms view, both have real data to actually exercise.
 *
 * Deliberately never touches `ReportCardReview` (the user's explicit
 * instruction: "leave some for us to review the workflow, approval
 * etc"), this command only ever writes `AcademicSession`/`Term` (to
 * reach two sessions, if a school only has one), `Score`,
 * `SubjectRemark`, and `StudentTermRemark.class_teacher_remark` rows,
 * nothing that advances a report card through review.
 *
 * Safe to re-run: a subject that already has any Score row for a
 * student in a given term is left completely alone (skipped whole, not
 * topped up component by component), so this never overwrites a real
 * teacher's already-entered marks, only fills in what is genuinely
 * blank. Same "only if blank" rule for SubjectRemark and
 * StudentTermRemark.class_teacher_remark. A school already at two (or
 * more) sessions is left as-is, only a school with exactly one gets a
 * second added.
 *
 * A student's subjects are always read from their *current* class arm,
 * for every term including past ones: nothing in the product tracks a
 * student's historical class placement per session (`Enrollment` exists
 * as a model but nothing anywhere creates or reads it yet), so this is
 * the same simplification the rest of the app already makes.
 */
class PopulateStudentScores extends Command
{
    protected $signature = 'schools:populate-scores {school? : A specific school slug, otherwise every provisioned school}';

    protected $description = 'Populate every student with realistic live scores and comments across every term of every session, leaving the report card review workflow untouched';

    public function handle(TenantManager $tenants, CommentBank $commentBank): int
    {
        $slug = $this->argument('school');

        $schools = $slug
            ? School::where('slug', $slug)->whereNotNull('database')->whereNotNull('provisioned_at')->get()
            : School::whereNotNull('database')->whereNotNull('provisioned_at')->get();

        if ($schools->isEmpty()) {
            $this->error($slug ? "No provisioned school found for slug \"{$slug}\"." : 'No provisioned schools found.');

            return self::FAILURE;
        }

        foreach ($schools as $school) {
            $tenants->use($school);

            $this->ensureTwoSessions($school);

            $terms = Term::with('session')->get()
                ->sortBy(fn (Term $t) => $t->session?->name.'-'.$t->sequence)
                ->values();

            if ($terms->isEmpty()) {
                $this->line("Skipping {$school->name} ({$school->slug}): no terms set up.");
                $tenants->forget();

                continue;
            }

            $components = AssessmentComponent::orderBy('sequence')->get();
            if ($components->isEmpty()) {
                $this->line("Skipping {$school->name} ({$school->slug}): no assessment components configured.");
                $tenants->forget();

                continue;
            }

            $this->line("Populating {$school->name} ({$school->slug}), {$terms->count()} term(s) across {$terms->pluck('session.name')->unique()->count()} session(s)...");

            $scoresWritten = 0;
            $remarksWritten = 0;
            $termRemarksWritten = 0;

            foreach ($terms as $term) {
                DB::connection('tenant')->transaction(function () use ($term, $components, $commentBank, &$scoresWritten, &$remarksWritten, &$termRemarksWritten) {
                    $this->populateTerm($term, $components, $commentBank, $scoresWritten, $remarksWritten, $termRemarksWritten);
                });
            }

            $this->line("  {$scoresWritten} score(s), {$remarksWritten} subject remark(s), {$termRemarksWritten} term remark(s) written.");
            $tenants->forget();
        }

        return self::SUCCESS;
    }

    /** A school with only one session gets a second, historical one added (the session immediately before its existing one), never touching which session is current. */
    protected function ensureTwoSessions(School $school): void
    {
        $sessions = AcademicSession::all();
        if ($sessions->count() >= 2) {
            return;
        }

        $existing = $sessions->first();
        if (! $existing || ! preg_match('/(\d{4})\/(\d{4})/', $existing->name, $m)) {
            $this->line("  Could not derive a second session's year from \"{$existing?->name}\", skipping session creation for {$school->slug}.");

            return;
        }

        $priorStart = (int) $m[1] - 1;
        $yearLabel = "{$priorStart}/".($priorStart + 1);
        $name = "{$yearLabel} Session";

        if (AcademicSession::where('name', $name)->exists()) {
            return;
        }

        $session = AcademicSession::create(['name' => $name]);
        foreach (['First' => 1, 'Second' => 2, 'Third' => 3] as $ordinal => $sequence) {
            Term::create([
                'academic_session_id' => $session->id,
                'name' => "{$ordinal} Term {$yearLabel}",
                'sequence' => $sequence,
            ]);
        }

        $this->line("  Added a second session, {$name}, for {$school->slug}.");
    }

    protected function populateTerm(Term $term, $components, CommentBank $commentBank, int &$scoresWritten, int &$remarksWritten, int &$termRemarksWritten): void
    {
        foreach (ClassArm::with('schoolClass.subjects')->get() as $arm) {
            $subjects = $arm->schoolClass?->subjects ?? collect();
            if ($subjects->isEmpty()) {
                continue;
            }

            $students = Student::active()->where('current_class_arm_id', $arm->id)->get();

            foreach ($students as $student) {
                // A stable per (student, term) ability, seeded from the student
                // and term ids so the same run produces a consistent spread
                // across terms for one student (some variation term to term,
                // not a full reroll), without needing to persist anything.
                mt_srand($student->id * 1000 + $term->id);
                $ability = mt_rand(35, 92);
                $subjectTotals = [];

                foreach ($subjects as $subject) {
                    $alreadyScored = Score::where('student_id', $student->id)
                        ->where('subject_id', $subject->id)
                        ->where('term_id', $term->id)
                        ->exists();
                    if ($alreadyScored) {
                        continue;
                    }

                    $subjectAbility = max(15, min(98, $ability + mt_rand(-8, 8)));
                    $total = 0.0;

                    foreach ($components as $component) {
                        $max = (float) $component->max_score;
                        $raw = $max * ($subjectAbility / 100) + mt_rand(-2, 2);
                        $score = max(0, min($max, round($raw)));

                        Score::create([
                            'student_id' => $student->id,
                            'subject_id' => $subject->id,
                            'class_arm_id' => $arm->id,
                            'term_id' => $term->id,
                            'assessment_component_id' => $component->id,
                            'score' => $score,
                        ]);
                        $scoresWritten++;
                        $total += $score;
                    }

                    $subjectTotals[] = $total;

                    $hasRemark = SubjectRemark::where('student_id', $student->id)
                        ->where('subject_id', $subject->id)
                        ->where('term_id', $term->id)
                        ->exists();
                    $suggestion = $commentBank->suggestionFor($total);
                    if (! $hasRemark && $suggestion) {
                        SubjectRemark::create([
                            'student_id' => $student->id,
                            'subject_id' => $subject->id,
                            'term_id' => $term->id,
                            'class_arm_id' => $arm->id,
                            'remark' => $suggestion,
                        ]);
                        $remarksWritten++;
                    }
                }

                if (empty($subjectTotals)) {
                    continue;
                }

                $existingTermRemark = StudentTermRemark::where('student_id', $student->id)
                    ->where('term_id', $term->id)
                    ->first();
                if ($existingTermRemark && $existingTermRemark->class_teacher_remark) {
                    continue;
                }

                $average = array_sum($subjectTotals) / count($subjectTotals);
                $overallSuggestion = $commentBank->suggestionFor($average);
                if (! $overallSuggestion) {
                    continue;
                }

                StudentTermRemark::updateOrCreate(
                    ['student_id' => $student->id, 'term_id' => $term->id],
                    ['class_teacher_remark' => $overallSuggestion]
                );
                $termRemarksWritten++;
            }
        }
    }
}
