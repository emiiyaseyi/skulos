<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeBaseCategory;
use Illuminate\Http\Request;

/**
 * The in-app help/FAQ screen every signed-in school member can read
 * (2026-07-21 Knowledge Base build) — no special permission needed
 * beyond being a member of the school at all, this is a help resource,
 * not a management screen. Content is platform-authored (see
 * Admin\KnowledgeBaseController), shared across every school.
 */
class KnowledgeBaseController extends Controller
{
    protected function school(Request $request): \App\Models\School
    {
        return $request->attributes->get('school');
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $q = trim((string) $request->query('q'));

        $categories = KnowledgeBaseCategory::with(['articles' => function ($query) use ($q) {
            $query->where('is_published', true)->orderBy('sequence');
            if ($q !== '') {
                $query->where(fn ($w) => $w->where('title', 'like', "%{$q}%")->orWhere('body', 'like', "%{$q}%"));
            }
        }])->orderBy('sequence')->get()->filter(fn ($category) => $category->articles->isNotEmpty());

        return view('school.knowledge-base.index', [
            'school' => $this->school($request),
            'accent' => $this->school($request)->branding()['color'],
            'categories' => $categories,
            'q' => $q,
        ]);
    }

    public function show(Request $request): \Illuminate\View\View
    {
        $article = KnowledgeBaseArticle::where('slug', $request->route('slug'))
            ->where('is_published', true)
            ->with('category')
            ->firstOrFail();

        return view('school.knowledge-base.show', [
            'school' => $this->school($request),
            'accent' => $this->school($request)->branding()['color'],
            'article' => $article,
        ]);
    }
}
