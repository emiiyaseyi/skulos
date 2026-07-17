<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Services\Rbac\FamilyScope;
use App\Services\Rbac\Rbac;
use App\Services\Rbac\TeacherScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Global Search (user directed backlog item, 2026-07-12, "To Do List 2"
 * doc item 4: "one search bar, search anything"). Covers what the product
 * actually has a record of today: Students, Staff, and Parents/Guardians.
 * Invoice, Receipt, Result, Library Book, and Message from the source
 * doc's own example are deliberately not searched, none of those have a
 * real module yet (Finance, Library, Messaging are all still unbuilt or
 * deferred, see tracker). Each category respects its own view permission,
 * a user without students.view never sees a student (or guardian) result
 * even if the text matches, same for staff.view.
 */
class SearchController extends Controller
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
        $q = trim((string) $request->query('q'));

        $canViewStudents = $rbac->check($user, $school, 'students.view');
        $canViewStaff = $rbac->check($user, $school, 'staff.view');

        // A class/subject teacher or a parent/student holds students.view
        // but must only ever be able to search up their own scope, the
        // same restriction StudentController::show()/index() already
        // enforce (audit finding M-1, 2026-07-17: this screen let a class
        // teacher search up any student, and their guardians' contact
        // details, school wide).
        $scopedArmIds = app(TeacherScope::class)->classArmIds($user, $school);
        $familyStudentIds = app(FamilyScope::class)->studentIds($user, $school);

        $students = collect();
        $staff = collect();
        $guardians = collect();

        if (mb_strlen($q) >= 2) {
            if ($canViewStudents) {
                $students = Student::query()
                    ->when($scopedArmIds !== null, fn ($qb) => $qb->whereIn('current_class_arm_id', $scopedArmIds))
                    ->when($familyStudentIds !== null, fn ($qb) => $qb->whereIn('id', $familyStudentIds))
                    ->where(function ($w) use ($q) {
                        $w->where('admission_number', 'like', "%{$q}%")
                            ->orWhere('first_name', 'like', "%{$q}%")
                            ->orWhere('last_name', 'like', "%{$q}%");
                    })
                    ->orderBy('last_name')->limit(8)->get();

                $guardians = StudentGuardian::query()
                    ->with('student')
                    ->whereHas('student', function ($sw) use ($scopedArmIds, $familyStudentIds) {
                        $sw->when($scopedArmIds !== null, fn ($qb) => $qb->whereIn('current_class_arm_id', $scopedArmIds))
                            ->when($familyStudentIds !== null, fn ($qb) => $qb->whereIn('id', $familyStudentIds));
                    })
                    ->where(function ($w) use ($q) {
                        $w->where('name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%")
                            ->orWhere('phone', 'like', "%{$q}%");
                    })
                    ->limit(8)->get();
            }

            if ($canViewStaff) {
                $staff = Staff::query()
                    ->where(function ($w) use ($q) {
                        $w->where('staff_id', 'like', "%{$q}%")
                            ->orWhere('first_name', 'like', "%{$q}%")
                            ->orWhere('last_name', 'like', "%{$q}%")
                            ->orWhere('position', 'like', "%{$q}%");
                    })
                    ->orderBy('last_name')->limit(8)->get();
            }
        }

        return view('school.search', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'q' => $q,
            'students' => $students,
            'staff' => $staff,
            'guardians' => $guardians,
            'total' => $students->count() + $staff->count() + $guardians->count(),
        ]);
    }
}
