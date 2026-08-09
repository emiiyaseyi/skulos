<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\AssessmentWindow;
use App\Models\Tenant\AssessmentWindowExtension;
use App\Models\Tenant\Score;
use App\Models\Tenant\Term;
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

        // Score entry windows (user request 2026-07-18): a window is per
        // (component, term) since the same component recurs every term,
        // so this screen needs its own term picker, same shape as Report
        // Cards/Student Reports.
        $terms = Term::with('session')->orderByDesc('starts_on')->get();
        $termId = $request->integer('term_id') ?: null;
        $term = ($termId ? Term::find($termId) : null) ?? Term::current()->first();

        // Per-term structure (user directive 2026-08-05: "the assessment
        // structure can be changed per term so its not a fixed one
        // across the whole session or school life time"). "editingTermId"
        // (distinct from the score-entry-window $term above) is which
        // scope this screen's own add/edit/delete actions apply to —
        // "all" (the universal set) or one specific term's own fork.
        // Defaults to "all" so an admin editing the structure for the
        // very first time still shapes the universal set every already
        // provisioned school already depends on, not an empty new fork.
        $editingTermParam = $request->query('editing_term_id', 'all');
        $editingTerm = $editingTermParam !== 'all' ? Term::find($editingTermParam) : null;
        $isForked = $editingTerm && AssessmentComponent::where('term_id', $editingTerm->id)->exists();
        // A term with no fork of its own previews the universal set
        // read-only (same "preview the shared catalog until it has one
        // of its own" shape as TimetablePeriod's per-section preview) —
        // add/edit/delete stay disabled until it is explicitly
        // customized, so nothing is ever silently created half-forked.
        $components = ($editingTerm && ! $isForked)
            ? AssessmentComponent::whereNull('term_id')->orderBy('sequence')->get()
            : AssessmentComponent::where('term_id', $editingTerm?->id)->orderBy('sequence')->get();
        $canEditStructure = ! $editingTerm || $isForked;

        $windowsByComponent = $term
            ? AssessmentWindow::where('term_id', $term->id)->get()->keyBy('assessment_component_id')
            : collect();

        return view('school.examinations.structure', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'caComponents' => $components->where('type', AssessmentComponent::TYPE_CA)->values(),
            'examComponents' => $components->where('type', AssessmentComponent::TYPE_EXAM)->values(),
            'totalPossible' => (float) $components->sum('max_score'),
            // Folds the fork state into the SAME flag every existing
            // control in this view already checks, so a term merely
            // previewing the universal structure (not yet customized)
            // never shows editable-looking controls that would actually
            // 403 or silently create a half-fork on submit. The couple
            // of permission-only actions (fork/unfork itself, the
            // score-entry-window term picker, Extension requests)
            // deliberately use canManagePermission instead, below.
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'examinations.approve_results') && $canEditStructure,
            'canManagePermission' => app(Rbac::class)->check(Auth::user(), $school, 'examinations.approve_results'),
            'terms' => $terms,
            'term' => $term,
            'windowsByComponent' => $windowsByComponent,
            'pendingExtensionCount' => AssessmentWindowExtension::pending()->count(),
            'editingTerm' => $editingTerm,
            'isForked' => $isForked,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $editingTermParam = $request->input('editing_term_id', 'all');
        $editingTermId = $editingTermParam !== 'all' ? (int) $editingTermParam : null;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('tenant.assessment_components', 'name')->where('term_id', $editingTermId)],
            'type' => ['required', Rule::in([AssessmentComponent::TYPE_CA, AssessmentComponent::TYPE_EXAM])],
            'max_score' => ['required', 'numeric', 'min:0.01', 'max:1000'],
        ]);

        AssessmentComponent::create([
            'term_id' => $editingTermId,
            'name' => $data['name'],
            'type' => $data['type'],
            'counts_for_midterm' => $request->boolean('counts_for_midterm'),
            'counts_for_fullterm' => $request->boolean('counts_for_fullterm'),
            'max_score' => $data['max_score'],
            'sequence' => (int) AssessmentComponent::where('term_id', $editingTermId)->max('sequence') + 1,
        ]);

        return back()->with('status', "\"{$data['name']}\" added.");
    }

    /**
     * A term editing the universal structure for the first time (its own
     * fork has never existed) gets a real, independent copy seeded from
     * whatever the universal set currently is — the same "Customize...
     * for this [scope]" fork action already established for
     * TimetablePeriod's own per-section catalog, so a school can start
     * from its existing structure and adjust just one term, not build a
     * brand new one from nothing.
     */
    public function forkTerm(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $data = $request->validate(['term_id' => ['required', 'exists:tenant.terms,id']]);

        if (AssessmentComponent::where('term_id', $data['term_id'])->exists()) {
            return back()->with('error', 'This term already has its own structure.');
        }

        foreach (AssessmentComponent::whereNull('term_id')->orderBy('sequence')->get() as $universal) {
            AssessmentComponent::create([
                'term_id' => $data['term_id'],
                'name' => $universal->name,
                'type' => $universal->type,
                'counts_for_midterm' => $universal->counts_for_midterm,
                'counts_for_fullterm' => $universal->counts_for_fullterm,
                'max_score' => $universal->max_score,
                'sequence' => $universal->sequence,
            ]);
        }

        return redirect()->route('school.examinations.structure', [
            'school' => $this->school($request)->slug, 'editing_term_id' => $data['term_id'],
        ])->with('status', 'This term now has its own independent structure, starting from the universal one.');
    }

    /** Deletes every one of a term's own component rows, reverting it back to following the universal structure. */
    public function unforkTerm(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $data = $request->validate(['term_id' => ['required', 'exists:tenant.terms,id']]);

        $components = AssessmentComponent::where('term_id', $data['term_id'])->get();
        $scored = Score::whereIn('assessment_component_id', $components->pluck('id'))->exists();
        if ($scored) {
            return back()->with('error', 'This term already has scores entered against its own structure, remove those first if you want to revert to the universal one.');
        }

        AssessmentComponent::where('term_id', $data['term_id'])->delete();

        return redirect()->route('school.examinations.structure', [
            'school' => $this->school($request)->slug, 'editing_term_id' => 'all',
        ])->with('status', 'Reverted to following the universal structure.');
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $component = AssessmentComponent::findOrFail($request->route('componentId'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('tenant.assessment_components', 'name')->where('term_id', $component->term_id)->ignore($component->id)],
            'max_score' => ['required', 'numeric', 'min:0.01', 'max:1000'],
        ]);

        $data['counts_for_midterm'] = $request->boolean('counts_for_midterm');
        $data['counts_for_fullterm'] = $request->boolean('counts_for_fullterm');

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

    /**
     * Bulk version of destroy(), same list-screen bulk-action pattern used
     * across the app. Mirrors destroy()'s own scored-component guard per
     * row, skipping (not failing the whole batch on) any component that
     * already has scores entered against it.
     */
    public function bulkDestroy(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $data = $request->validate([
            'component_ids' => ['required', 'array', 'min:1'],
            'component_ids.*' => ['exists:tenant.assessment_components,id'],
        ]);

        $components = AssessmentComponent::whereIn('id', $data['component_ids'])->get();
        $scoredIds = Score::whereIn('assessment_component_id', $components->pluck('id'))
            ->distinct()->pluck('assessment_component_id')->all();

        $removed = 0;
        $skipped = [];
        foreach ($components as $component) {
            if (in_array($component->id, $scoredIds, true)) {
                $skipped[] = $component->name;
                continue;
            }
            $component->delete();
            $removed++;
        }

        $message = $removed > 0 ? "{$removed} component(s) removed." : 'No components removed.';
        if ($skipped) {
            $message .= ' Skipped (already has scores entered): '.implode(', ', $skipped).'.';
        }

        return back()->with($skipped ? 'error' : 'status', $message);
    }

    /**
     * One click starting structure (guided setup wizard, user request
     * 2026-07-11), the common two CA plus one exam split. Only ever
     * loaded onto an empty structure, a school that already defined its
     * own components is never overwritten.
     */
    public function applyDefaults(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');

        // Deliberately scoped to the universal set only (term_id null):
        // this action is the Guided Setup wizard's own one-time "start
        // from empty" step, never reachable from the per-term editing UI
        // above, so a term that already forked its own structure must
        // never block (or be affected by) seeding the school's universal
        // defaults.
        if (AssessmentComponent::whereNull('term_id')->exists()) {
            return back()->with('error', 'An assessment structure already exists, defaults are only for starting from empty.');
        }

        foreach (config('skulos.default_assessment_components') as $index => $component) {
            AssessmentComponent::create([
                'name' => $component['name'],
                'type' => $component['type'],
                'max_score' => $component['max_score'],
                'sequence' => $index + 1,
            ]);
        }

        return back()->with('status', 'Default assessment structure loaded: '.count(config('skulos.default_assessment_components')).' component(s).');
    }
}
