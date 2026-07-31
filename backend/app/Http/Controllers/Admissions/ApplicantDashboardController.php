<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AdmissionPayment;
use App\Models\Tenant\AdmissionWindow;
use App\Models\Tenant\Applicant;
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
}
