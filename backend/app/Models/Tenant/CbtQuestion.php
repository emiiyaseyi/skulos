<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One question in the school's own question bank, shaped after the
 * reference repo's `learnguard_qbank`.
 *
 * Answer options live in `cbt_question_answers` (one row each, with a
 * `fraction` for partial credit and its own `feedback`), NOT in a JSON
 * column — the legacy `options`/`correct_answer` columns from Phase 2a
 * are migrated into real answer rows and are no longer read.
 *
 * Per type, the answer rows mean:
 * - multiple_choice_single: one row per option; exactly one has fraction 1
 * - multiple_choice_multiple: one row per option; each correct one carries
 *   a share of the credit (they need not sum to exactly 1)
 * - true_false: exactly two rows, "True" and "False", one with fraction 1
 * - short_answer: one row per accepted answer, each with its own fraction
 *   (so a fully correct and a partially acceptable spelling can coexist)
 * - matching: one row per left-hand stem; `answer_text` is the stem and
 *   `feedback` holds its correct right-hand match
 * - essay: no rows at all, always manually graded (see isObjective())
 */
class CbtQuestion extends Model
{
    use Auditable;

    public const TYPES = [
        'multiple_choice_single', 'multiple_choice_multiple', 'true_false',
        'short_answer', 'essay', 'matching',
    ];

    protected $connection = 'tenant';

    protected $fillable = [
        'category_id', 'name', 'type', 'prompt', 'points', 'image_path', 'is_active',
        'shuffle_answers', 'general_feedback', 'marking_guidance', 'case_sensitive', 'is_mandatory',
        // Legacy Phase 2a columns, still fillable so the data migration
        // that reads them can run; never read at runtime any more.
        'options', 'correct_answer', 'category',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'correct_answer' => 'array',
            'points' => 'decimal:2',
            'is_active' => 'boolean',
            'shuffle_answers' => 'boolean',
            'case_sensitive' => 'boolean',
            'is_mandatory' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CbtQuestionCategory::class, 'category_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(CbtQuestionAnswer::class, 'question_id')->orderBy('sort_order');
    }

    /** Essay is the only type never auto-graded; everything else has real answer rows to compare against. */
    public function isObjective(): bool
    {
        return $this->type !== 'essay';
    }

    /**
     * Returns the fraction of this question's marks earned, 0..1, or
     * null for a type that is never auto-graded (essay) / an unanswered
     * question. A fraction rather than a bool because the answer rows
     * carry partial credit — matching the reference repo's own
     * `learnguard_responses.fraction`.
     */
    public function gradeFraction(mixed $answer): ?float
    {
        if (! $this->isObjective() || $answer === null) {
            return null;
        }

        $answers = $this->relationLoaded('answers') ? $this->answers : $this->answers()->get();

        return match ($this->type) {
            'multiple_choice_single', 'true_false' => $this->gradeSingle($answers, $answer),
            'short_answer' => $this->gradeShortAnswer($answers, $answer),
            'multiple_choice_multiple' => $this->gradeMultiple($answers, (array) $answer),
            'matching' => $this->gradeMatching($answers, (array) $answer),
            default => null,
        };
    }

    protected function gradeSingle($answers, mixed $answer): float
    {
        $picked = $this->resolveOption($answers, $answer);

        return $picked ? max(0.0, min(1.0, (float) $picked->fraction)) : 0.0;
    }

    /**
     * Resolves one submitted value to an answer row. A submitted value
     * is an answer row ID (that is what the take screen posts); the
     * answer_text fallback exists only for responses recorded before
     * the bank moved to real answer rows, and is applied ONLY when the
     * value matches no row id on this question.
     *
     * That ordering matters and is not cosmetic: with options literally
     * named "2", "3", "4", a plain text-or-id match lets the option
     * TEXT "3" collide with a different option's row ID 3, silently
     * scoring an unpicked answer as picked. Caught by
     * CbtQuestionBankTest::test_a_multi_answer_question_awards_partial_credit.
     */
    protected function resolveOption($answers, mixed $value)
    {
        $byId = $answers->firstWhere('id', (int) $value);
        if ($byId) {
            return $byId;
        }

        $matchesSomeId = $answers->contains(fn ($a) => (string) $a->id === (string) $value);

        return $matchesSomeId ? null : $answers->first(fn ($a) => (string) $a->answer_text === (string) $value);
    }

    protected function gradeShortAnswer($answers, mixed $answer): float
    {
        if (! is_string($answer)) {
            return 0.0;
        }
        $needle = $this->case_sensitive ? trim($answer) : mb_strtolower(trim($answer));

        foreach ($answers as $option) {
            $candidate = $this->case_sensitive
                ? trim((string) $option->answer_text)
                : mb_strtolower(trim((string) $option->answer_text));
            if ($candidate === $needle) {
                return max(0.0, min(1.0, (float) $option->fraction));
            }
        }

        return 0.0;
    }

    /**
     * Sums the credit of every correct option the taker picked, then
     * subtracts the credit of each wrong one — the standard "no free
     * marks for ticking everything" rule. Floored at 0, capped at 1.
     */
    protected function gradeMultiple($answers, array $picked): float
    {
        $correctCount = max(1, $answers->filter(fn ($a) => $a->fraction > 0)->count());
        $total = 0.0;

        // Resolve each SUBMITTED value to at most one row (see
        // resolveOption()) rather than asking each row "was I picked?",
        // which is what allowed an option's text to collide with a
        // different option's id.
        $pickedRows = collect($picked)
            ->map(fn ($value) => $this->resolveOption($answers, $value))
            ->filter()->unique('id');

        foreach ($pickedRows as $option) {
            $total += $option->fraction > 0 ? (float) $option->fraction : -(1 / $correctCount);
        }

        return max(0.0, min(1.0, $total));
    }

    /** One answer row per left-hand stem; its `feedback` holds that stem's correct right-hand match. */
    protected function gradeMatching($answers, array $submitted): float
    {
        if ($answers->isEmpty()) {
            return 0.0;
        }

        $correct = 0;
        foreach ($answers as $index => $option) {
            $given = $submitted[$option->id] ?? $submitted[$index] ?? null;
            if ($given !== null && (string) $given === (string) $option->feedback) {
                $correct++;
            }
        }

        return $correct / $answers->count();
    }
}
