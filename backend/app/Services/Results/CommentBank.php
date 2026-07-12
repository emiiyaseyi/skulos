<?php

namespace App\Services\Results;

use App\Models\Tenant\Comment;

/**
 * Score-range comment suggestions (user directive 2026-07-12, sourced from
 * "Intelligent Assessment & Report Card Workflow.docx": "this is not
 * really an AI engine, just use the bank against the score range and the
 * flow in the document"). Reads from the tenant `comments` table, never
 * from config directly (user correction 2026-07-12: "note that i dont
 * want anything hardcoded as you know", "everything must be a DB backed
 * layer, which means an admin/school/user should be able to customize
 * their own" — config('skulos.default_comment_bank') is only ever the
 * seed catalog, seeded into the real table the first time it is needed,
 * exactly like grading_scales/grading_bands already work). Picks a
 * random comment from whichever band a score (a 0-100 percentage, the
 * same assumption GradingScale::bandFor() already makes) falls into, so
 * two students with the same score do not get an identical remark.
 */
class CommentBank
{
    /** Seeds the tenant comments table from config('skulos.default_comment_bank') the first time it is empty, a no-op on every call after that. Public so an explicit "reload defaults" admin action can call it directly later too. */
    public function ensureSeeded(): void
    {
        if (Comment::exists()) {
            return;
        }

        foreach (config('skulos.default_comment_bank', []) as $band) {
            foreach ($band['comments'] ?? [] as $text) {
                Comment::create([
                    'min_score' => $band['min'],
                    'max_score' => $band['max'],
                    'text' => $text,
                    'is_active' => true,
                ]);
            }
        }
    }

    public function suggestionFor(float $score): ?string
    {
        $this->ensureSeeded();

        $comments = Comment::where('is_active', true)
            ->where('min_score', '<=', $score)
            ->where('max_score', '>=', $score)
            ->pluck('text');

        return $comments->isEmpty() ? null : $comments->random();
    }
}
