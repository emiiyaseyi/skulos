<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Mail\AdmissionCredentialsMail;
use App\Models\School;
use App\Models\Tenant\AdmissionPayment;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Admissions module, Phase 1d: admin reviews an applicant's uploaded
 * application-fee evidence and confirms or rejects it. Verifying is what
 * actually issues the applicant's sign in credentials (Applicant::
 * issueCredentials()) and emails them — a genuine "the moment access is
 * granted" action, not just a record-keeping status flip, matching the
 * atomic decide()/applyDecision() shape PaymentController already
 * established for the exact same race condition (two staff acting on
 * the same submission at once must not both process it).
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class AdmissionFeeReviewController extends Controller
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
        $this->authorizeAction($request, 'admissions.decide');
        $school = $this->school($request);

        // Both fee types share one review queue (application fees, paid
        // at registration, and acceptance fees, paid once an offer is
        // made, Phase 1g) — the review action itself is identical either
        // way, only what verifying triggers afterward differs.
        $payments = AdmissionPayment::with('applicant')
            ->orderByRaw("status = 'pending' desc")
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('school.admissions.fee-review', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'payments' => $payments,
        ]);
    }

    public function evidence(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeAction($request, 'admissions.decide');
        $payment = AdmissionPayment::findOrFail($request->route('paymentId'));
        abort_unless($payment->evidence_path, 404);

        return Storage::disk('local')->response($payment->evidence_path);
    }

    public function verify(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->decide($request, AdmissionPayment::STATUS_VERIFIED);
    }

    public function reject(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->decide($request, AdmissionPayment::STATUS_REJECTED);
    }

    protected function decide(Request $request, string $status): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.decide');
        $school = $this->school($request);
        $payment = AdmissionPayment::with('applicant')->findOrFail($request->route('paymentId'));

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        $updated = AdmissionPayment::where('id', $payment->id)
            ->where('status', AdmissionPayment::STATUS_PENDING)
            ->update([
                'status' => $status,
                'verified_by_user_id' => Auth::id(),
                'verified_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

        if ($updated === 0) {
            return back()->with('error', 'This payment has already been processed.');
        }

        $feeLabel = $payment->fee_type === AdmissionPayment::TYPE_ACCEPTANCE ? 'Acceptance fee' : 'Application fee';
        $verb = $status === AdmissionPayment::STATUS_VERIFIED ? 'verified' : 'rejected';

        // Credentials are only ever issued once, off the FIRST (application)
        // fee — an acceptance fee (Phase 1g) verifies against an applicant
        // who is already signed in and mid-pipeline; re-issuing here would
        // silently invalidate their existing password for no reason.
        // Acceptance-fee-verified enrollment itself is a separate, deliberate
        // admin action (AdmissionPipelineController::convert()), not an
        // automatic side effect of this evidence check.
        if ($status === AdmissionPayment::STATUS_VERIFIED && $payment->fee_type === AdmissionPayment::TYPE_APPLICATION) {
            $applicant = $payment->applicant;
            $password = $applicant->issueCredentials();
            $applicant->advanceStage();

            if ($applicant->email) {
                Mail::to($applicant->email)->send(new AdmissionCredentialsMail($applicant, $school, $password));

                return back()->with('status', "Application fee verified. Sign in details emailed to {$applicant->email}.");
            }

            // No email on file (email is optional at registration, and no
            // SMS provider is wired up anywhere in this app yet) — the
            // password can only ever be shown this once, so hand it to
            // the staff member acting right now to relay by phone rather
            // than silently losing it.
            return back()->with('status', "Application fee verified. No email on file — share this password with {$applicant->name} directly, it will not be shown again: {$password}");
        }

        return back()->with('status', "{$feeLabel} marked as {$verb}.");
    }
}
