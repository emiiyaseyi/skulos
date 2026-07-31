<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AdmissionPayment;
use App\Models\Tenant\AdmissionWindow;
use App\Models\Tenant\Applicant;
use App\Services\Media\ImageProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Admissions module, Phase 1c: "Seek Admission" public entry point and
 * self-registration. Deliberately a single combined screen for now, not
 * a multi-page wizard: the only real information to collect at this
 * stage is which open window and basic contact details, the rich
 * per-school custom application form (sections/multi-step/file uploads)
 * lands in Phase 1e, filled in from the applicant's own dashboard after
 * they register here, not before — building a hollow multi-step shell
 * now with nothing real in most of the steps would be exactly the kind
 * of half-finished implementation this project avoids.
 */
class PublicApplicationController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    /**
     * Groups every currently open AdmissionWindow row into its own
     * admin-named batch, each carrying its own list of classes (live
     * feedback 2026-07-31: "the application window should not show the
     * classes with it, there should be another field for applicant to
     * select the class they are applying for"). Mirrors
     * AdmissionSettingsController::windowBatches(), applicant-facing side.
     */
    protected function openBatches(): \Illuminate\Support\Collection
    {
        return AdmissionWindow::with(['session', 'schoolClass', 'campus'])
            ->where('is_active', true)
            ->get()
            ->filter(fn (AdmissionWindow $w) => $w->isOpen())
            ->groupBy('batch_uuid')
            ->map(function ($rows) {
                $first = $rows->first();

                return (object) [
                    'batch_uuid' => $first->batch_uuid,
                    'name' => $first->name ?: (($first->session?->name ?: 'Admissions').' intake'),
                    'session' => $first->session,
                    'campus' => $first->campus,
                    'application_fee_amount' => $first->application_fee_amount,
                    'classes' => $rows->map(fn ($w) => (object) [
                        'id' => $w->school_class_id,
                        'name' => $w->schoolClass?->name,
                        'section' => $w->schoolClass?->section?->name,
                    ])->values(),
                ];
            })
            ->values();
    }

    public function start(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);

        return view('admissions.apply', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'batches' => $this->openBatches(),
        ]);
    }

    public function register(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        $batches = $this->openBatches();

        $data = $request->validate([
            'batch_uuid' => ['required', 'string', \Illuminate\Validation\Rule::in($batches->pluck('batch_uuid')->all())],
            'school_class_id' => ['required', 'integer'],
            'first_name' => ['required', 'string', 'max:80'],
            'other_names' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $window = AdmissionWindow::with('schoolClass')
            ->where('batch_uuid', $data['batch_uuid'])
            ->where('school_class_id', $data['school_class_id'])
            ->first();
        abort_if(! $window, 422, 'Choose a class from the selected admission window.');
        abort_unless($window->isOpen(), 422, 'This admission window is no longer open.');

        $applicant = Applicant::forceCreate([
            'name' => trim(implode(' ', array_filter([$data['first_name'], $data['other_names'] ?? null, $data['last_name']]))),
            'first_name' => $data['first_name'],
            'other_names' => $data['other_names'] ?? null,
            'last_name' => $data['last_name'],
            'date_of_birth' => $data['date_of_birth'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'],
            'session_id' => $window->session_id,
            'campus_id' => $window->campus_id,
            'school_class_id' => $window->school_class_id,
            // This reference doubles as the applicant's own sign-in
            // identifier (admission number or parent phone, see
            // ApplicantAuthController) and, if the application is later
            // accepted, becomes the student's real admission number at
            // conversion (Phase 1g). Format is admin-configurable, see
            // Applicant::nextReferenceNumber().
            'admission_number' => Applicant::nextReferenceNumber($window->schoolClass),
            // Always the first stage in the school's own pipeline order
            // (a school can freely add/reorder stages now, so there is no
            // fixed "application_started" key to look up anymore — see
            // Applicant::pipelineChain()'s own docblock).
            'pipeline_stage' => 0,
            'account_status' => Applicant::STATUS_PENDING_FEE,
        ]);

        // Signed, not a plain id in the URL (audit follow-up while
        // building Phase 1d): an applicant has no account/password yet at
        // this point, so without a signature anyone could enumerate
        // /apply/{id} to read another applicant's name/reference, or
        // worse, once the fee-evidence upload lands on this same page,
        // submit fake payment proof against someone else's application.
        // 30 days matches the platform default application window, not
        // tied to a school's own configured TTL (that setting only
        // governs the account once it's actually active).
        $statusUrl = URL::temporarySignedRoute('school.admissions.apply.status', now()->addDays(30), [
            'school' => $school->slug,
            'applicant' => $applicant->id,
        ]);

        return redirect($statusUrl);
    }

    public function status(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        // Read via $request->route(), not a typed method parameter: this
        // route sits under the {school} domain wildcard, so an untyped
        // scalar controller parameter resolves positionally against the
        // full route parameter list (domain param included), not by
        // name — the recurring gotcha documented in CLAUDE.md.
        $applicant = Applicant::findOrFail($request->route('applicant'));
        $feePayment = AdmissionPayment::where('applicant_id', $applicant->id)
            ->where('fee_type', AdmissionPayment::TYPE_APPLICATION)
            ->latest()
            ->first();

        return view('admissions.status', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'applicant' => $applicant,
            'feePayment' => $feePayment,
            'bankAccounts' => \App\Models\Tenant\BankAccount::active()->orderBy('sort_order')->get(),
        ]);
    }

    public function submitFeeEvidence(Request $request, ImageProcessingService $images): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        $applicant = Applicant::findOrFail($request->route('applicant'));
        abort_if($applicant->account_status !== Applicant::STATUS_PENDING_FEE, 422, 'This application has already moved past the fee stage.');

        $window = AdmissionWindow::where('session_id', $applicant->session_id)
            ->where('school_class_id', $applicant->school_class_id)
            ->where('campus_id', $applicant->campus_id)
            ->firstOrFail();

        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.config('skulos.upload_limits.admission_fee_evidence_kb')],
        ]);

        $file = $request->file('evidence');
        $evidencePath = $file->getClientOriginalExtension() === 'pdf'
            ? $file->storeAs("admissions/{$school->slug}/{$applicant->id}", Str::random(24).'.pdf', 'local')
            : $images->toWebp($file, "admissions/{$school->slug}/{$applicant->id}", disk: 'local');

        AdmissionPayment::create([
            'applicant_id' => $applicant->id,
            'fee_type' => AdmissionPayment::TYPE_APPLICATION,
            'amount' => $window->application_fee_amount,
            'bank_name' => $data['bank_name'] ?? null,
            'reference' => $data['reference'] ?? null,
            'evidence_path' => $evidencePath,
        ]);

        return back()->with('status', 'Payment evidence received. The school will confirm it shortly.');
    }
}
