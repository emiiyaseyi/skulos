<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\TeachingAssignment;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Staff Portal, browsed the same way as Student Portal (user directive
 * 2026-07-16: "STAFF PORTAL SHOULD BE CATEGORIZED PROPERLY FROM SECTION,
 * CLASS THEN TO THE STAFF DETAILS AS WE HAVE IT UNDER STUDENT PORTAL").
 * Section -> Class -> that class's teaching staff (class teacher,
 * assistant, subject teachers per arm), each name linking to the
 * existing full staff profile (`school.staff.show`). Staff with no class
 * tie at all (Bursar, Librarian, ICT Officer, ...) sit in their own
 * bucket at the bottom, the same "No class assigned" pattern Student
 * Portal already uses for students with no class.
 *
 * Deliberately browse-only: reassigning who teaches a class already has
 * a real, working screen (`school.academics.classes.show`'s per-arm
 * dropdowns), this does not duplicate that, only links out to a staff
 * member's own profile.
 */
class StaffDirectoryController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        abort_unless(app(Rbac::class)->check(Auth::user(), $school, 'staff.view'), 403, 'You do not have permission for this.');

        $sections = Section::with([
            'classes' => fn ($q) => $q->orderBy('sequence'),
            'classes.arms.arm',
            'classes.arms.classTeacher',
            'classes.arms.assistantTeacher',
        ])->orderBy('sequence')->get();

        $teachingStaffIds = collect()
            ->merge(\App\Models\Tenant\ClassArm::whereNotNull('class_teacher_staff_id')->pluck('class_teacher_staff_id'))
            ->merge(\App\Models\Tenant\ClassArm::whereNotNull('assistant_teacher_staff_id')->pluck('assistant_teacher_staff_id'))
            ->merge(TeachingAssignment::pluck('staff_id'))
            ->unique();

        $unassigned = Staff::active()->whereNotIn('id', $teachingStaffIds)->orderBy('last_name')->get();

        return view('school.staff.directory', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'sections' => $sections,
            'unassigned' => $unassigned,
        ]);
    }

    public function showClass(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        abort_unless(app(Rbac::class)->check(Auth::user(), $school, 'staff.view'), 403, 'You do not have permission for this.');

        $class = \App\Models\Tenant\SchoolClass::with(['section', 'arms.arm', 'arms.classTeacher', 'arms.assistantTeacher'])
            ->findOrFail($request->route('classId'));

        $armIds = $class->arms->pluck('id');
        $subjectTeachers = TeachingAssignment::whereIn('class_arm_id', $armIds)
            ->with('staff', 'subject', 'classArm.arm')
            ->get()
            ->groupBy('class_arm_id');

        return view('school.staff.directory-class', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'class' => $class,
            'subjectTeachers' => $subjectTeachers,
        ]);
    }
}
