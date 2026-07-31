<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtPaper;
use App\Models\Tenant\CbtResponse;
use App\Models\Tenant\CbtSession;
use Illuminate\Database\Eloquent\Model;

/**
 * SKULOS Assessment Hub, Phase 2b: starts a timed session for any
 * "takeable" model (School\User today, Applicant later — see CbtSession's
 * own docblock), saves answers as a taker works through the paper, and
 * grades on submit (objective types immediately, essay left for Phase
 * 2c's manual/double-grading queue).
 */
class CbtSessionService
{
    /** Resumes an already-in-progress attempt if one exists, otherwise starts a fresh one — never two concurrent in-progress sessions for the same taker+paper. */
    public function start(Model $taker, CbtPaper $paper, ?string $ip = null, ?string $userAgent = null): CbtSession
    {
        $existing = CbtSession::where('paper_id', $paper->id)
            ->where('takeable_type', $taker::class)
            ->where('takeable_id', $taker->getKey())
            ->where('status', CbtSession::STATUS_IN_PROGRESS)
            ->first();

        if ($existing) {
            return $existing;
        }

        return CbtSession::create([
            'paper_id' => $paper->id,
            'takeable_type' => $taker::class,
            'takeable_id' => $taker->getKey(),
            'started_at' => now(),
            'ends_at' => now()->addMinutes($paper->duration_minutes),
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
     * and marks the session submitted. A fully-objective paper reaches
     * `graded` immediately here; one with any essay stays `submitted`/
     * `auto_submitted` until Phase 2c's grading queue finishes.
     */
    public function submit(CbtSession $session, string $status = CbtSession::STATUS_SUBMITTED): void
    {
        if ($session->status !== CbtSession::STATUS_IN_PROGRESS) {
            return; // already submitted once — never re-process
        }

        $paper = $session->paper()->with('paperQuestions.question')->first();
        $existingResponses = $session->responses()->get()->keyBy('question_id');

        // Every question on the paper gets a response row, even one the
        // taker never touched (answer stays null) — finalizeIfFullyGraded()
        // only ever sees a question again through its response ROW, so a
        // skipped essay with no row at all would otherwise be invisible
        // to that check and silently let the session "finish" grading
        // without it.
        foreach ($paper->paperQuestions as $paperQuestion) {
            $question = $paperQuestion->question;
            $response = $existingResponses->get($question->id)
                ?? CbtResponse::create(['session_id' => $session->id, 'question_id' => $question->id, 'answer' => null]);

            if (! $question->isObjective()) {
                continue; // essay: stays ungraded, Phase 2c's queue picks it up
            }

            $isCorrect = $question->gradeAnswer($response->answer);
            $points = $paper->pointsFor($question);

            $response->forceFill([
                'is_correct' => $isCorrect,
                'score_awarded' => $isCorrect ? $points : 0,
                'status' => CbtResponse::STATUS_AUTO_GRADED,
            ])->save();
        }

        $session->forceFill([
            'submitted_at' => now(),
            'status' => $status,
            'max_score' => $paper->totalPoints(),
        ])->save();

        $session->finalizeIfFullyGraded();
    }
}
