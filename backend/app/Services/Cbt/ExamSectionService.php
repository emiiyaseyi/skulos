<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtSession;
use Illuminate\Support\Collection;

/**
 * Groups a taker's frozen paper into the sections they actually see,
 * and works out each section's own deadline.
 *
 * A paper drawing from several question banks is announced bank by
 * bank: the taker is told which section they are entering before its
 * questions begin. Timing is then either
 *
 *   TOTAL        one clock across the whole paper (the default, and
 *                what every existing assessment keeps), or
 *   PER_SECTION  each section gets its own allowance and its own hard
 *                stop, so running long on part one cannot eat part two
 *
 * Under per-section timing the deadlines are cumulative from the
 * attempt's own start, so they are absolute instants rather than
 * durations — the same reasoning as the exam clock itself, which
 * anchors to ends_at rather than counting down a number that drifts.
 */
class ExamSectionService
{
    public const TIMER_TOTAL = 'total';

    public const TIMER_PER_SECTION = 'per_section';

    /**
     * @return Collection<int, array{
     *   order: int, label: ?string, questions: Collection,
     *   first_index: int, last_index: int,
     *   minutes: ?int, ends_at: ?\Illuminate\Support\Carbon
     * }>
     */
    public function sectionsFor(CbtSession $session): Collection
    {
        $given = app(QuestionSetBuilder::class)->questionsFor($session);
        $assessment = $session->assessment;
        $perSection = $this->isPerSection($session);

        // Pool allowances, keyed by pool, for per-section timing.
        $poolMinutes = $assessment?->pools()->pluck('duration_minutes', 'id')->all() ?? [];

        $grouped = $given->values()->groupBy('section_order');
        $cursor = $session->started_at?->copy();
        $index = 0;

        return $grouped->map(function (Collection $questions, $order) use (&$cursor, &$index, $perSection, $poolMinutes) {
            $firstIndex = $index;
            $index += $questions->count();

            $minutes = null;
            $endsAt = null;

            if ($perSection) {
                // A section without its own allowance falls back to an
                // even share of nothing rather than guessing: it simply
                // has no separate stop, and the overall clock applies.
                $poolId = $questions->first()?->pool_id;
                $minutes = $poolId ? ($poolMinutes[$poolId] ?? null) : null;

                if ($minutes && $cursor) {
                    $cursor = $cursor->copy()->addMinutes($minutes);
                    $endsAt = $cursor->copy();
                }
            }

            return [
                'order' => (int) $order,
                'label' => $questions->first()?->section_label,
                'questions' => $questions,
                'first_index' => $firstIndex,
                'last_index' => $index - 1,
                'minutes' => $minutes,
                'ends_at' => $endsAt,
            ];
        })->values();
    }

    public function isPerSection(CbtSession $session): bool
    {
        return ($session->assessment?->timer_mode ?? self::TIMER_TOTAL) === self::TIMER_PER_SECTION;
    }

    /** True once a paper has more than one named section worth announcing. */
    public function hasSections(CbtSession $session): bool
    {
        return $this->sectionsFor($session)->filter(fn ($s) => $s['label'] !== null)->count() > 1;
    }
}
