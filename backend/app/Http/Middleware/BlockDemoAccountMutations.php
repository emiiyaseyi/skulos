<?php

namespace App\Http\Middleware;

use App\Models\School;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Demo account" support (2026-08-15, user request: greenfield used as a
 * live demo school for prospective customers, one real account per major
 * role that must never be able to change anything, including its own
 * password, even on first login). Deliberately a blanket block on every
 * non-GET request rather than relying on Rbac module permissions alone
 * — several real mutations (changing your own password, editing your
 * own profile) are never gated through Rbac::check() at all, since any
 * authenticated member is normally allowed to do those. Scoped to the
 * school_user pivot row (school_user.is_demo_readonly), not the User
 * itself, per the explicit "this is only for this school" instruction.
 * Runs after EnsureSchoolMember, so $request->attributes->get('school')
 * and Auth::user() are both already confirmed valid.
 */
class BlockDemoAccountMutations
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        /** @var School|null $school */
        $school = $request->attributes->get('school');
        $user = Auth::user();

        if ($school && $user && ! $user->is_super_admin) {
            $isDemo = DB::table('school_user')
                ->where('school_id', $school->id)
                ->where('user_id', $user->id)
                ->value('is_demo_readonly');

            if ($isDemo) {
                return back()->with('error', 'This is a read-only demo account and cannot make changes.');
            }
        }

        return $next($request);
    }
}
