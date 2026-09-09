<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Section;
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
            'sectionLogos' => $school ? $this->sectionLogosFor($school) : [],
        ]);
    }

    /**
     * The distinct section logos to show on the login page, when the
     * school has turned "Show every section's logo on the login page"
     * on (user request 2026-09-09). Every section resolves to either
     * its own logo override or the school default, then those resolved
     * values are grouped: a section with no override of its own simply
     * merges into the same group as every other unconfigured section
     * (which naturally IS the school default), so "one section
     * overridden, the rest untouched" correctly shows exactly two marks
     * — the override and the default — with no need to track which
     * sections were deliberately left alone. Fewer than two distinct
     * marks means there is nothing meaningful to show side by side, so
     * the login page falls back to the single default logo either way.
     */
    protected function sectionLogosFor(School $school): array
    {
        if (! $school->show_all_section_logos_on_login) {
            return [];
        }

        $sections = Section::with('branding')->orderBy('sequence')->get();
        if ($sections->count() < 2) {
            return [];
        }

        $groups = [];
        foreach ($sections as $section) {
            $logo = $section->resolvedLogoPath($school);
            $key = $logo ?: '__none__';
            $groups[$key]['logo'] = $logo;
            $groups[$key]['labels'][] = $section->name;
        }

        if (count($groups) < 2) {
            return [];
        }

        return collect($groups)->map(fn ($g) => [
            'logo' => $g['logo'],
            'label' => implode(' & ', $g['labels']),
        ])->values()->all();
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
            $this->logFailedLogin($request, $school, $identifier, \App\Models\FailedLogin::REASON_NOT_MEMBER);
            throw ValidationException::withMessages(['email' => 'This account is not a member of this school.']);
        }
        // The login page asks "staff / parent / student" before showing
        // the credential form (see login-form.blade.php), but that choice
        // was previously cosmetic, it only steered the admission-number
        // lookup, so a staff member picking "Parent" or "Student" (or vice
        // versa) and typing their own real credentials still signed
        // straight in as themselves (user report 2026-07-15: "STAFF CAN
        // LOGIN WITH PARENT AND STUDENT LOGIN"). Enforced here instead:
        // the account's actual role in this school must belong to the
        // category the user selected, checked after credentials are
        // confirmed correct so a wrong-portal guess still reads as "those
        // details do not match" rather than leaking which portal an email
        // belongs to.
        if ($ok && $school && ! $user->is_super_admin && ! empty($data['as'])) {
            $roleSlug = optional($user->roleIn($school))->slug;
            $isFamilyRole = in_array($roleSlug, config('skulos.family_scoped_roles', []), true);
            $wrongPortal = match ($data['as']) {
                'staff' => $isFamilyRole,
                'parent', 'student' => $roleSlug !== $data['as'],
                default => false,
            };
            if ($wrongPortal) {
                RateLimiter::hit($key, 60);
                $this->logFailedLogin($request, $school, $identifier, \App\Models\FailedLogin::REASON_WRONG_PORTAL);
                throw ValidationException::withMessages(['email' => 'Those details do not match our records.']);
            }
        }
        if (! $ok) {
            RateLimiter::hit($key, 60);
            $reason = $user ? \App\Models\FailedLogin::REASON_BAD_PASSWORD : \App\Models\FailedLogin::REASON_NOT_FOUND;
            $this->logFailedLogin($request, $school, $identifier, $reason);
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
     * Never stores the attempted password (security: sensitive attributes
     * excluded from logs, matching the standing audit trail rule), only
     * the identifier string typed and why it failed.
     */
    protected function logFailedLogin(Request $request, ?\App\Models\School $school, string $identifier, string $reason): void
    {
        \App\Models\FailedLogin::create([
            'school_id' => $school?->id,
            'identifier' => $identifier,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
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

        return \App\Models\User::whereIn('phone', $this->phoneCandidates(preg_replace('/\D+/', '', $identifier)))->first();
    }

    /**
     * A phone number is stored exactly as an admin originally typed it
     * (no normalization at write time), so a sign-in attempt typed in a
     * different but equivalent form (a Nigerian number with the +234
     * country code where the stored value is the local 0-prefixed form,
     * or vice versa) previously never matched at all (tracker gap noted
     * 2026-07-12: "no change to normalizing a phone number typed with a
     * country code prefix"). Tries the exact digits first (unchanged
     * behavior for the common case), then the other equivalent form.
     *
     * @return list<string>
     */
    protected function phoneCandidates(string $digits): array
    {
        $candidates = [$digits];
        if (str_starts_with($digits, '234') && strlen($digits) === 13) {
            $candidates[] = '0'.substr($digits, 3);
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $candidates[] = '234'.substr($digits, 1);
        }

        return array_unique($candidates);
    }

    /**
     * Forgot password, for the platform admin login only ($school is
     * always null here, the school-scoped equivalent is
     * School\ProfileController::forgotPasswordForm()). Was entirely
     * missing (user report 2026-07-15), unlike the school login which
     * already had it. Platform admins always have a real email (it is
     * their platform-wide identifier), so unlike the school version there
     * is no "admin approves it" path, there is no admin above a super
     * admin to approve one, just the immediate emailed reset link.
     */
    public function forgotPasswordForm(): \Illuminate\View\View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $email = Str::lower($data['email']);
        $user = \App\Models\User::where('email', $email)->where('is_super_admin', true)->first();
        $generic = 'If that email matches a platform administrator account, a reset link has been sent.';

        if ($user) {
            $token = Str::random(64);
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => \Illuminate\Support\Facades\Hash::make($token), 'created_at' => now()]
            );

            $resetUrl = route('login.reset', ['token' => $token, 'email' => $email]);
            \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\PasswordResetLinkMail($user, null, $resetUrl));
        }

        return redirect()->route('login')->with('status', $generic);
    }

    public function resetViaTokenForm(Request $request): \Illuminate\View\View
    {
        return view('auth.reset-password', [
            'token' => $request->query('token'),
            'email' => $request->query('email'),
        ]);
    }

    public function resetViaToken(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $email = Str::lower($data['email']);
        $record = \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', $email)->first();

        $valid = $record
            && \Illuminate\Support\Facades\Hash::check($data['token'], $record->token)
            && now()->diffInMinutes($record->created_at) <= 60;

        if (! $valid) {
            return back()->withErrors(['token' => 'This reset link is invalid or has expired, request a new one.']);
        }

        $user = \App\Models\User::where('email', $email)->where('is_super_admin', true)->first();
        abort_unless($user, 404);

        $user->password = \Illuminate\Support\Facades\Hash::make($data['password']);
        $user->must_change_password = false;
        $user->save();

        \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', $email)->delete();

        return redirect()->route('login')->with('status', 'Your password has been reset, you can sign in now.');
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
