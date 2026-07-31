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

    public function start(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);

        $windows = AdmissionWindow::with(['session', 'schoolClass', 'campus'])
            ->where('is_active', true)
            ->get()
            ->filter(fn (AdmissionWindow $w) => $w->isOpen())
            ->values();

        return view('admissions.apply', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'windows' => $windows,
        ]);
    }

    public function register(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);

        $windowIds = AdmissionWindow::where('is_active', true)->pluck('id')->all();
        $data = $request->validate([
            'admission_window_id' => ['required', 'integer', \Illuminate\Validation\Rule::in($windowIds)],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $window = AdmissionWindow::findOrFail($data['admission_window_id']);
        abort_unless($window->isOpen(), 422, 'This admission window is no longer open.');

        $chain = Applicant::pipelineChain();
        $applicant = Applicant::forceCreate([
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'date_of_birth' => $data['date_of_birth'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'],
            'session_id' => $window->session_id,
            'campus_id' => $window->campus_id,
            'school_class_id' => $window->school_class_id,
            // The staff-numbering sequence pattern used elsewhere (Staff::
            // withTrashed()->count()+1, sprintf('STF/%04d', ...)); Applicant
            // is never soft-deleted, so a plain count() is enough. This
            // reference doubles as the applicant's own sign-in identifier
            // (admission number or parent phone, see ApplicantAuthController)
            // and, if the application is later accepted, becomes the
            // student's real admission number at conversion (Phase 1g).
            'admission_number' => sprintf('APP/%04d', Applicant::count() + 1),
            'pipeline_stage' => array_search('application_started', $chain, true) ?: 0,
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
