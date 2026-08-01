<?php

namespace Tests\Concerns;

use App\Models\Tenant\CbtQuestion;
use App\Models\Tenant\CbtQuestionAnswer;

/**
 * Builds question-bank questions with their real cbt_question_answers
 * rows. Answer options stopped being a JSON column on CbtQuestion when
 * the bank was reworked to the reference repo's shape (partial credit
 * per option, per-option feedback), so a test that only sets `prompt`
 * and `type` would create a question that cannot be graded at all.
 */
trait MakesCbtQuestions
{
    protected function makeTrueFalseQuestion(string $prompt, bool $correct = true, float $points = 1): CbtQuestion
    {
        $question = CbtQuestion::create(['type' => 'true_false', 'prompt' => $prompt, 'points' => $points]);

        CbtQuestionAnswer::create(['question_id' => $question->id, 'answer_text' => 'True', 'fraction' => $correct ? 1 : 0, 'sort_order' => 0]);
        CbtQuestionAnswer::create(['question_id' => $question->id, 'answer_text' => 'False', 'fraction' => $correct ? 0 : 1, 'sort_order' => 1]);

        return $question->fresh('answers');
    }

    /**
     * @param  array<int, string>  $options
     * @param  array<int, string>  $correct  subset of $options; more than one makes it a multi-answer question
     */
    protected function makeChoiceQuestion(string $prompt, array $options, array $correct, float $points = 1): CbtQuestion
    {
        $type = count($correct) > 1 ? 'multiple_choice_multiple' : 'multiple_choice_single';
        $question = CbtQuestion::create(['type' => $type, 'prompt' => $prompt, 'points' => $points]);
        $share = max(1, count($correct));

        foreach ($options as $sortOrder => $option) {
            CbtQuestionAnswer::create([
                'question_id' => $question->id,
                'answer_text' => $option,
                'fraction' => in_array($option, $correct, true) ? ($type === 'multiple_choice_multiple' ? round(1 / $share, 5) : 1) : 0,
                'sort_order' => $sortOrder,
            ]);
        }

        return $question->fresh('answers');
    }

    protected function makeEssayQuestion(string $prompt, float $points = 5): CbtQuestion
    {
        // Essay deliberately has no answer rows at all — it is the only
        // type that is never auto-graded.
        return CbtQuestion::create(['type' => 'essay', 'prompt' => $prompt, 'points' => $points]);
    }

    /**
     * Returns the answer row id a taker would submit to pick the given
     * option text. Reads the ALREADY-LOADED answers relation rather
     * than issuing a query, so it still works after a test has called
     * TenantManager::forget() — which is exactly where assertions
     * about a submitted answer tend to live.
     */
    protected function answerIdFor(CbtQuestion $question, string $optionText): int
    {
        return (int) $question->answers->firstWhere('answer_text', $optionText)->id;
    }
}
