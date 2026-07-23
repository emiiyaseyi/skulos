<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\BehaviouralTrait;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\Score;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentBehaviouralRating;
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
 * `SubjectRemark`, `StudentTermRemark` (both `class_teacher_remark` and
 * `principal_remark`), and `StudentBehaviouralRating` rows, nothing
 * that advances a report card through review.
 *
 * Safe to re-run: a subject that already has any Score row for a
 * student in a given term is left completely alone (skipped whole, not
 * topped up component by component), so this never overwrites a real
 * teacher's already-entered marks, only fills in what is genuinely
 * blank. Same "only if blank" rule for SubjectRemark,
 * StudentTermRemark's two remark columns (independently, one already
 * set does not block the other from being filled), and
 * StudentBehaviouralRating. A school already at two (or more) sessions
 * is left as-is, only a school with exactly one gets a second added.
 *
 * A student's subjects are always read from ClassArm::offeredSubjects()
 * (2026-07-23: a class arm with its own per-stream subject overrides,
 * SSS's Science & Technology/Arts & Humanities/Commercial streams most
 * commonly, must only ever be scored on its own real subject list, not
 * every subject the whole class collectively teaches across every
 * stream), read from their *current* class arm, for every term
 * including past ones: nothing in the product tracks a student's
 * historical class placement per session (`Enrollment` exists as a
 * model but nothing anywhere creates or reads it yet), so this is the
 * same simplification the rest of the app already makes.
 *
 * Behavioural trait ratings are only ever populated for a trait whose
 * type is "remark" (a free text value like "Excellent"): reused from
 * the same grading scale band the student's own subject average falls
 * into, real school-configured vocabulary, not invented text. A
 * "score" type trait (a number out of the school's own configured max)
 * is left for a real class teacher to enter, no sensible default number
 * to synthesize for it the way a grade band remark already exists for.
 */
class PopulateStudentScores extends Command
{
    protected $signature = 'schools:populate-scores {school? : A specific school slug, otherwise every provisioned school}';

    protected $description = 'Populate every student with realistic live scores, subject/term remarks, and behavioural trait ratings across every term of every session, leaving the report card review workflow untouched';

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
            $traitRatingsWritten = 0;
            $gradingScale = GradingScale::default();
            $traits = BehaviouralTrait::active()->where('type', BehaviouralTrait::TYPE_REMARK)->get();

            foreach ($terms as $term) {
                DB::connection('tenant')->transaction(function () use ($term, $components, $commentBank, $gradingScale, $traits, &$scoresWritten, &$remarksWritten, &$termRemarksWritten, &$traitRatingsWritten) {
                    $this->populateTerm($term, $components, $commentBank, $gradingScale, $traits, $scoresWritten, $remarksWritten, $termRemarksWritten, $traitRatingsWritten);
                });
            }

            $this->line("  {$scoresWritten} score(s), {$remarksWritten} subject remark(s), {$termRemarksWritten} term remark(s), {$traitRatingsWritten} behavioural rating(s) written.");
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

    protected function populateTerm(Term $term, $components, CommentBank $commentBank, ?GradingScale $gradingScale, $traits, int &$scoresWritten, int &$remarksWritten, int &$termRemarksWritten, int &$traitRatingsWritten): void
    {
        foreach (ClassArm::with('schoolClass.subjects', 'subjects')->get() as $arm) {
            $subjects = $arm->offeredSubjects();
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
                // Range raised 2026-07-16 (user directive: "Term Average
                // SHOULD STILL GO HIGHER UP TO 80%"), was mt_rand(35, 92)
                // (averaging ~63), now averages ~80 while keeping enough
                // spread for a genuine low end, Students At Risk still
                // needs some students to actually be at risk.
                $ability = mt_rand(62, 98);
                $subjectTotals = [];

                foreach ($subjects as $subject) {
                    $existingScores = Score::where('student_id', $student->id)
                        ->where('subject_id', $subject->id)
                        ->where('term_id', $term->id)
                        ->get();

                    if ($existingScores->isNotEmpty()) {
                        // A subject already scored (a real teacher's entry,
                        // or a prior run of this command) is never topped
                        // up, but its real total still counts toward this
                        // student's term average below — previously it was
                        // skipped entirely here, silently leaving
                        // $subjectTotals empty (and this student's term
                        // remark/behavioural ratings unfilled) for any
                        // student whose scores were already complete,
                        // found live on greenfield, 2026-07-23: every one
                        // of its 320 students already had full scores, so
                        // this command had been silently doing nothing for
                        // them since the very first run.
                        $total = (float) $existingScores->sum('score');
                    } else {
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

                $average = array_sum($subjectTotals) / count($subjectTotals);
                $existingTermRemark = StudentTermRemark::where('student_id', $student->id)
                    ->where('term_id', $term->id)
                    ->first();

                // class_teacher_remark and principal_remark are filled
                // independently: a student already having one must never
                // block the other from being filled (found live on
                // greenfield, 2026-07-23: 301/320 students already had a
                // class teacher remark from an earlier run of this
                // command, but principal_remark had never been touched at
                // all, 0/320, since the original single combined "already
                // has a remark, skip everything" check treated them as one).
                $termRemarkUpdates = [];
                if (! $existingTermRemark?->class_teacher_remark) {
                    $suggestion = $commentBank->suggestionFor($average);
                    if ($suggestion) {
                        $termRemarkUpdates['class_teacher_remark'] = $suggestion;
                    }
                }
                if (! $existingTermRemark?->principal_remark) {
                    $suggestion = $commentBank->suggestionFor($average);
                    if ($suggestion) {
                        $termRemarkUpdates['principal_remark'] = $suggestion;
                    }
                }
                if ($termRemarkUpdates) {
                    StudentTermRemark::updateOrCreate(
                        ['student_id' => $student->id, 'term_id' => $term->id],
                        $termRemarkUpdates
                    );
                    $termRemarksWritten++;
                }

                if ($gradingScale && $traits->isNotEmpty()) {
                    $band = $gradingScale->bandFor($average);
                    if ($band) {
                        foreach ($traits as $trait) {
                            $existingRating = StudentBehaviouralRating::where('student_id', $student->id)
                                ->where('term_id', $term->id)
                                ->where('behavioural_trait_id', $trait->id)
                                ->exists();
                            if ($existingRating) {
                                continue;
                            }

                            StudentBehaviouralRating::create([
                                'student_id' => $student->id,
                                'term_id' => $term->id,
                                'behavioural_trait_id' => $trait->id,
                                'value' => $band->grade,
                            ]);
                            $traitRatingsWritten++;
                        }
                    }
                }
            }
        }
    }
}
