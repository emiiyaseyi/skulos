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
                KnowledgeBaseArticle::firstOrCreate(
                    ['slug' => Str::slug($article['title'])],
                    [
                        'knowledge_base_category_id' => $category->id,
                        'title' => $article['title'],
                        'body' => $article['body'],
                        'sequence' => $articleSequence,
                        'is_published' => true,
                    ]
                );
                $articleSequence++;
            }

            $sequence++;
        }
    }
}
