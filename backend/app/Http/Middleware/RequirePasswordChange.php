<?php

namespace App\Http\Middleware;

use App\Models\School;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user signed in on a system-generated or admin-reset password must
 * change it before reaching anywhere else (user directive 2026-07-13:
 * "all first time users must change the default password when they
 * login in for the first time"). The change-password route itself and
 * logout are excluded, everything else on a school-scoped request
 * bounces here until it is done.
 */
class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        // A platform admin impersonating this account for support is not
        // forced through someone else's password change gate.
        if ($request->session()->has('impersonator_id')) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), ['school.password.change', 'school.password.change.update'], true)) {
            return $next($request);
        }

        /** @var School|null $school */
        $school = $request->attributes->get('school');

        return redirect()->route('school.password.change', ['school' => $school->slug]);
    }
}
