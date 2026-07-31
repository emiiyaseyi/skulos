<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\AdmissionWindow;
use App\Models\Tenant\Applicant;
use App\Models\Tenant\Campus;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Setting;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Admissions module, Phase 1b: which pipeline stages apply, how long an
 * applicant's account stays usable, and which class/session cycles are
 * open right now with what application fee.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class AdmissionSettingsController extends Controller
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

    public function edit(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'admissions.manage_settings');
        $school = $this->school($request);

        return view('school.admissions.settings', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'stageCatalog' => config('skulos.admission_pipeline_stages', []),
            'pipelineChain' => Applicant::pipelineChain(),
            'ttlDays' => (int) (Setting::grab('admission_account_ttl_days') ?? config('skulos.default_admission_account_ttl_days', 30)),
            'windows' => AdmissionWindow::with(['session', 'schoolClass', 'campus'])->orderByDesc('id')->paginate(30)->withQueryString(),
            'sessions' => AcademicSession::active()->orderByDesc('id')->get(),
            'classes' => SchoolClass::active()->with('section')->orderBy('sequence')->get(),
            'campuses' => Campus::orderBy('name')->get(),
        ]);
    }

    public function updatePipeline(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.manage_settings');

        // 'stages' => required + min:1 already guarantees at least one
        // valid, catalog-checked stage survives validation before this
        // line ever runs (a blank/empty submission bounces back as a
        // normal validation error, not a separate 422 check here).
        $catalogKeys = array_keys(config('skulos.admission_pipeline_stages', []));
        $data = $request->validate([
            'stages' => ['required', 'array', 'min:1'],
            'stages.*' => [Rule::in($catalogKeys)],
            'account_ttl_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        // Submitted checkboxes can arrive in any order; the pipeline is a
        // fixed sequence an applicant moves through, not a freely
        // reorderable chain like report card review (an admin can trim
        // optional stages, not reshuffle around the ones with real
        // behaviour attached to them, e.g. document upload has to come
        // before an admin can review documents).
        $chain = array_values(array_intersect($catalogKeys, $data['stages']));

        Setting::put('admission_pipeline_chain', $chain);
        Setting::put('admission_account_ttl_days', $data['account_ttl_days']);

        return back()->with('status', 'Admission pipeline updated.');
    }

    public function storeWindow(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.manage_settings');

        $data = $request->validate([
            'session_id' => ['required', 'exists:tenant.academic_sessions,id'],
            'school_class_id' => ['required', 'exists:tenant.school_classes,id'],
            'campus_id' => ['nullable', 'exists:tenant.campuses,id'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after_or_equal:opens_at'],
            'application_fee_amount' => ['required', 'numeric', 'min:0'],
            'acceptance_fee_amount' => ['required', 'numeric', 'min:0'],
        ]);

        AdmissionWindow::create($data + ['is_active' => true]);

        return back()->with('status', 'Admission window opened.');
    }

    public function toggleWindow(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.manage_settings');

        // Read via $request->route(), not a typed method parameter: this
        // route sits under the {school} domain wildcard, so an untyped
        // scalar controller parameter resolves positionally against the
        // FULL route parameter list (domain param included), not by
        // name, and would silently receive the school's slug instead of
        // the window id (the recurring gotcha documented in CLAUDE.md).
        $window = AdmissionWindow::findOrFail($request->route('windowId'));
        $window->update(['is_active' => ! $window->is_active]);

        return back()->with('status', $window->is_active ? 'Window reopened.' : 'Window closed.');
    }
}
