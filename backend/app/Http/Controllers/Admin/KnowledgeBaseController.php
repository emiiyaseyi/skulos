<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeBaseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Platform admin CMS for the in-app Knowledge Base (2026-07-21, resolving
 * the "real screen or a service commitment" ambiguity as "both" — a real
 * help/FAQ screen, admin-editable, not hardcoded). One shared article set
 * for every school, authored here once by the platform admin, browsed by
 * every school's own signed-in members under School\KnowledgeBaseController.
 */
class KnowledgeBaseController extends Controller
{
    public function index(): \Illuminate\View\View
    {
        return view('admin.knowledge-base.index', [
            'categories' => KnowledgeBaseCategory::with(['articles' => fn ($q) => $q->orderBy('sequence')])
                ->orderBy('sequence')->get(),
        ]);
    }

    public function storeCategory(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        KnowledgeBaseCategory::create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug(KnowledgeBaseCategory::class, $data['name']),
            'sequence' => (int) KnowledgeBaseCategory::max('sequence') + 1,
        ]);

        return back()->with('status', 'Category added.');
    }

    public function updateCategory(Request $request): \Illuminate\Http\RedirectResponse
    {
        $category = KnowledgeBaseCategory::findOrFail($request->route('categoryId'));
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $category->update(['name' => $data['name']]);

        return back()->with('status', 'Category renamed.');
    }

    public function destroyCategory(Request $request): \Illuminate\Http\RedirectResponse
    {
        $category = KnowledgeBaseCategory::findOrFail($request->route('categoryId'));
        $name = $category->name;
        $category->delete();

        return back()->with('status', "\"{$name}\" and its articles removed.");
    }

    public function editArticle(Request $request): \Illuminate\View\View
    {
        $article = KnowledgeBaseArticle::findOrFail($request->route('articleId'));

        return view('admin.knowledge-base.article-edit', [
            'article' => $article,
            'categories' => KnowledgeBaseCategory::orderBy('sequence')->get(),
        ]);
    }

    public function storeArticle(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $this->validatedArticle($request);

        KnowledgeBaseArticle::create($data + [
            'slug' => $this->uniqueSlug(KnowledgeBaseArticle::class, $data['title']),
            'sequence' => (int) KnowledgeBaseArticle::where('knowledge_base_category_id', $data['knowledge_base_category_id'])->max('sequence') + 1,
        ]);

        return back()->with('status', 'Article added.');
    }

    public function updateArticle(Request $request): \Illuminate\Http\RedirectResponse
    {
        $article = KnowledgeBaseArticle::findOrFail($request->route('articleId'));
        $article->update($this->validatedArticle($request, $article->id));

        return back()->with('status', 'Article saved.');
    }

    public function destroyArticle(Request $request): \Illuminate\Http\RedirectResponse
    {
        $article = KnowledgeBaseArticle::findOrFail($request->route('articleId'));
        $title = $article->title;
        $article->delete();

        return back()->with('status', "\"{$title}\" removed.");
    }

    protected function validatedArticle(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'knowledge_base_category_id' => ['required', 'exists:knowledge_base_categories,id'],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:20000'],
            'is_published' => ['nullable', 'boolean'],
        ]) + ['is_published' => $request->boolean('is_published', true)];
    }

    protected function uniqueSlug(string $model, string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $i = 1;
        while ($model::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
