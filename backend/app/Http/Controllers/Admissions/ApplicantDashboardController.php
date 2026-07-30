<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The applicant's own dashboard. A minimal status placeholder for now
 * (Phase 1a: identity/auth only) — the form/document/pipeline-status
 * pieces land here in later Admissions sub-phases, this controller
 * expands rather than being replaced.
 */
class ApplicantDashboardController extends Controller
{
    public function index(Request $request): \Illuminate\View\View
    {
        $school = $request->attributes->get('school');
        $applicant = Auth::guard('applicant')->user();

        return view('admissions.dashboard', [
            'school' => $school,
            'accent' => $school?->branding()['color'] ?? null,
            'applicant' => $applicant,
        ]);
    }
}
