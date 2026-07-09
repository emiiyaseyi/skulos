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
 * Platform sign in (session based). Throttled by email + IP to resist
 * credential stuffing. A school-scoped login page posts here too, with a
 * hidden "school" slug, so a user is verified to belong to that school.
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
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower($data['email']);
        $key = $email.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Please wait a moment and try again.',
            ]);
        }

        $school = $request->attributes->get('school');
        $user = \App\Models\User::where('email', $email)->first();

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

        // Send users to where they belong.
        if ($school) {
            return redirect()->route('school.dashboard', ['school' => $school->slug]);
        }

        return redirect()->intended(Auth::user()->is_super_admin ? route('admin.dashboard') : '/');
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
