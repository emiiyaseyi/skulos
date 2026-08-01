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
                'pool_id' => $row['pool_id'] ?? null,
                // Frozen alongside the question so the sections the
                // taker actually saw survive a pool being renamed.
                'section_label' => $row['section_label'] ?? null,
                'section_order' => $row['section_order'] ?? 0,
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

        // 1. Fixed questions. These sit in section 0, before any pool,
        //    and are unlabelled unless pools exist to contrast with.
        foreach ($assessment->assessmentQuestions as $aq) {
            if (! $aq->question) {
                continue;
            }
            $set[$aq->question_id] = [
                'question_id' => $aq->question_id,
                'points' => (float) ($aq->points_override ?? $aq->question->points),
                'mandatory' => (bool) $aq->question->is_mandatory,
                'pool_id' => null,
                'section_label' => $assessment->pools->isNotEmpty() ? 'General' : null,
                'section_order' => 0,
            ];
        }

        // 2. Pool draws, skipping anything already fixed so a question
        //    can never appear twice on the same paper. Each pool is its
        //    own SECTION, named for the pool or its category, so a
        //    paper drawing from several banks reads as several parts.
        foreach ($assessment->pools as $poolIndex => $pool) {
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

            $label = $pool->label ?: ($pool->category?->name ?? 'Section '.($poolIndex + 1));

            foreach ($take as $questionId) {
                $set[$questionId] = [
                    'question_id' => $questionId,
                    'points' => (float) $pool->points_each,
                    'mandatory' => (bool) $candidates[$questionId],
                    'pool_id' => $pool->id,
                    'section_label' => $label,
                    'section_order' => $poolIndex + 1,
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

        // 4. Order. Shuffling happens WITHIN each section, never
        //    across them: a paper that announces "Algebra" then
        //    "Geometry" must not interleave the two, or the section
        //    headings would be meaningless. Sections themselves stay
        //    in the order the pools were configured.
        $bySection = [];
        foreach ($rows as $row) {
            $bySection[$row['section_order']][] = $row;
        }
        ksort($bySection);

        $ordered = [];
        foreach ($bySection as $sectionRows) {
            if ($assessment->shuffle_questions) {
                $this->seededShuffle($sectionRows);
            }
            foreach ($sectionRows as $row) {
                $ordered[] = $row;
            }
        }

        mt_srand();

        return array_values($ordered);
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

    /**
     * The score/max pair a session's own total_score/max_score is set
     * from, honouring the assessment's score_aggregation policy:
     *
     *   sum               every question's own points, added together
     *                     (the default, and what "X out of Y marks"
     *                     ordinarily means)
     *   average_sections  each section (question bank) is marked out of
     *                     its own total first, then every section's
     *                     percentage counts equally toward the final
     *                     score — a 5-question bank and a 50-question
     *                     bank end up weighted the same, so one large
     *                     bank cannot quietly dominate the result just
     *                     by having more questions in it. Reported out
     *                     of 100, since "out of" no longer means marks.
     *
     * Callable at any point in grading: an ungraded essay's
     * score_awarded is simply still 0/null, so this is safe to call
     * both for the provisional max_score set at submit() and the final
     * total_score set once grading completes.
     *
     * @return array{score: float, max: float}
     */
    public function scoreTotalsFor(CbtSession $session): array
    {
        $given = $this->questionsFor($session);
        $responses = $session->responses()->get()->keyBy('question_id');
        $score = fn ($rows) => (float) $rows->sum(fn ($row) => (float) ($responses->get($row->question_id)?->score_awarded ?? 0));

        $assessment = $session->assessment ?? $session->assessment()->first();
        $bySection = $given->groupBy('section_order');

        // 'sum' mode, or a paper with only one section (nothing to
        // average against) — plain totals, unchanged from the original
        // behaviour.
        if (($assessment?->score_aggregation ?? CbtAssessment::SCORE_SUM) !== CbtAssessment::SCORE_AVERAGE_SECTIONS
            || $bySection->count() <= 1) {
            return ['score' => round($score($given), 2), 'max' => round((float) $given->sum('points'), 2)];
        }

        $percentages = $bySection->map(function ($rows) use ($score) {
            $sectionMax = (float) $rows->sum('points');

            return $sectionMax > 0 ? ($score($rows) / $sectionMax) * 100 : 0.0;
        });

        return ['score' => round($percentages->avg(), 2), 'max' => 100.0];
    }
}
