<?php

namespace App\Http\Middleware;

use App\Models\Tenant\Applicant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the applicant dashboard/application routes. An applicant can be
 * authenticated (a valid session under the 'applicant' guard) but still
 * unable to use the dashboard: expired past their temporary window
 * (auto-archived, same as the nightly admissions:archive-expired-
 * applicants sweep), or manually locked_out/archived by an admin.
 * Checked on every request rather than only at login, since an
 * applicant's window can lapse mid session.
 */
class EnsureApplicantActive
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Applicant|null $applicant */
        $applicant = Auth::guard('applicant')->user();

        if (! $applicant) {
            return redirect()->route('school.admissions.login', ['school' => $request->route('school')]);
        }

        if ($applicant->account_status === Applicant::STATUS_ACTIVE && $applicant->expires_at?->isPast()) {
            $applicant->forceFill(['account_status' => Applicant::STATUS_ARCHIVED])->save();
        }

        if (! $applicant->canSignIn()) {
            Auth::guard('applicant')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('school.admissions.login', ['school' => $request->route('school')])
                ->withErrors(['identifier' => 'Your application window has closed. Contact the school to reopen it.']);
        }

        return $next($request);
    }
}
