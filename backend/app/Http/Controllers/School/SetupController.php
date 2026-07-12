<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\House;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Guided setup wizard (user request, tracker section 13/15, made into a
 * genuine step-by-step flow 2026-07-12 per the "To Do List 2" doc's item 1,
 * "School Setup Wizard"). One screen at a time rather than a single long
 * checklist, for a first-time admin who has never seen the product before.
 * Deliberately does not duplicate any setup logic, every action on this
 * page posts to the same existing routes those standalone screens already
 * use, this is a curated front door onto them, not a second
 * implementation. Each step reads as "done" straight from whether the
 * relevant records exist, no separate progress-tracking flag to fall out
 * of sync with reality. Curriculum type and Fees, both present in the
 * source "To Do List 2" wizard sketch, are deliberately not steps here,
 * neither has a real setting/module behind it yet (no curriculum-type
 * field exists; Finance is still deferred scope per CLAUDE.md).
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
        $hasStudents = Student::exists();
        $hasStaff = Staff::exists();

        $allRealStepsDone = (bool) ($school->brand_color || $school->logo_path || $school->welcome_headline)
            && $hasSession && $hasClasses && $hasHouses && $hasAssessment && $hasStudents && $hasStaff;

        $steps = [
            ['key' => 'welcome', 'title' => 'Welcome', 'done' => true],
            ['key' => 'branding', 'title' => 'Branding', 'done' => (bool) ($school->brand_color || $school->logo_path || $school->welcome_headline)],
            ['key' => 'session', 'title' => 'Session & term', 'done' => $hasSession],
            ['key' => 'classes', 'title' => 'Class structure', 'done' => $hasClasses],
            ['key' => 'houses', 'title' => 'Houses', 'done' => $hasHouses],
            ['key' => 'assessment', 'title' => 'Assessment structure', 'done' => $hasAssessment],
            ['key' => 'students', 'title' => 'Students', 'done' => $hasStudents],
            ['key' => 'staff', 'title' => 'Staff', 'done' => $hasStaff],
            // "Done" only reads as complete on the rail once every real step above actually is, so it never
            // shows a checkmark that overstates progress while steps are still outstanding.
            ['key' => 'done', 'title' => 'Done', 'done' => $allRealStepsDone],
        ];

        $requested = (int) $request->query('step', 0);
        if ($requested >= 1 && $requested <= count($steps)) {
            $current = $requested - 1;
        } else {
            // Land on the first not-yet-done step, or the final "Done" step if everything is already set up.
            $current = collect($steps)->search(fn ($s) => ! $s['done']);
            $current = $current === false ? count($steps) - 1 : $current;
        }

        return view('school.setup', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'steps' => $steps,
            'current' => $current,
            'currentSession' => $hasSession ? AcademicSession::current()->first() : null,
            'classCount' => Section::withCount('classes')->get()->sum('classes_count'),
            'houseCount' => House::count(),
            'studentCount' => Student::count(),
            'staffCount' => Staff::count(),
            'classCatalogs' => config('skulos.default_class_structure'),
            'allDone' => collect($steps)->every(fn ($s) => $s['done']),
            'canManageAcademics' => $rbac->check($user, $school, 'academics.create'),
            'canManageStudentSettings' => $rbac->check($user, $school, 'students.manage_settings'),
            'canApproveResults' => $rbac->check($user, $school, 'examinations.approve_results'),
            'canManageBranding' => $rbac->check($user, $school, 'school_info.update'),
        ]);
    }
}
