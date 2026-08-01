<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-fix half of the question bank rework: converts every existing
 * question's Phase 2a `options`/`correct_answer` JSON into real
 * `cbt_question_answers` rows, and its free-text `category` string
 * into a real `cbt_question_categories` row, so no already-authored
 * question is lost or silently stops grading.
 *
 * Idempotent: skips any question that already has answer rows, so a
 * re-run (or a school migrated twice) is a no-op rather than a
 * duplicate-options bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('tenant');

        // Free-text category strings -> real category rows.
        $categoryIds = [];
        $names = $db->table('cbt_questions')
            ->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->pluck('category');

        foreach ($names as $sortOrder => $name) {
            $existing = $db->table('cbt_question_categories')->where('name', $name)->value('id');
            $categoryIds[$name] = $existing ?: $db->table('cbt_question_categories')->insertGetId([
                'name' => $name,
                'sort_order' => $sortOrder,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($db->table('cbt_questions')->get() as $question) {
            if ($question->category && isset($categoryIds[$question->category]) && ! $question->category_id) {
                $db->table('cbt_questions')->where('id', $question->id)
                    ->update(['category_id' => $categoryIds[$question->category]]);
            }

            if ($db->table('cbt_question_answers')->where('question_id', $question->id)->exists()) {
                continue; // already converted
            }

            foreach ($this->answerRowsFor($question) as $sortOrder => $row) {
                $db->table('cbt_question_answers')->insert(array_merge($row, [
                    'question_id' => $question->id,
                    'sort_order' => $sortOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    /** Maps one legacy question's JSON into the answer rows its type now needs. */
    protected function answerRowsFor(object $question): array
    {
        $options = json_decode((string) $question->options, true);
        $correct = json_decode((string) $question->correct_answer, true);

        return match ($question->type) {
            'multiple_choice_single' => collect($options ?? [])
                ->map(fn ($option) => [
                    'answer_text' => (string) $option,
                    'fraction' => ((string) $option === (string) $correct) ? 1 : 0,
                    'feedback' => null,
                ])->values()->all(),

            'multiple_choice_multiple' => (function () use ($options, $correct) {
                $correctSet = array_map('strval', (array) $correct);
                $share = max(1, count($correctSet));

                return collect($options ?? [])->map(fn ($option) => [
                    'answer_text' => (string) $option,
                    'fraction' => in_array((string) $option, $correctSet, true) ? round(1 / $share, 5) : 0,
                    'feedback' => null,
                ])->values()->all();
            })(),

            'true_false' => (function () use ($correct) {
                $isTrue = filter_var($correct, FILTER_VALIDATE_BOOLEAN);

                return [
                    ['answer_text' => 'True', 'fraction' => $isTrue ? 1 : 0, 'feedback' => null],
                    ['answer_text' => 'False', 'fraction' => $isTrue ? 0 : 1, 'feedback' => null],
                ];
            })(),

            'short_answer' => [
                ['answer_text' => is_array($correct) ? reset($correct) : (string) $correct, 'fraction' => 1, 'feedback' => null],
            ],

            // One row per LEFT stem; its correct right-hand match is
            // stored in `feedback` (see CbtQuestion::gradeMatching()).
            'matching' => collect($options['left'] ?? [])
                ->map(function ($stem, $index) use ($options, $correct) {
                    $rightIndex = $correct[$index] ?? $correct[(string) $index] ?? null;

                    return [
                        'answer_text' => (string) $stem,
                        'fraction' => 1,
                        'feedback' => $rightIndex === null ? null : (string) ($options['right'][$rightIndex] ?? ''),
                    ];
                })->values()->all(),

            default => [], // essay: never has answer rows
        };
    }

    public function down(): void
    {
        // Non-destructive by design: the legacy options/correct_answer
        // columns were never dropped, so rolling back the schema
        // migration alone fully restores the old behaviour. Deleting
        // the converted rows here would be the only destructive step in
        // this whole rework, for no benefit.
    }
};
