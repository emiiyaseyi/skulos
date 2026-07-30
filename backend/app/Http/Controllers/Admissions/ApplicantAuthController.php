<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Applicant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Sign in for the Admissions module's own "applicant" guard (user
 * directive 2026-07-30: "users will sign in with their admission number
 * or parent phone number and a generated password"). Deliberately
 * separate from Auth\LoginController: an applicant is not a school_user
 * member, its credentials are only ever issued once (ApplicationFee
 * verification, a later sub-phase), never admin-set/reset the way a real
 * member's password is.
 */
class ApplicantAuthController extends Controller
{
    public function show(Request $request): \Illuminate\View\View
    {
        $school = $request->attributes->get('school');

        return view('admissions.login', [
            'school' => $school,
            'accent' => $school?->branding()['color'] ?? null,
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        /** @var School $school */
        $school = $request->attributes->get('school');
        $identifier = trim($data['identifier']);
        $key = 'applicant:'.mb_strtolower($identifier).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'identifier' => 'Too many attempts. Please wait a moment and try again.',
            ]);
        }

        $digits = preg_replace('/\D+/', '', $identifier);
        $applicant = Applicant::where('admission_number', $identifier)
            ->orWhere('phone', $identifier)
            ->orWhere('phone', $digits)
            ->first();

        if (! $applicant || ! $applicant->password || ! Hash::check($data['password'], $applicant->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['identifier' => 'Those details do not match our records.']);
        }

        if (! $applicant->isActive()) {
            throw ValidationException::withMessages(['identifier' => 'Your application window has closed. Contact the school to reopen it.']);
        }

        RateLimiter::clear($key);
        Auth::guard('applicant')->login($applicant, remember: true);
        $request->session()->regenerate();

        return redirect()->route('school.admissions.dashboard', ['school' => $school->slug]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $school = $request->attributes->get('school');
        Auth::guard('applicant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('school.admissions.login', ['school' => $school->slug]);
    }
}
