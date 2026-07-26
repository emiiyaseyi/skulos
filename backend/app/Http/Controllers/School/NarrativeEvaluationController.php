<?php

namespace App\Http\Controllers\School;

use App\Models\School;
use App\Models\Tenant\ClassNarrativeTerm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Term;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Narrative Evaluation (user request 2026-07-18): "add a new settings
 * for all admins across all plan that allows the admin... to set a
 * class or some classes to have the Narrative Evaluation style of
 * report where scores are not entered into the student report card but
 * more of Narrative Evaluation (comment based)". Available on every plan
 * (not a plan_feature_catalog gate), admin only.
 *
 * Per-class-per-term (2026-07-26, user request: "re-toggleable across
 * terms"): the toggle is scoped to one term at a time via ClassNarrativeTerm
 * rows, not a permanent flag on the class, so a school can turn it on for
 * one term and off again for the next without rewriting an earlier term's
 * already-generated report cards. See ReportCardController::show()/
 * classEntry() for where this actually changes behaviour: a class narrative
 * for the term being viewed always renders the comment-based template and
 * always gets the comment entry screen, regardless of the school's own
 * global template preference.
 */
class NarrativeEvaluationController extends \App\Http\Controllers\Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request, string $permission): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), $permission),
            403,
            'You do not have permission for this.'
        );
    }

    protected function orderedClasses(): \Illuminate\Support\Collection
    {
        return SchoolClass::active()->with('section', 'narrativeTerms')->get()
            ->sortBy(fn (SchoolClass $c) => sprintf('%05d-%05d', $c->section?->sequence ?? 0, $c->sequence ?? 0))
            ->values();
    }

    protected function resolveTerm(Request $request): ?Term
    {
        $termId = $request->query('term_id') ?: $request->input('term_id');

        return $termId ? Term::find($termId) : Term::current()->first();
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $school = $this->school($request);
        $term = $this->resolveTerm($request);

        return view('school.examinations.narrative-evaluation', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'classes' => $this->orderedClasses(),
            'term' => $term,
            'terms' => Term::with('session')->orderByDesc('starts_on')->get(),
        ]);
    }

    /** Declarative save for ONE term: every checked class id becomes narrative for that term, every other class reverts to scored for that term, other terms are never touched. */
    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');

        $data = $request->validate([
            'term_id' => ['required', 'exists:tenant.terms,id'],
            'narrative_class_ids' => ['array'],
            'narrative_class_ids.*' => ['integer', 'exists:tenant.school_classes,id'],
        ]);
        $narrativeIds = $data['narrative_class_ids'] ?? [];
        $termId = (int) $data['term_id'];

        foreach ($narrativeIds as $classId) {
            ClassNarrativeTerm::firstOrCreate(['school_class_id' => $classId, 'term_id' => $termId]);
        }
        ClassNarrativeTerm::where('term_id', $termId)->whereNotIn('school_class_id', $narrativeIds)->delete();

        return back()->with('status', 'Narrative Evaluation classes updated for this term.');
    }
}
