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
 *
 * A second, independent check narrows this further for a parent or
 * student (2026-07-26 user report: "Help & Knowledge Base for parent
 * is totally wrong, why is it showing how to set up house,
 * communicaiton, so may issues are wrog there"): the permission check
 * above alone was not enough, a parent/student holds several of the
 * same broad permission codes an admin screen checks (students.view,
 * communication.view) for a completely different, narrower reason,
 * their own child only, via FamilyScope, not for browsing school
 * administration articles. config('skulos.knowledge_base_start_
 * routes')[route]['hidden_for_family_scoped'] flags exactly which
 * ones to hide regardless, the same "shares a permission but isn't
 * really for them" exclusion nav-school.blade.php's own
 * $alwaysHiddenForScoped already applies to the sidebar.
 *
 * A third, identical check narrows this further for a class/subject
 * teacher or class assistant (2026-07-28 user request: "Help &
 * Knowledge Base role-relevance for OTHER roles"): the same shape of
 * problem the family-scoped fix above already solved, a teacher_scoped_
 * role (config('skulos.teacher_scoped_roles')) also shares several of
 * these same broad permission codes for their own class/subject only,
 * not for browsing school administration articles.
 * ['hidden_for_teacher_scoped'] flags exactly which ones to hide.
 */
class KnowledgeBaseController extends Controller
{
    protected function school(Request $request): \App\Models\School
    {
        return $request->attributes->get('school');
    }

    protected function isFamilyScoped(\App\Models\School $school): bool
    {
        $roleSlug = optional(Auth::user()?->roleIn($school))->slug;

        return $roleSlug && in_array($roleSlug, config('skulos.family_scoped_roles', []), true);
    }

    protected function isTeacherScoped(\App\Models\School $school): bool
    {
        $roleSlug = optional(Auth::user()?->roleIn($school))->slug;

        return $roleSlug && in_array($roleSlug, config('skulos.teacher_scoped_roles', []), true);
    }

    protected function articleVisible(\App\Models\KnowledgeBaseArticle $article, array $startRoutes, Rbac $rbac, $user, \App\Models\School $school, bool $familyScoped, bool $teacherScoped): bool
    {
        $meta = $startRoutes[$article->start_route] ?? [];
        if ($familyScoped && ($meta['hidden_for_family_scoped'] ?? false)) {
            return false;
        }
        if ($teacherScoped && ($meta['hidden_for_teacher_scoped'] ?? false)) {
            return false;
        }
        // Per-article flags (2026-07-28), separate from the route-level
        // ones above: a route can be legitimately reachable by more than
        // one audience for different reasons (school.timetable.mine,
        // school.chat.index), so a specific article's own wording/
        // content can be hidden from a scope even when its route stays
        // fully visible for another article that reuses the same route.
        if ($familyScoped && $article->hidden_for_family_scoped) {
            return false;
        }
        if ($teacherScoped && $article->hidden_for_teacher_scoped) {
            return false;
        }
        // Positive restriction, the inverse of the two hides above (real
        // bug found live 2026-08-05: a bursar, who genuinely holds
        // students.view/finance.view for their own unrelated reason, was
        // seeing the whole "For Parents" category — hidden_for_* can
        // only ever HIDE from a scope it names, it has no way to
        // restrict an article to ONLY that scope, so any THIRD role
        // sharing the same permission slipped straight through). Per-
        // article DB columns, same as hidden_for_family_scoped/
        // hidden_for_teacher_scoped just above, not the route-level
        // $meta array (a route can be reused by an article that should
        // NOT carry this restriction).
        if ($article->family_only && ! $familyScoped) {
            return false;
        }
        if ($article->teacher_only && ! $teacherScoped) {
            return false;
        }

        $permission = $meta['permission'] ?? null;

        return ! $permission || $rbac->check($user, $school, $permission);
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $q = trim((string) $request->query('q'));
        $school = $this->school($request);
        $user = Auth::user();
        $rbac = app(Rbac::class);
        $startRoutes = config('skulos.knowledge_base_start_routes', []);
        $familyScoped = $this->isFamilyScoped($school);
        $teacherScoped = $this->isTeacherScoped($school);

        $categories = KnowledgeBaseCategory::with(['articles' => function ($query) use ($q) {
            $query->where('is_published', true)->orderBy('sequence');
            if ($q !== '') {
                $query->where(fn ($w) => $w->where('title', 'like', "%{$q}%")->orWhere('body', 'like', "%{$q}%"));
            }
        }])->orderBy('sequence')->get()
            ->map(function ($category) use ($startRoutes, $rbac, $user, $school, $familyScoped, $teacherScoped) {
                $category->setRelation('articles', $category->articles->filter(
                    fn ($article) => $this->articleVisible($article, $startRoutes, $rbac, $user, $school, $familyScoped, $teacherScoped)
                ));

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
        $startRoutes = config('skulos.knowledge_base_start_routes', []);
        $visible = $this->articleVisible($article, $startRoutes, app(Rbac::class), Auth::user(), $school, $this->isFamilyScoped($school), $this->isTeacherScoped($school));
        abort_unless($visible, 404);

        return view('school.knowledge-base.show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'article' => $article,
        ]);
    }
}
