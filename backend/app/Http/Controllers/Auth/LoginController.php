<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auditing\Recorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Platform sign in (session based). Throttled by identifier + IP to
 * resist credential stuffing. A school-scoped login page posts here too,
 * with a hidden "school" slug, so a user is verified to belong to that
 * school. The identifier field accepts either an email address or a
 * phone number (user directive 2026-07-12: "login details can be email
 * or phone number with the password"), matching the fact that a school's
 * own `staff_identifier`/`parent_identifier` setting can already be
 * phone, previously sign in was hard coded to email regardless of that
 * setting. The form field is still named/keyed "email" throughout (view,
 * validation, session error bag) purely to avoid touching every existing
 * caller and test, its accepted values are broader than the name now
 * implies.
 */
class LoginController extends Controller
{
    public function show(Request $request)
    {
        $school = $request->attributes->get('school');

        return view('auth.login', [
            'school' => $school,
            'accent' => $school?->branding()['color'] ?? null,
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
            'as' => ['nullable', 'string', 'in:staff,parent,student'],
        ]);

        $identifier = trim($data['email']);
        $key = Str::lower($identifier).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Please wait a moment and try again.',
            ]);
        }

        $school = $request->attributes->get('school');
        $user = $this->resolveUser($identifier, $data['as'] ?? null, $school);

        // Verify credentials and, on a school portal, membership BEFORE
        // authenticating, so a non member is never signed in even briefly.
        $ok = $user && \Illuminate\Support\Facades\Hash::check($data['password'], $user->password);
        if ($ok && $school && ! $user->is_super_admin && ! $user->isMemberOf($school)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'This account is not a member of this school.']);
        }
        if (! $ok) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Those details do not match our records.']);
        }

        RateLimiter::clear($key);
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        app(Recorder::class)->record('auth.login', $user);

        // Every first-time login on a system-generated password (created
        // by an admin, or reset by one) must be changed before reaching
        // anywhere else (user directive 2026-07-13: "all first time users
        // must change the default password when they login in for the
        // first time"). The change-password route itself, and logout, are
        // the only things excluded from this gate, see
        // RequirePasswordChange.
        if ($user->must_change_password) {
            return redirect()->route($school ? 'school.password.change' : 'password.change', $school ? ['school' => $school->slug] : []);
        }

        // Send users to where they belong: their school's admin-configured
        // landing page for their role (user request 2026-07-13:
        // "admin-configurable landing page per staff/student/parent
        // (default Dashboard)"), Dashboard if nothing is configured or the
        // user has no role in this school (a platform admin impersonating
        // via support access, for instance).
        if ($school) {
            $role = $user->is_super_admin ? null : $user->roleIn($school);
            $routeName = \App\Http\Controllers\School\LandingPageController::landingRouteFor($role);

            return redirect()->route($routeName, ['school' => $school->slug]);
        }

        return redirect()->intended(Auth::user()->is_super_admin ? route('admin.dashboard') : '/');
    }

    /**
     * A student's identifier can also be their admission number, which
     * has no fixed format (no reliable way to distinguish it from a
     * phone number by shape alone), so the login page asks which of
     * staff/parent/student is signing in first (user directive
     * 2026-07-13) and this only tries the admission-number lookup when
     * $as is explicitly "student". An email is always unambiguous
     * (contains "@"), tried first regardless of $as.
     */
    protected function resolveUser(string $identifier, ?string $as, ?\App\Models\School $school): ?\App\Models\User
    {
        if (str_contains($identifier, '@')) {
            return \App\Models\User::where('email', Str::lower($identifier))->first();
        }

        if ($as === 'student' && $school) {
            $student = \App\Models\Tenant\Student::where('admission_number', $identifier)->first();
            if ($student && $student->user_id) {
                return \App\Models\User::find($student->user_id);
            }
        }

        return \App\Models\User::where('phone', preg_replace('/\D+/', '', $identifier))->first();
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::user();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            app(Recorder::class)->record('auth.logout', $user);
        }

        return redirect('/login');
    }
}
