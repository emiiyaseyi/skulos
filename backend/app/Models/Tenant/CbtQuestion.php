<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One question in the school's own question bank. `options`/
 * `correct_answer` shape depends on `type`:
 *
 * - multiple_choice_single: options = ["A","B","C"], correct_answer = "B"
 * - multiple_choice_multiple: options = ["A","B","C"], correct_answer = ["A","C"]
 * - true_false: options = null, correct_answer = true|false
 * - short_answer: options = null, correct_answer = "the expected text"
 *   (graded via a normalized exact match, see gradeAnswer())
 * - matching: options = {"left":["A","B"],"right":["1","2"]},
 *   correct_answer = {"0":"1","1":"0"} (left-index => right-index, string
 *   keys since JSON object keys are always strings)
 * - essay: options = null, correct_answer = null (always manually graded,
 *   see CbtQuestion::isObjective())
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
        'type', 'prompt', 'options', 'correct_answer', 'points', 'image_path', 'category', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'correct_answer' => 'array',
            'points' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** Essay is the only type with no automatic grading — every other type has a real correct answer to compare against. */
    public function isObjective(): bool
    {
        return $this->type !== 'essay';
    }

    /**
     * Compares a submitted answer against correct_answer. Returns null
     * for essay (never auto-graded) or short_answer once a school wants
     * a human to double check it (not built yet — currently always
     * auto-compared); true/false otherwise.
     */
    public function gradeAnswer(mixed $answer): ?bool
    {
        if (! $this->isObjective() || $answer === null) {
            return null;
        }

        return match ($this->type) {
            'multiple_choice_single' => (string) $answer === (string) $this->correct_answer,
            'true_false' => filter_var($answer, FILTER_VALIDATE_BOOLEAN) === filter_var($this->correct_answer, FILTER_VALIDATE_BOOLEAN),
            'short_answer' => is_string($answer) && mb_strtolower(trim($answer)) === mb_strtolower(trim((string) $this->correct_answer)),
            'multiple_choice_multiple' => $this->sameSet((array) $answer, (array) $this->correct_answer),
            'matching' => $this->sameMap((array) $answer, (array) $this->correct_answer),
            default => null,
        };
    }

    protected function sameSet(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return array_map('strval', $a) === array_map('strval', $b);
    }

    protected function sameMap(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return array_map('strval', $a) === array_map('strval', $b);
    }
}
