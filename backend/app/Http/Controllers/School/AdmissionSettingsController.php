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
            'passwordStyle' => Setting::grab('admission_password_style') ?? config('skulos.default_admission_password_style', 'random'),
            'referenceStyle' => Setting::grab('admission_reference_style') ?? config('skulos.default_admission_reference_style', 'custom'),
            'referencePrefix' => Setting::grab('admission_reference_prefix') ?? config('skulos.default_admission_reference_prefix', 'APP'),
            'batches' => $this->windowBatches(),
            'sessions' => AcademicSession::active()->orderByDesc('id')->get(),
            'classes' => SchoolClass::active()->with('section')->orderBy('sequence')->get(),
            'campuses' => Campus::orderBy('name')->get(),
        ]);
    }

    /**
     * Groups AdmissionWindow rows by batch_uuid into one summary object
     * per admin-facing "window" (live feedback 2026-07-31: "it should
     * not show all the windows per class but all merged into one"). A
     * school opens at most a handful of these across its whole lifetime,
     * so a generous cap stands in for real pagination here rather than
     * a per-batch paginator, which would need to span partial rows.
     */
    protected function windowBatches()
    {
        return AdmissionWindow::with(['session', 'schoolClass', 'campus'])
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->groupBy('batch_uuid')
            ->map(function ($rows) {
                $first = $rows->first();

                return (object) [
                    'batch_uuid' => $first->batch_uuid,
                    'name' => $first->name,
                    'session' => $first->session,
                    'campus' => $first->campus,
                    'classNames' => $rows->pluck('schoolClass.name')->filter()->values(),
                    'application_fee_amount' => $first->application_fee_amount,
                    'acceptance_fee_amount' => $first->acceptance_fee_amount,
                    'requires_acceptance_fee' => $first->requiresAcceptanceFee(),
                    'opens_at' => $first->opens_at,
                    'closes_at' => $first->closes_at,
                    'is_active' => $first->is_active,
                    'is_open' => $rows->contains(fn ($w) => $w->isOpen()),
                    'sort_id' => $rows->max('id'),
                ];
            })
            ->sortByDesc('sort_id')
            ->values();
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
            'password_style' => ['required', Rule::in(['random', 'alphanumeric', 'numeric'])],
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
        Setting::put('admission_password_style', $data['password_style']);

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
        // row, so nothing else needed to change to support this. Rows
        // created together share one batch_uuid so the settings screen
        // can show and edit them as a single named group (live feedback
        // 2026-07-31: "it should not show all the windows per class but
        // all merged into one, we can give each application window a
        // name").
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'session_id' => ['required', 'exists:tenant.academic_sessions,id'],
            'school_class_id' => ['required', 'array', 'min:1'],
            'school_class_id.*' => ['exists:tenant.school_classes,id'],
            'campus_id' => ['nullable', 'exists:tenant.campuses,id'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after_or_equal:opens_at'],
            'application_fee_amount' => ['required', 'numeric', 'min:0'],
            // Acceptance fee is optional (live feedback 2026-07-31: "some
            // schools dont use it") — a blank submission stores 0, which
            // AdmissionWindow::requiresAcceptanceFee() treats as "not
            // configured", not a real free acceptance fee.
            'acceptance_fee_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $classIds = $data['school_class_id'];
        unset($data['school_class_id']);
        $data['acceptance_fee_amount'] = $data['acceptance_fee_amount'] ?? 0;
        $data['batch_uuid'] = (string) \Illuminate\Support\Str::uuid();

        $opened = 0;
        $skipped = [];
        foreach ($classIds as $classId) {
            $alreadyOpen = AdmissionWindow::where('session_id', $data['session_id'])
                ->where('school_class_id', $classId)
                ->where('campus_id', $data['campus_id'] ?? null)
                ->exists();
            // A class already has a window for this exact session/campus
            // (e.g. re-submitting after a partial failure, or picking a
            // class that was opened individually before) — skip it
            // rather than crash on the unique constraint, and tell the
            // admin which one(s) were skipped so nothing is silently lost.
            if ($alreadyOpen) {
                $skipped[] = SchoolClass::find($classId)?->name ?? "#{$classId}";

                continue;
            }
            AdmissionWindow::create($data + ['school_class_id' => $classId, 'is_active' => true]);
            $opened++;
        }

        $status = $opened > 0 ? ($opened > 1 ? "{$opened} admission windows opened." : 'Admission window opened.') : 'No new windows opened.';
        if ($skipped) {
            $status .= ' Already open, skipped: '.implode(', ', $skipped).'.';
        }

        return back()->with('status', $status);
    }

    public function updateWindowBatch(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.manage_settings');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after_or_equal:opens_at'],
            'application_fee_amount' => ['required', 'numeric', 'min:0'],
            'acceptance_fee_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $data['acceptance_fee_amount'] = $data['acceptance_fee_amount'] ?? 0;

        // Read via $request->route(), not a typed method parameter: see
        // the domain-wildcard gotcha documented in CLAUDE.md.
        $batchUuid = $request->route('batchUuid');
        $count = AdmissionWindow::where('batch_uuid', $batchUuid)->update($data);
        abort_if($count === 0, 404);

        return back()->with('status', 'Admission window updated.');
    }

    public function toggleWindowBatch(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.manage_settings');

        $batchUuid = $request->route('batchUuid');
        $window = AdmissionWindow::where('batch_uuid', $batchUuid)->firstOrFail();
        AdmissionWindow::where('batch_uuid', $batchUuid)->update(['is_active' => ! $window->is_active]);

        return back()->with('status', $window->is_active ? 'Window closed.' : 'Window reopened.');
    }

}
