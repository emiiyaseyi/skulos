<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtEssayGrade;
use App\Models\Tenant\CbtGrader;
use App\Models\Tenant\CbtResponse;
use App\Models\Tenant\CbtSession;

/**
 * Records a human mark against one written answer.
 *
 * Every save appends a CbtEssayGrade row (who, which layer, what
 * score, what feedback) and never overwrites an earlier one — that
 * history is what the grade-history popup shows and what lets a school
 * answer "what did the first marker give?" months later.
 *
 * LAYER RECONCILIATION — a deliberate departure from the reference.
 * There, layer 1 and layer 2 both write the live score directly, so
 * whoever saved last wins, even if that is the first marker revisiting
 * an answer after the moderator already reviewed it. Here the live
 * score is always recomputed from the history with an explicit rule:
 * the most recent LAYER-2 mark wins if one exists, otherwise the most
 * recent layer-1 mark. A reviewer's decision therefore stands because
 * it is a review, not because of save ordering.
 */
class EssayGradingService
{
    public function grade(CbtResponse $response, int $graderUserId, int $layer, float $score, ?string $feedback = null): void
    {
        $maxScore = $this->maxScoreFor($response);
        abort_if($score < 0 || $score > $maxScore, 422, "Score must be between 0 and {$maxScore}.");

        CbtEssayGrade::create([
            'response_id' => $response->id,
            'grader_user_id' => $graderUserId,
            'grade_layer' => $layer,
            'score' => $score,
            'feedback' => $feedback,
        ]);

        $this->applyResolvedScore($response, $graderUserId);
    }

    /** The marks this question is worth on the assessment the response belongs to. */
    public function maxScoreFor(CbtResponse $response): float
    {
        $session = $response->session()->with('assessment')->first();

        return $session?->assessment?->pointsFor($response->question) ?? (float) $response->question->points;
    }

    /**
     * Recomputes the live score from the append-only history, applying
     * the layer rule above, then re-checks whether the whole session
     * can now be finalised.
     */
    protected function applyResolvedScore(CbtResponse $response, int $graderUserId): void
    {
        $winning = CbtEssayGrade::where('response_id', $response->id)
            ->where('grade_layer', CbtGrader::LAYER_REVIEW)
            ->latest('id')->first()
            ?? CbtEssayGrade::where('response_id', $response->id)
                ->where('grade_layer', CbtGrader::LAYER_FIRST)
                ->latest('id')->first();

        if (! $winning) {
            return;
        }

        $maxScore = $this->maxScoreFor($response);

        $response->forceFill([
            'score_awarded' => $winning->score,
            'is_correct' => $maxScore > 0 && (float) $winning->score >= $maxScore,
            'status' => CbtResponse::STATUS_GRADED,
            'graded_by_user_id' => $winning->grader_user_id,
            'graded_at' => now(),
            'notes' => $winning->feedback,
        ])->save();

        $response->session?->finalizeIfFullyGraded();
    }

    /** Full history for one answer, newest first — what the grade-history popup renders. */
    public function historyFor(CbtResponse $response)
    {
        return CbtEssayGrade::where('response_id', $response->id)->orderByDesc('id')->get();
    }

    /**
     * Clears every human mark on a session and returns its written
     * answers to ungraded. Used when an attempt has to be re-marked
     * from scratch; the session drops back out of "graded" so it
     * cannot silently keep a stale total.
     */
    public function resetGrades(CbtSession $session): int
    {
        $responses = $session->responses()->with('question')->get()
            ->filter(fn (CbtResponse $r) => ! $r->question->isObjective());

        foreach ($responses as $response) {
            CbtEssayGrade::where('response_id', $response->id)->delete();
            $response->forceFill([
                'score_awarded' => 0,
                'is_correct' => null,
                'status' => CbtResponse::STATUS_UNGRADED,
                'graded_by_user_id' => null,
                'graded_at' => null,
                'notes' => null,
            ])->save();
        }

        if ($responses->isNotEmpty() && $session->status === CbtSession::STATUS_GRADED) {
            $session->forceFill(['status' => CbtSession::STATUS_SUBMITTED, 'total_score' => null])->save();
        }

        return $responses->count();
    }

    /**
     * Voids an attempt outright: it keeps its record but scores zero
     * and is marked graded, so it stops blocking a grading queue.
     */
    public function voidAttempt(CbtSession $session, int $byUserId, ?string $reason = null): void
    {
        $session->responses()->update([
            'score_awarded' => 0,
            'status' => CbtResponse::STATUS_GRADED,
            'graded_by_user_id' => $byUserId,
            'graded_at' => now(),
        ]);

        $session->forceFill([
            'status' => CbtSession::STATUS_GRADED,
            'total_score' => 0,
            'suspended_reason' => $reason,
        ])->save();
    }
}
