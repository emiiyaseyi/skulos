<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AdmissionRecoveryRequest;
use App\Models\Tenant\Applicant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Admissions module (user directive 2026-07-31: "what happens if
 * applicant forget the reference number and password, applicant should
 * be able to request for password or reference number check up, the
 * system asks them a few questions about them... if all are correct,
 * the system shows them the applicant reference number and password").
 * Two stages: an automatic identity check (this controller's own
 * verify()), and — only if that doesn't match — a manual, admin-reviewed
 * fallback (request()/submitRequest()), reviewed from School\
 * AdmissionRecoveryReviewController.
 */
class AdmissionRecoveryController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    public function show(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);

        return view('admissions.recover', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
        ]);
    }

    public function verify(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'date_of_birth' => ['required', 'date'],
            'phone_or_email' => ['required', 'string', 'max:150'],
        ]);

        $key = 'admission-recovery:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 8)) {
            throw ValidationException::withMessages(['first_name' => 'Too many attempts. Please wait a moment and try again.']);
        }
        RateLimiter::hit($key, 300);

        $applicant = Applicant::matchForRecovery($data['first_name'], $data['last_name'], $data['date_of_birth'], $data['phone_or_email']);

        return view('admissions.recover', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'matched' => $applicant,
            'checked' => true,
        ]);
    }

    public function requestForm(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);

        return view('admissions.recover-request', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
        ]);
    }

    public function submitRequest(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        $data = $request->validate([
            'remembered_name' => ['nullable', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:1000'],
            'email' => ['required', 'email', 'max:150'],
        ]);

        AdmissionRecoveryRequest::create($data);

        return redirect()->route('school.admissions.recover.show', ['school' => $school->slug])
            ->with('status', 'Your request has been sent to the school. If it matches an application on file, you will receive an email with your details.');
    }
}
