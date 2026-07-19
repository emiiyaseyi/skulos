<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\SchoolClass;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Narrative Evaluation (user request 2026-07-18): "add a new settings
 * for all admins across all plan that allows the admin... to set a
 * class or some classes to have the Narrative Evaluation style of
 * report where scores are not entered into the student report card but
 * more of Narrative Evaluation (comment based)". Per class (SchoolClass.
 * evaluation_style), available on every plan (not a plan_feature_catalog
 * gate), admin only. See ReportCardController::show()/classEntry() for
 * where this flag actually changes behaviour: a narrative class always
 * renders the comment-based template and always gets the comment entry
 * screen, regardless of the school's own global template preference.
 */
class NarrativeEvaluationController extends Controller
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
        return SchoolClass::active()->with('section')->get()
            ->sortBy(fn (SchoolClass $c) => sprintf('%05d-%05d', $c->section?->sequence ?? 0, $c->sequence ?? 0))
            ->values();
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $school = $this->school($request);

        return view('school.examinations.narrative-evaluation', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'classes' => $this->orderedClasses(),
        ]);
    }

    /** Declarative save: every class id checked becomes narrative, every other class reverts to scores. */
    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');

        $data = $request->validate([
            'narrative_class_ids' => ['array'],
            'narrative_class_ids.*' => ['integer', 'exists:tenant.school_classes,id'],
        ]);
        $narrativeIds = $data['narrative_class_ids'] ?? [];

        SchoolClass::whereIn('id', $narrativeIds)->update(['evaluation_style' => SchoolClass::EVALUATION_STYLE_NARRATIVE]);
        SchoolClass::whereNotIn('id', $narrativeIds)->update(['evaluation_style' => SchoolClass::EVALUATION_STYLE_SCORES]);

        return back()->with('status', 'Narrative Evaluation classes updated.');
    }
}
