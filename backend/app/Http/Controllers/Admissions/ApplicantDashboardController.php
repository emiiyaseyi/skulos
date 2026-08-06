<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AdmissionPayment;
use App\Models\Tenant\AdmissionWindow;
use App\Models\Tenant\Applicant;
use App\Services\Admissions\AdmissionAcceptanceService;
use App\Services\Media\ImageProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The applicant's own dashboard: application status, and, once offered
 * admission (Phase 1g), the acceptance fee upload — the same evidence-
 * then-admin-verify shape the application fee already uses, reachable
 * here rather than the pre-credentials signed status page since the
 * applicant has a real login by this point.
 */
class ApplicantDashboardController extends Controller
{
    public function index(Request $request): \Illuminate\View\View
    {
        $school = $request->attributes->get('school');
        $applicant = Auth::guard('applicant')->user();

        $acceptancePayment = AdmissionPayment::where('applicant_id', $applicant->id)
            ->where('fee_type', AdmissionPayment::TYPE_ACCEPTANCE)
            ->latest()
            ->first();

        $acceptanceWindow = $applicant->decision_status === Applicant::DECISION_OFFERED
            ? AdmissionWindow::where('session_id', $applicant->session_id)
                ->where('school_class_id', $applicant->school_class_id)
                ->where('campus_id', $applicant->campus_id)
                ->first()
            : null;

        // A pending_fee applicant can now sign in here directly (the
        // reference-number-as-bootstrap-password path, see Applicant::
        // canSignIn()), so the dashboard needs to offer the same
        // application-fee evidence upload the pre-credentials signed
        // status page already offers, not just a "continue the form"
        // link with no way to actually pay.
        $applicationPayment = null;
        $applicationWindow = null;
        if ($applicant->account_status === Applicant::STATUS_PENDING_FEE) {
            $applicationPayment = AdmissionPayment::where('applicant_id', $applicant->id)
                ->where('fee_type', AdmissionPayment::TYPE_APPLICATION)
                ->latest()
                ->first();
            $applicationWindow = AdmissionWindow::where('session_id', $applicant->session_id)
                ->where('school_class_id', $applicant->school_class_id)
                ->where('campus_id', $applicant->campus_id)
                ->first();
        }

        // SKULOS Assessment Hub: whether this applicant has any real
        // Admission Assessment to take right now — kept a plain count,
        // not the full list, since the dashboard only ever needs to
        // decide whether to show a link to the dedicated assessment
        // list (AdmissionAssessmentController::available()), not render
        // one here itself.
        $assessmentCount = \App\Models\Tenant\CbtAssessment::where('audience', \App\Models\Tenant\CbtAssessment::AUDIENCE_APPLICANT)
            ->where('is_active', true)->get()
            ->filter(fn ($assessment) => $assessment->isEligible($applicant))
            ->count();

        // Pipeline progress (user directive 2026-08-02): the applicant
        // themselves never had any visible confirmation of where they
        // actually stand in the school's own pipeline — e.g. passing an
        // admission assessment auto-advances them a stage (see
        // AdmissionAssessmentController::maybeAdvancePipelineOnPass()),
        // but nothing on THIS dashboard ever reflected that. A plain
        // stage list + current index is enough for a simple stepper,
        // same "labels not keys" shape Applicant::pipelineChain() itself
        // already uses.
        $pipelineStages = Applicant::pipelineChain();

        return view('admissions.dashboard', [
            'school' => $school,
            'accent' => $school?->branding()['color'] ?? null,
            'applicant' => $applicant,
            'acceptancePayment' => $acceptancePayment,
            'acceptanceWindow' => $acceptanceWindow,
            'acceptanceFeeRequired' => $acceptanceWindow?->requiresAcceptanceFee() ?? false,
            'applicationPayment' => $applicationPayment,
            'applicationWindow' => $applicationWindow,
            'bankAccounts' => \App\Models\Tenant\BankAccount::active()->orderBy('sort_order')->get(),
            'assessmentCount' => $assessmentCount,
            'pipelineStages' => $pipelineStages,
        ]);
    }

    public function submitApplicationFee(Request $request, ImageProcessingService $images): \Illuminate\Http\RedirectResponse
    {
        $school = $request->attributes->get('school');
        $applicant = Auth::guard('applicant')->user();
        abort_unless($applicant->account_status === Applicant::STATUS_PENDING_FEE, 422, 'This application has already moved past the fee stage.');

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

    public function submitAcceptanceFee(Request $request, ImageProcessingService $images): \Illuminate\Http\RedirectResponse
    {
        $school = $request->attributes->get('school');
        $applicant = Auth::guard('applicant')->user();
        abort_unless($applicant->decision_status === Applicant::DECISION_OFFERED, 422, 'You have not been offered admission yet.');

        $window = AdmissionWindow::where('session_id', $applicant->session_id)
            ->where('school_class_id', $applicant->school_class_id)
            ->where('campus_id', $applicant->campus_id)
            ->firstOrFail();
        abort_unless($window->requiresAcceptanceFee(), 422, 'This school does not charge an acceptance fee.');

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
            'fee_type' => AdmissionPayment::TYPE_ACCEPTANCE,
            'amount' => $window->acceptance_fee_amount,
            'bank_name' => $data['bank_name'] ?? null,
            'reference' => $data['reference'] ?? null,
            'evidence_path' => $evidencePath,
        ]);

        return back()->with('status', 'Acceptance fee evidence received. The school will confirm it shortly.');
    }

    /**
     * Automated admissions pipeline (2026-08-06): the printable admission
     * letter, viewable once an offer exists — still shown after
     * acceptance/conversion so the applicant can always retrieve it, not
     * just in the narrow window before they respond.
     */
    public function admissionLetter(Request $request, \App\Services\Admissions\AdmissionLetterContentService $letterContent): \Illuminate\View\View
    {
        $school = $request->attributes->get('school');
        $applicant = Auth::guard('applicant')->user()->load('schoolClass', 'session');

        abort_unless(
            in_array($applicant->decision_status, [Applicant::DECISION_OFFERED, Applicant::DECISION_CONVERTED], true),
            404
        );

        return view('admissions.letter', [
            'school' => $school,
            'accent' => $school?->branding()['color'] ?? null,
            'applicant' => $applicant,
            'letterContent' => $letterContent->render($school, $applicant),
            'backRoute' => route('school.admissions.dashboard', ['school' => $school->slug]),
        ]);
    }

    /**
     * The parent's explicit acceptance of an offer (user directive
     * 2026-08-06: "require the parent to accept the offer of admission").
     * Creates the dormant Student + provisions both logins immediately —
     * see AdmissionAcceptanceService for the full reasoning.
     */
    public function acceptOffer(Request $request, AdmissionAcceptanceService $acceptance): \Illuminate\Http\RedirectResponse
    {
        $school = $request->attributes->get('school');
        $applicant = Auth::guard('applicant')->user();
        abort_unless($applicant->decision_status === Applicant::DECISION_OFFERED && ! $applicant->offer_response, 422, 'This offer cannot be accepted right now.');

        $acceptance->accept($school, $applicant);

        return redirect()->route('school.admissions.dashboard', ['school' => $school->slug])
            ->with('status', 'Offer accepted. You can now pay school fees for your child below.');
    }

    public function declineOffer(Request $request, AdmissionAcceptanceService $acceptance): \Illuminate\Http\RedirectResponse
    {
        $school = $request->attributes->get('school');
        $applicant = Auth::guard('applicant')->user();
        abort_unless($applicant->decision_status === Applicant::DECISION_OFFERED && ! $applicant->offer_response, 422, 'This offer cannot be declined right now.');

        $acceptance->decline($applicant);

        return redirect()->route('school.admissions.dashboard', ['school' => $school->slug])
            ->with('status', 'You have declined this offer of admission.');
    }
}
