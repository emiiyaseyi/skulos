<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Section;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Student;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Parent Portal, browsed the same way as Student Portal (user directive
 * 2026-07-16: "WHY IS PARENT PORTAL HAVING SAME LINK AS STUDENT PORTAL -
 * FIX IT, IT SHOULD BE CATEGORIZED PROPERLY FROM SECTION, CLASS THEN TO
 * THE PARENTS DETAILS AS WE HAVE IT UNDER STUDENT PORTAL"). Previously
 * `parent_portal` was aliased straight to `school.students` (the same
 * screen Student Portal uses), a stand-in from when this nav entry
 * falsely showed "Soon" (see the 2026-07-15 "Live QA fixes" tracker
 * entry) rather than a real, distinct destination. Section -> Class ->
 * that class's students with their guardian(s), each guardian name
 * linking to the student record where the real guardian management
 * (add/edit/remove, create a login) already lives, not duplicated here.
 *
 * "NOT THAT WE HAVE A SETTINGS OF WHO CAN SEE PARENT DETAILS - SCHOOL
 * ADMIN CAN SET THE ACCESS RIGHT": that setting already exists
 * (`guardian_visibility` tenant Setting, "class_teacher_only" vs "both"),
 * reused here unchanged, exactly the same gate StudentController::show()
 * already applies, not a second, competing permission system.
 */
class ParentDirectoryController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function canSeeGuardianDetails(): bool
    {
        $user = Auth::user();
        $school = request()->attributes->get('school');
        $roleSlug = optional($user->roleIn($school))->slug;

        return ! in_array($roleSlug, config('skulos.teacher_scoped_roles', []), true)
            || in_array($roleSlug, ['class-teacher', 'class-assistant'], true)
            || (Setting::grab('guardian_visibility', 'class_teacher_only') === 'both');
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        abort_unless(app(Rbac::class)->check(Auth::user(), $school, 'parents.view'), 403, 'You do not have permission for this.');

        $counts = Student::whereNotNull('current_class_arm_id')
            ->selectRaw('current_class_arm_id, count(*) as c')
            ->groupBy('current_class_arm_id')
            ->pluck('c', 'current_class_arm_id');

        $sections = Section::with(['classes' => fn ($q) => $q->orderBy('sequence'), 'classes.arms.arm'])
            ->orderBy('sequence')->get();

        return view('school.parents.directory', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'sections' => $sections,
            'counts' => $counts,
            'canSeeGuardianDetails' => $this->canSeeGuardianDetails(),
        ]);
    }

    public function showClass(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        abort_unless(app(Rbac::class)->check(Auth::user(), $school, 'parents.view'), 403, 'You do not have permission for this.');

        $class = \App\Models\Tenant\SchoolClass::with(['section', 'arms.arm'])->findOrFail($request->route('classId'));
        $armIds = $class->arms->pluck('id');
        $students = Student::whereIn('current_class_arm_id', $armIds)
            ->with('guardians', 'currentClassArm.arm')
            ->active()->orderBy('last_name')->get();

        return view('school.parents.directory-class', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'class' => $class,
            'students' => $students,
            'canSeeGuardianDetails' => $this->canSeeGuardianDetails(),
        ]);
    }
}
