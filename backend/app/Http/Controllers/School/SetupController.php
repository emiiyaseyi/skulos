<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\House;
use App\Models\Tenant\Section;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Guided setup wizard (user request, tracker section 13/15): one screen
 * that walks a new school through the one-time setup pieces that already
 * existed as separate, easy-to-miss screens (session/term, class
 * structure, houses, assessment structure). Deliberately does not
 * duplicate any of that logic, every action on this page posts to the
 * same existing routes those standalone screens already use, this is a
 * curated front door onto them, not a second implementation. Each step
 * reads as "done" straight from whether the relevant records exist, no
 * separate progress-tracking flag to fall out of sync with reality.
 */
class SetupController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        $rbac = app(Rbac::class);
        $user = Auth::user();

        abort_unless($rbac->check($user, $school, 'academics.view'), 403, 'You do not have permission for this.');

        $hasSession = AcademicSession::exists();
        $hasClasses = Section::whereHas('classes')->exists();
        $hasHouses = House::exists();
        $hasAssessment = AssessmentComponent::exists();

        return view('school.setup', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'hasSession' => $hasSession,
            'currentSession' => $hasSession ? AcademicSession::current()->first() : null,
            'hasClasses' => $hasClasses,
            'classCount' => Section::withCount('classes')->get()->sum('classes_count'),
            'hasHouses' => $hasHouses,
            'houseCount' => House::count(),
            'hasAssessment' => $hasAssessment,
            'classCatalogs' => config('skulos.default_class_structure'),
            'allDone' => $hasSession && $hasClasses && $hasHouses && $hasAssessment,
            'canManageAcademics' => $rbac->check($user, $school, 'academics.create'),
            'canManageStudentSettings' => $rbac->check($user, $school, 'students.manage_settings'),
            'canApproveResults' => $rbac->check($user, $school, 'examinations.approve_results'),
        ]);
    }
}
