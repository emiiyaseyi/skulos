<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\House;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Services\Admissions\ClassTeacherOnboardingService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Automated admissions pipeline (user directive 2026-08-06): "the class
 * teacher is to onboard the child to the class after which the child is
 * added to the class like other children in the class." Scoped to the
 * acting teacher's own class arm(s) (class_teacher_staff_id), the same
 * lookup RoleDashboard::classTeam() already uses — a class teacher only
 * ever sees their own dormant admissions here, never the whole school's.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class ClassOnboardingController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request, string $permission): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), $permission),
            403,
            'You do not have permission for this.'
        );
    }

    /** The acting user's own class arm ids as class teacher — empty for anyone not actually a class teacher of any arm. */
    protected function ownedClassArmIds(): \Illuminate\Support\Collection
    {
        $staff = Staff::where('user_id', Auth::id())->first();

        return $staff ? ClassArm::where('class_teacher_staff_id', $staff->id)->pluck('id') : collect();
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'admissions.onboard');
        $school = $this->school($request);
        $armIds = $this->ownedClassArmIds();

        $students = Student::pendingEnrollment()
            ->whereIn('current_class_arm_id', $armIds)
            ->with('currentClassArm.schoolClass')
            ->orderByDesc('created_at')
            ->get();

        return view('school.admissions.onboarding.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'students' => $students,
        ]);
    }

    public function show(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'admissions.onboard');
        $school = $this->school($request);
        $student = Student::pendingEnrollment()
            ->whereIn('current_class_arm_id', $this->ownedClassArmIds())
            ->with(['currentClassArm.schoolClass', 'guardians'])
            ->findOrFail($request->route('studentId'));

        return view('school.admissions.onboarding.show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'student' => $student,
            'houses' => House::active()->orderBy('name')->get(),
        ]);
    }

    public function onboard(Request $request, ClassTeacherOnboardingService $onboarding): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'admissions.onboard');
        $school = $this->school($request);
        $student = Student::pendingEnrollment()
            ->whereIn('current_class_arm_id', $this->ownedClassArmIds())
            ->findOrFail($request->route('studentId'));

        $data = $request->validate(['house_id' => ['nullable', 'exists:tenant.houses,id']]);

        $onboarding->onboard($school, $student, Auth::id(), $data['house_id'] ?? null);

        return redirect()->route('school.admissions.onboarding.index', ['school' => $school->slug])
            ->with('status', "{$student->fullName()} has been onboarded and added to the class.");
    }
}
