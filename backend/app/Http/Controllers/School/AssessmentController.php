<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\Score;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Assessment structure (user request 2026-07-10): the school-wide list of
 * "Test / Continuous Assessment" and "Examinations" components the
 * Results entry screen and ResultService already read/write against
 * (AssessmentComponent existed before this with no admin screen at all,
 * only tests or a future wizard step could create one). Gated behind
 * examinations.approve_results rather than the plain create/update a
 * rank and file teacher holds, since redefining the whole school's
 * assessment format is an institution-wide policy decision, not
 * something every subject teacher should be able to change.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class AssessmentController extends Controller
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

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'examinations.view');
        $school = $this->school($request);

        $components = AssessmentComponent::orderBy('sequence')->get();

        return view('school.examinations.structure', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'caComponents' => $components->where('type', AssessmentComponent::TYPE_CA)->values(),
            'examComponents' => $components->where('type', AssessmentComponent::TYPE_EXAM)->values(),
            'totalPossible' => AssessmentComponent::totalPossible(),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'examinations.approve_results'),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('tenant.assessment_components', 'name')],
            'type' => ['required', Rule::in([AssessmentComponent::TYPE_CA, AssessmentComponent::TYPE_EXAM])],
            'max_score' => ['required', 'numeric', 'min:0.01', 'max:1000'],
        ]);

        AssessmentComponent::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'max_score' => $data['max_score'],
            'sequence' => (int) AssessmentComponent::max('sequence') + 1,
        ]);

        return back()->with('status', "\"{$data['name']}\" added.");
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $component = AssessmentComponent::findOrFail($request->route('componentId'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('tenant.assessment_components', 'name')->ignore($component->id)],
            'max_score' => ['required', 'numeric', 'min:0.01', 'max:1000'],
        ]);

        $component->update($data);

        return back()->with('status', "\"{$component->name}\" updated.");
    }

    /**
     * Deleting a component cascades to every Score row entered against it
     * (the foreign key is set up that way), so this refuses to delete one
     * that already has scores rather than silently destroying entered
     * data. A school genuinely retiring a component should either wait
     * until it is a new term with nothing entered yet, or accept the
     * loss explicitly by clearing those scores first.
     */
    public function destroy(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $component = AssessmentComponent::findOrFail($request->route('componentId'));

        if (Score::where('assessment_component_id', $component->id)->exists()) {
            return back()->with('error', "\"{$component->name}\" already has scores entered against it and cannot be removed, this would delete that data. Clear its scores first if you are sure.");
        }

        $name = $component->name;
        $component->delete();

        return back()->with('status', "\"{$name}\" removed.");
    }
}
