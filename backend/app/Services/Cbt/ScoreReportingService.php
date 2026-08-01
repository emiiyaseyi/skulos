<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtEssayGrade;
use App\Models\Tenant\CbtResponse;
use App\Models\Tenant\CbtSession;
use Illuminate\Support\Collection;

/**
 * Turns raw attempts into the figures the reporting screens show:
 * per-question state pills, and a taker's FINAL grade across several
 * attempts under the assessment's own grade method.
 *
 * The grade method matters and is easy to get wrong: an assessment
 * that allows three attempts has three scores but only one grade, and
 * which one counts is a policy decision (highest / average / first /
 * last), not an implementation detail. Every screen asks here rather
 * than picking its own, so Scores, Results and the Score Matrix can
 * never disagree about what someone actually got.
 */
class ScoreReportingService
{
    /** Per-question pill states, matching the reference's own legend. */
    public const STATE_FULL = 'full';        // full marks

    public const STATE_PARTIAL = 'partial';  // some marks

    public const STATE_ZERO = 'zero';        // answered, no marks

    public const STATE_REQUIRES = 'rg';      // written answer awaiting a human

    public const STATE_NOT_ANSWERED = 'na';  // left blank

    public const STATE_LABELS = [
        self::STATE_FULL => 'Full marks',
        self::STATE_PARTIAL => 'Partial',
        self::STATE_ZERO => 'Zero / wrong',
        self::STATE_REQUIRES => 'Requires grading',
        self::STATE_NOT_ANSWERED => 'Not answered',
    ];

    /**
     * One pill per question the taker was given, in their own order.
     *
     * @return Collection<int, array{label: string, state: string, score: float, max: float, grader: ?string}>
     */
    public function pillsFor(CbtSession $session, array $graderNames = []): Collection
    {
        $given = app(QuestionSetBuilder::class)->questionsFor($session);
        $responses = $session->responses()->get()->keyBy('question_id');

        // Who actually marked each written answer, for the grader name
        // the reference prints under a graded pill.
        $latestGrades = CbtEssayGrade::whereIn('response_id', $responses->pluck('id'))
            ->orderByDesc('id')->get()->unique('response_id')->keyBy('response_id');

        return $given->values()->map(function ($row, $index) use ($responses, $latestGrades, $graderNames) {
            $response = $responses->get($row->question_id);
            $max = (float) $row->points;
            $score = (float) ($response->score_awarded ?? 0);

            $state = match (true) {
                $response === null => self::STATE_NOT_ANSWERED,
                $response->status === CbtResponse::STATUS_UNGRADED => self::STATE_REQUIRES,
                $this->isBlank($response->answer) => self::STATE_NOT_ANSWERED,
                $max > 0 && $score >= $max => self::STATE_FULL,
                $score > 0 => self::STATE_PARTIAL,
                default => self::STATE_ZERO,
            };

            $graderId = $response ? ($latestGrades->get($response->id)?->grader_user_id) : null;

            return [
                'label' => 'Q'.($index + 1),
                'state' => $state,
                'score' => $score,
                'max' => $max,
                'grader' => $graderId ? ($graderNames[$graderId] ?? null) : null,
            ];
        });
    }

    protected function isBlank(mixed $answer): bool
    {
        if ($answer === null) {
            return true;
        }
        if (is_string($answer)) {
            return trim($answer) === '';
        }
        if (is_array($answer)) {
            return $answer === [];
        }

        return false;
    }

    /**
     * The single grade that counts for one taker on one assessment,
     * resolved by the assessment's own grade method.
     *
     * Only FINISHED attempts count. A suspended attempt has no score
     * by design, and an in-progress one is not a result yet — counting
     * either would understate someone's grade.
     *
     * @return array{score: ?float, max: ?float, attempts: int, method: string}
     */
    public function finalGrade(CbtAssessment $assessment, string $takeableType, int $takeableId): array
    {
        $finished = CbtSession::where('assessment_id', $assessment->id)
            ->where('takeable_type', $takeableType)
            ->where('takeable_id', $takeableId)
            ->whereIn('status', [CbtSession::STATUS_SUBMITTED, CbtSession::STATUS_AUTO_SUBMITTED, CbtSession::STATUS_GRADED])
            ->whereNotNull('total_score')
            ->orderBy('id')->get();

        if ($finished->isEmpty()) {
            return ['score' => null, 'max' => null, 'attempts' => 0, 'method' => $assessment->grade_method];
        }

        $chosen = match ($assessment->grade_method) {
            'average' => null, // averaged below rather than picking one row
            'first' => $finished->first(),
            'last' => $finished->last(),
            default => $finished->sortByDesc(fn ($s) => (float) $s->total_score)->first(), // highest
        };

        if ($assessment->grade_method === 'average') {
            return [
                'score' => round($finished->avg(fn ($s) => (float) $s->total_score), 2),
                // Max comes from the attempts themselves, since a
                // randomised paper's total can differ per attempt.
                'max' => round($finished->avg(fn ($s) => (float) $s->max_score), 2),
                'attempts' => $finished->count(),
                'method' => 'average',
            ];
        }

        return [
            'score' => (float) $chosen->total_score,
            'max' => (float) $chosen->max_score,
            'attempts' => $finished->count(),
            'method' => $assessment->grade_method,
        ];
    }

    /** Whether a final grade clears the assessment's pass mark, or null when no pass mark is set. */
    public function passed(CbtAssessment $assessment, ?float $score): ?bool
    {
        if ($assessment->pass_mark === null || $score === null) {
            return null;
        }

        return $score >= (float) $assessment->pass_mark;
    }

    public function percentage(?float $score, ?float $max): ?float
    {
        if ($score === null || ! $max) {
            return null;
        }

        return round(($score / $max) * 100, 1);
    }
}
