<?php

namespace Database\Seeders;

use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeBaseCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds config('skulos.default_knowledge_base') into the real
 * knowledge_base_categories/articles tables once. Idempotent by slug —
 * safe to re-run, never overwrites an admin's own edits to an
 * already-seeded article (only fills in what's missing), matching
 * PlanSeeder/CommentBankSeeder's own convention.
 *
 * Revisited 2026-07-22 when steps/start_route were added to the schema:
 * an already-seeded article from before that change would otherwise be
 * frozen without them forever (firstOrCreate only sets attributes at
 * creation time), the same shape of gotcha CLAUDE.md documents for
 * extra_permissions/module_states, one layer up. Backfills steps/
 * start_route (and body, now used as a short intro rather than the
 * whole article) onto an existing row only when that column is still
 * genuinely empty, so a school's or admin's own edit is never overwritten.
 */
class KnowledgeBaseSeeder extends Seeder
{
    public function run(): void
    {
        $sequence = 0;

        foreach (config('skulos.default_knowledge_base') as $categoryName => $articles) {
            $category = KnowledgeBaseCategory::firstOrCreate(
                ['slug' => Str::slug($categoryName)],
                ['name' => $categoryName, 'sequence' => $sequence]
            );

            $articleSequence = 0;
            foreach ($articles as $article) {
                $slug = Str::slug($article['title']);
                $existing = KnowledgeBaseArticle::where('slug', $slug)->first();

                if (! $existing) {
                    KnowledgeBaseArticle::create([
                        'knowledge_base_category_id' => $category->id,
                        'title' => $article['title'],
                        'slug' => $slug,
                        'body' => $article['body'] ?? '',
                        'steps' => $article['steps'] ?? null,
                        'start_route' => $article['start_route'] ?? null,
                        'sequence' => $articleSequence,
                        'is_published' => true,
                    ]);
                } else {
                    $existing->update(array_filter([
                        'steps' => empty($existing->steps) ? ($article['steps'] ?? null) : null,
                        'start_route' => $existing->start_route === null ? ($article['start_route'] ?? null) : null,
                    ], fn ($v) => $v !== null));
                }

                $articleSequence++;
            }

            $sequence++;
        }
    }
}
