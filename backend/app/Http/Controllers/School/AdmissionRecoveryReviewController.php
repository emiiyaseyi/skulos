<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Mail\AdmissionRecoveryMail;
use App\Models\School;
use App\Models\Tenant\AdmissionRecoveryRequest;
use App\Models\Tenant\Applicant;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Admissions module (user directive 2026-07-31): admin reviews a manual
 * "I forgot my details and the automatic check didn't match" request,
 * picks which real applicant it actually belongs to (a human judgment
 * call — the requester only gave a free-text description, not a lookup
 * key), and approving emails that applicant's reference number/password
 * to the requester's given address.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class AdmissionRecoveryReviewController extends Controller
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

        $requests = AdmissionRecoveryRequest::with('matchedApplicant')
            ->orderByRaw("status = 'pending' desc")
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('school.admissions.recovery-review', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'requests' => $requests,
            'applicants' => Applicant::orderByDesc('created_at')->limit(200)->get(['id', 'name', 'admission_number', 'phone']),
        ]);
    }

    public function approve(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.decide');
        $school = $this->school($request);
        $recoveryRequest = AdmissionRecoveryRequest::findOrFail($request->route('requestId'));

        $data = $request->validate([
            'matched_applicant_id' => ['required', 'exists:tenant.applicants,id'],
        ]);
        $applicant = Applicant::findOrFail($data['matched_applicant_id']);

        $updated = AdmissionRecoveryRequest::where('id', $recoveryRequest->id)
            ->where('status', AdmissionRecoveryRequest::STATUS_PENDING)
            ->update([
                'status' => AdmissionRecoveryRequest::STATUS_APPROVED,
                'matched_applicant_id' => $applicant->id,
                'reviewed_by_user_id' => Auth::id(),
                'reviewed_at' => now(),
            ]);

        if ($updated === 0) {
            return back()->with('error', 'This request has already been processed.');
        }

        Mail::to($recoveryRequest->email)->send(new AdmissionRecoveryMail($applicant, $school));

        return back()->with('status', "Details emailed to {$recoveryRequest->email}.");
    }

    public function reject(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.decide');
        $recoveryRequest = AdmissionRecoveryRequest::findOrFail($request->route('requestId'));

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        $updated = AdmissionRecoveryRequest::where('id', $recoveryRequest->id)
            ->where('status', AdmissionRecoveryRequest::STATUS_PENDING)
            ->update([
                'status' => AdmissionRecoveryRequest::STATUS_REJECTED,
                'reviewed_by_user_id' => Auth::id(),
                'reviewed_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

        if ($updated === 0) {
            return back()->with('error', 'This request has already been processed.');
        }

        return back()->with('status', 'Request rejected.');
    }
}
