<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtResponse;
use App\Models\Tenant\CbtSession;
use Illuminate\Database\Eloquent\Model;

/**
 * Starts a timed session for any "takeable" model (School\User today,
 * Applicant later — see CbtSession's own docblock), saves answers as a
 * taker works through the assessment, and grades on submit (objective
 * types immediately via CbtQuestion::gradeFraction() against the real
 * cbt_question_answers rows — essay left for a human/moderation queue).
 */
class CbtSessionService
{
    /** Resumes an already-in-progress attempt if one exists, otherwise starts a fresh one — never two concurrent in-progress sessions for the same taker+assessment. */
    public function start(Model $taker, CbtAssessment $assessment, ?string $ip = null, ?string $userAgent = null): CbtSession
    {
        $existing = CbtSession::where('assessment_id', $assessment->id)
            ->where('takeable_type', $taker::class)
            ->where('takeable_id', $taker->getKey())
            ->where('status', CbtSession::STATUS_IN_PROGRESS)
            ->first();

        if ($existing) {
            return $existing;
        }

        return CbtSession::create([
            'assessment_id' => $assessment->id,
            'takeable_type' => $taker::class,
            'takeable_id' => $taker->getKey(),
            'started_at' => now(),
            'ends_at' => now()->addMinutes($assessment->duration_minutes),
            'ip_address' => $ip,
            'browser_info' => $userAgent ? \Illuminate\Support\Str::limit($userAgent, 250, '') : null,
        ]);
    }

    public function saveAnswer(CbtSession $session, int $questionId, mixed $answer): void
    {
        CbtResponse::updateOrCreate(
            ['session_id' => $session->id, 'question_id' => $questionId],
            ['answer' => $answer]
        );
    }

    /**
     * Grades every objective response, computes what CAN be known yet,
     * and marks the session submitted. A fully-objective assessment
     * reaches `graded` immediately here; one with any essay stays
     * `submitted`/`auto_submitted` until a human grades it.
     */
    public function submit(CbtSession $session, string $status = CbtSession::STATUS_SUBMITTED): void
    {
        if ($session->status !== CbtSession::STATUS_IN_PROGRESS) {
            return; // already submitted once — never re-process
        }

        $assessment = $session->assessment()->with('assessmentQuestions.question.answers')->first();
        $existingResponses = $session->responses()->get()->keyBy('question_id');

        // Every question on the assessment gets a response row, even one
        // the taker never touched (answer stays null) — finalizeIfFullyGraded()
        // only ever sees a question again through its response ROW, so a
        // skipped essay with no row at all would otherwise be invisible
        // to that check and silently let the session "finish" grading
        // without it.
        foreach ($assessment->assessmentQuestions as $assessmentQuestion) {
            $question = $assessmentQuestion->question;
            $response = $existingResponses->get($question->id)
                ?? CbtResponse::create(['session_id' => $session->id, 'question_id' => $question->id, 'answer' => null]);

            if (! $question->isObjective()) {
                continue; // essay: stays ungraded, a human's grading queue picks it up
            }

            $fraction = $question->gradeFraction($response->answer) ?? 0.0;
            $points = $assessment->pointsFor($question);

            $response->forceFill([
                'is_correct' => $fraction >= 1.0,
                'score_awarded' => round($fraction * $points, 2),
                'status' => CbtResponse::STATUS_AUTO_GRADED,
            ])->save();
        }

        $session->forceFill([
            'submitted_at' => now(),
            'status' => $status,
            'max_score' => $assessment->totalPoints(),
        ])->save();

        $session->finalizeIfFullyGraded();
    }
}
