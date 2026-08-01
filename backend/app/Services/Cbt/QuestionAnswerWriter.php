<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtQuestion;
use App\Models\Tenant\CbtQuestionAnswer;

/**
 * Turns the Question Bank form's per-type inputs into real
 * cbt_question_answers rows (replacing the earlier options/correct_answer
 * JSON pair). See CbtQuestion's own docblock for what the rows mean per
 * type; `fraction` carries partial credit and `feedback` carries either
 * per-option feedback or, for matching, that stem's correct right-hand
 * match.
 */
class QuestionAnswerWriter
{
    /**
     * Replaces every answer row on the question with what the form
     * supplied. Wholesale replace rather than diff/patch: an option list
     * is edited as one block in the UI, so a partial update has no
     * meaning here and diffing would only risk orphan rows.
     *
     * @param  array  $input  the raw request payload
     */
    public function write(CbtQuestion $question, array $input): void
    {
        $question->answers()->delete();

        foreach ($this->rowsFor($question->type, $input) as $sortOrder => $row) {
            CbtQuestionAnswer::create(array_merge($row, [
                'question_id' => $question->id,
                'sort_order' => $sortOrder,
            ]));
        }
    }

    protected function rowsFor(string $type, array $input): array
    {
        return match ($type) {
            'multiple_choice_single' => $this->singleChoiceRows($input),
            'multiple_choice_multiple' => $this->multiChoiceRows($input),
            'true_false' => $this->trueFalseRows($input),
            'short_answer' => $this->shortAnswerRows($input),
            'matching' => $this->matchingRows($input),
            default => [], // essay: never has answer rows
        };
    }

    /** One row per option; the one whose text matches mc_correct_single gets the full mark. */
    protected function singleChoiceRows(array $input): array
    {
        $correct = trim((string) ($input['mc_correct_single'] ?? ''));

        return collect($this->lines($input['mc_options'] ?? null))
            ->map(fn (string $option) => [
                'answer_text' => $option,
                'fraction' => $option === $correct ? 1 : 0,
                'feedback' => null,
            ])->values()->all();
    }

    /** Every correct option shares the mark evenly, so ticking all of them scores exactly 1. */
    protected function multiChoiceRows(array $input): array
    {
        $correctSet = $this->lines($input['mc_correct_multiple_raw'] ?? null);
        $share = max(1, count($correctSet));

        return collect($this->lines($input['mc_options'] ?? null))
            ->map(fn (string $option) => [
                'answer_text' => $option,
                'fraction' => in_array($option, $correctSet, true) ? round(1 / $share, 5) : 0,
                'feedback' => null,
            ])->values()->all();
    }

    protected function trueFalseRows(array $input): array
    {
        $isTrue = filter_var($input['true_false_answer'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            ['answer_text' => 'True', 'fraction' => $isTrue ? 1 : 0, 'feedback' => null],
            ['answer_text' => 'False', 'fraction' => $isTrue ? 0 : 1, 'feedback' => null],
        ];
    }

    /** One row per accepted spelling — every line of the textarea is a fully correct answer. */
    protected function shortAnswerRows(array $input): array
    {
        $accepted = $this->lines($input['short_answer_correct'] ?? null);
        if ($accepted === []) {
            return [];
        }

        return collect($accepted)
            ->map(fn (string $text) => ['answer_text' => $text, 'fraction' => 1, 'feedback' => null])
            ->values()->all();
    }

    /** "Stem = its correct match" per line; the stem is answer_text, the match goes in feedback. */
    protected function matchingRows(array $input): array
    {
        $rows = [];
        foreach ($this->lines($input['matching_pairs'] ?? null) as $line) {
            [$stem, $match] = array_pad(explode('=', $line, 2), 2, null);
            if ($stem === null || $match === null) {
                continue;
            }
            $rows[] = ['answer_text' => trim($stem), 'fraction' => 1, 'feedback' => trim($match)];
        }

        return $rows;
    }

    /** @return array<int, string> */
    public function lines(?string $raw): array
    {
        if (! $raw) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $raw)), fn ($line) => $line !== ''));
    }
}
