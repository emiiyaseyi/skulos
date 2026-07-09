<?php

namespace App\Http\Middleware;

use App\Models\School;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards authenticated school routes. Because sessions are shared across
 * subdomains (for single sign on), being logged in is not enough: the user
 * must actually be a member of THIS school, or be a platform super admin.
 * Otherwise a user of one school could open another school's subdomain and
 * see its data. A non member is bounced to this school's login.
 */
class EnsureSchoolMember
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var School|null $school */
        $school = $request->attributes->get('school');
        $user = Auth::user();

        if (! $school || ! $user) {
            abort(404);
        }

        if (! $user->is_super_admin && ! $user->isMemberOf($school)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('school.login', ['school' => $school->slug])
                ->withErrors(['email' => 'Please sign in with an account that belongs to this school.']);
        }

        return $next($request);
    }
}
