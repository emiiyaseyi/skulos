<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtSession;
use App\Models\Tenant\Score;
use App\Models\Tenant\Student;
use App\Models\User;

/**
 * Lets a class assessment's graded score land in the school's real
 * gradebook (the same `scores` table School\CbtAssessmentController's
 * own docblock and ExaminationController::save() already write to) —
 * user directive 2026-08-02: "class teacher and subject teacher can
 * decide that the assessment should be used for a continuous
 * assessment or examination".
 *
 * Deliberately narrow: only ever applies to a `CbtAssessment` with
 * `assessment_component_id` set (audience=student, subject_id and
 * term_id both present too — enforced at save time in
 * CbtAssessmentController::validatedAssessment(), not re-checked here
 * beyond a defensive guard). Every other assessment (unlinked class
 * assessments, every teacher- and applicant-audience assessment) is
 * completely untouched by this class.
 *
 * The written score is SCALED from the CBT paper's own point total to
 * the component's own max_score — a paper's own total (e.g. out of 25
 * questions) has no reason to match whatever ceiling the school gave
 * that CA/Exam component (e.g. 20).
 */
class GradebookSyncService
{
    /**
     * Whether this session is a real candidate to sync right now: fully
     * graded, not already synced, its assessment actually links a
     * component with a subject and term, and the taker resolves to a
     * real Student (the only takeable type a linked assessment's
     * audience=student requirement ever produces).
     */
    public function syncable(CbtSession $session): bool
    {
        if ($session->status !== CbtSession::STATUS_GRADED || $session->gradebook_synced_at !== null) {
            return false;
        }

        $assessment = $session->assessment;
        if (! $assessment || ! $assessment->assessment_component_id || ! $assessment->subject_id || ! $assessment->term_id) {
            return false;
        }

        return $this->studentFor($session) !== null;
    }

    /**
     * Writes (or overwrites) the Score row this session's grade maps
     * to, then stamps gradebook_synced_at. Idempotent — safe to call
     * again on an already-synced session (a manual re-push after a
     * regrade re-runs the same updateOrCreate with the freshest score).
     */
    public function sync(CbtSession $session): void
    {
        $assessment = $session->assessment;
        $student = $this->studentFor($session);
        abort_unless($student, 422, 'This session cannot be synced to the gradebook.');

        $component = $assessment->assessmentComponent;
        $scaled = $this->scaledScore($session, $component->max_score);

        Score::updateOrCreate(
            [
                'student_id' => $student->id,
                'subject_id' => $assessment->subject_id,
                'term_id' => $assessment->term_id,
                'assessment_component_id' => $component->id,
            ],
            [
                'class_arm_id' => $student->current_class_arm_id,
                'score' => $scaled,
            ]
        );

        $session->forceFill(['gradebook_synced_at' => now()])->save();
    }

    /**
     * Auto-sync hook, called from wherever a session actually becomes
     * STATUS_GRADED (CbtSessionService's own finalize path for an
     * all-objective assessment, and essay grading's finalize path once
     * the last subjective answer is marked) — a no-op for anything not
     * syncable or not in 'auto' mode, so it is always safe to call
     * unconditionally at both of those points.
     */
    public function syncIfAutomatic(CbtSession $session): void
    {
        if ($session->assessment?->gradebook_sync_mode !== 'auto') {
            return;
        }
        if (! $this->syncable($session)) {
            return;
        }
        $this->sync($session);
    }

    /** What sync() WOULD write, without writing it — the manual-review tab's own preview column. Null if not syncable at all. */
    public function previewScore(CbtSession $session): ?float
    {
        if (! $session->assessment?->assessmentComponent) {
            return null;
        }

        return $this->scaledScore($session, (float) $session->assessment->assessmentComponent->max_score);
    }

    protected function studentFor(CbtSession $session): ?Student
    {
        if ($session->takeable_type !== User::class) {
            return null;
        }

        return Student::where('user_id', $session->takeable_id)->first();
    }

    protected function scaledScore(CbtSession $session, float $componentMaxScore): float
    {
        $total = (float) ($session->total_score ?? 0);
        $max = (float) ($session->max_score ?? 0);
        if ($max <= 0) {
            return 0.0;
        }

        return round(($total / $max) * $componentMaxScore, 2);
    }
}
