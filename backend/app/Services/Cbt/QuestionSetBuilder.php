<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtQuestion;
use App\Models\Tenant\CbtSession;
use App\Models\Tenant\CbtSessionQuestion;

/**
 * Decides which questions one taker actually sits, and in what order,
 * then freezes that decision into cbt_session_questions.
 *
 * Freezing matters: with randomisation or shuffling on, "the questions
 * on this assessment" is no longer the same thing as "the questions
 * this person was given". Without a stored per-attempt set, a resumed
 * attempt would silently re-roll into a different paper, and a
 * finished one could never be reviewed or re-marked faithfully.
 *
 * Composition, in order:
 *   1. every FIXED question attached to the assessment
 *   2. plus, for each pool, `draw_count` questions drawn at random
 *      from that pool's category
 *   3. if `randomize` is on, the whole set is then reduced to
 *      `num_questions` — except that questions flagged is_mandatory
 *      are always kept, which is the whole point of that flag
 *   4. if `shuffle_questions` is on, the final order is shuffled
 *
 * Randomness is seeded per SESSION, so re-running the build for the
 * same attempt is idempotent and reproducible — important both for
 * resume and for anyone auditing why two takers got different papers.
 */
class QuestionSetBuilder
{
    /**
     * Builds and stores the set for a session, unless one already
     * exists (in which case that stored set is authoritative and is
     * returned untouched).
     *
     * @return \Illuminate\Support\Collection<int, CbtSessionQuestion>
     */
    public function buildFor(CbtSession $session): \Illuminate\Support\Collection
    {
        $existing = CbtSessionQuestion::where('session_id', $session->id)->orderBy('sequence')->get();
        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $assessment = $session->assessment()->with(['assessmentQuestions.question', 'pools'])->first();
        if (! $assessment) {
            return collect();
        }

        $picked = $this->pick($assessment, $session->id);

        foreach ($picked as $sequence => $row) {
            CbtSessionQuestion::create([
                'session_id' => $session->id,
                'question_id' => $row['question_id'],
                'sequence' => $sequence,
                'points' => $row['points'],
            ]);
        }

        return CbtSessionQuestion::where('session_id', $session->id)->orderBy('sequence')->get();
    }

    /**
     * The selection itself, kept pure so it can be reasoned about and
     * tested without writing rows.
     *
     * @return array<int, array{question_id: int, points: float, mandatory: bool}>
     */
    public function pick(CbtAssessment $assessment, int $seed): array
    {
        mt_srand($seed);

        $set = [];

        // 1. Fixed questions.
        foreach ($assessment->assessmentQuestions as $aq) {
            if (! $aq->question) {
                continue;
            }
            $set[$aq->question_id] = [
                'question_id' => $aq->question_id,
                'points' => (float) ($aq->points_override ?? $aq->question->points),
                'mandatory' => (bool) $aq->question->is_mandatory,
            ];
        }

        // 2. Pool draws, skipping anything already fixed so a question
        //    can never appear twice on the same paper.
        foreach ($assessment->pools as $pool) {
            $candidates = CbtQuestion::where('category_id', $pool->category_id)
                ->where('is_active', true)
                ->whereNotIn('id', array_keys($set))
                ->pluck('is_mandatory', 'id')->all();

            if ($candidates === []) {
                continue;
            }

            // Mandatory candidates are taken first; the rest fill the
            // remaining slots at random.
            $mandatoryIds = array_keys(array_filter($candidates));
            $optionalIds = array_keys(array_diff_key($candidates, array_flip($mandatoryIds)));
            $this->seededShuffle($optionalIds);

            $take = array_slice(array_merge($mandatoryIds, $optionalIds), 0, max(0, $pool->draw_count));

            foreach ($take as $questionId) {
                $set[$questionId] = [
                    'question_id' => $questionId,
                    'points' => (float) $pool->points_each,
                    'mandatory' => (bool) $candidates[$questionId],
                ];
            }
        }

        $rows = array_values($set);

        // 3. Reduce to num_questions, always keeping mandatory ones.
        if ($assessment->randomize && $assessment->num_questions > 0 && count($rows) > $assessment->num_questions) {
            $mandatory = array_values(array_filter($rows, fn ($r) => $r['mandatory']));
            $optional = array_values(array_filter($rows, fn ($r) => ! $r['mandatory']));
            $this->seededShuffle($optional);

            $slots = max(0, $assessment->num_questions - count($mandatory));
            // If mandatory questions alone already exceed the requested
            // count, they all stay — a question marked "always include"
            // is not dropped to satisfy a smaller number.
            $rows = array_merge($mandatory, array_slice($optional, 0, $slots));
        }

        // 4. Order.
        if ($assessment->shuffle_questions) {
            $this->seededShuffle($rows);
        }

        mt_srand();

        return array_values($rows);
    }

    /** shuffle() reseeds from the global RNG, which we have already seeded; this keeps that determinism explicit. */
    protected function seededShuffle(array &$items): void
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
    }

    /** The questions a session was actually given, in its own order, with each one's marks. */
    public function questionsFor(CbtSession $session): \Illuminate\Support\Collection
    {
        return CbtSessionQuestion::with('question.answers')
            ->where('session_id', $session->id)
            ->orderBy('sequence')->get();
    }

    public function totalPointsFor(CbtSession $session): float
    {
        return (float) CbtSessionQuestion::where('session_id', $session->id)->sum('points');
    }
}
