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
            'passwordLength' => (int) (Setting::grab('admission_password_length') ?? config('skulos.default_admission_password_length', 10)),
            'referenceStyle' => Setting::grab('admission_reference_style') ?? config('skulos.default_admission_reference_style', 'custom'),
            'referencePrefix' => Setting::grab('admission_reference_prefix') ?? config('skulos.default_admission_reference_prefix', 'APP'),
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
            'password_length' => ['required', 'integer', 'min:6', 'max:24'],
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
        Setting::put('admission_password_length', $data['password_length']);

        return back()->with('status', 'Admission pipeline updated.');
    }

    public function updateReferenceFormat(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.manage_settings');

        $data = $request->validate([
            'reference_style' => ['required', Rule::in(['custom', 'structured'])],
            'reference_prefix' => ['nullable', 'string', 'max:20'],
        ]);

        Setting::put('admission_reference_style', $data['reference_style']);
        if (! empty($data['reference_prefix'])) {
            Setting::put('admission_reference_prefix', $data['reference_prefix']);
        }

        return back()->with('status', 'Reference number format updated.');
    }

    public function storeWindow(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.manage_settings');

        // Admin can select several classes at once (user directive
        // 2026-07-31: "admin should be able to select multiple classes
        // the admission is open for/to"); each class still gets its own
        // AdmissionWindow row, same as opening them one at a time would
        // have — every downstream lookup (isOpen() filtering, fee
        // lookups, conversion) already keys off session+class+campus per
        // row, so nothing else needed to change to support this.
        $data = $request->validate([
            'session_id' => ['required', 'exists:tenant.academic_sessions,id'],
            'school_class_id' => ['required', 'array', 'min:1'],
            'school_class_id.*' => ['exists:tenant.school_classes,id'],
            'campus_id' => ['nullable', 'exists:tenant.campuses,id'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after_or_equal:opens_at'],
            'application_fee_amount' => ['required', 'numeric', 'min:0'],
            'acceptance_fee_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $classIds = $data['school_class_id'];
        unset($data['school_class_id']);

        foreach ($classIds as $classId) {
            AdmissionWindow::create($data + ['school_class_id' => $classId, 'is_active' => true]);
        }

        return back()->with('status', count($classIds) > 1 ? count($classIds).' admission windows opened.' : 'Admission window opened.');
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
