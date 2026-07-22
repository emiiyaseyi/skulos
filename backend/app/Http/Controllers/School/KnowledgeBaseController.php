<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeBaseCategory;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The in-app help/FAQ screen every signed-in school member can read
 * (2026-07-21 Knowledge Base build) — no special permission needed
 * beyond being a member of the school at all, this is a help resource,
 * not a management screen. Content is platform-authored (see
 * Admin\KnowledgeBaseController), shared across every school.
 *
 * Visibility is scoped to what the viewing role can actually do
 * (2026-07-22 user report: "Knowdlge base showing under other staff
 * should be related to the things the user can do only"). An article
 * with a start_route is really about a specific screen, so it is only
 * shown to a user who holds that screen's own gating permission
 * (config('skulos.knowledge_base_start_routes')[route]['permission']);
 * an article with no start_route is general orientation content and
 * stays visible to everyone.
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
        $school = $this->school($request);
        $user = Auth::user();
        $rbac = app(Rbac::class);
        $startRoutes = config('skulos.knowledge_base_start_routes', []);

        $categories = KnowledgeBaseCategory::with(['articles' => function ($query) use ($q) {
            $query->where('is_published', true)->orderBy('sequence');
            if ($q !== '') {
                $query->where(fn ($w) => $w->where('title', 'like', "%{$q}%")->orWhere('body', 'like', "%{$q}%"));
            }
        }])->orderBy('sequence')->get()
            ->map(function ($category) use ($startRoutes, $rbac, $user, $school) {
                $category->setRelation('articles', $category->articles->filter(function ($article) use ($startRoutes, $rbac, $user, $school) {
                    $permission = $startRoutes[$article->start_route]['permission'] ?? null;

                    return ! $permission || $rbac->check($user, $school, $permission);
                }));

                return $category;
            })
            ->filter(fn ($category) => $category->articles->isNotEmpty());

        return view('school.knowledge-base.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
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

        $school = $this->school($request);
        $permission = config('skulos.knowledge_base_start_routes', [])[$article->start_route]['permission'] ?? null;
        abort_unless(! $permission || app(Rbac::class)->check(Auth::user(), $school, $permission), 404);

        return view('school.knowledge-base.show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'article' => $article,
        ]);
    }
}
