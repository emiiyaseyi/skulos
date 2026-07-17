<?php

namespace App\Services\Rbac;

use App\Models\School;
use App\Models\Tenant\Student;
use App\Models\User;

/**
 * The single access gate a class/subject teacher's or parent/student's view
 * of an individual student record must pass. Previously only
 * StudentController::show() enforced TeacherScope/FamilyScope on a loaded
 * Student; every mutation and login-provisioning action loaded the record
 * by raw id with no scope check at all, letting a class teacher edit,
 * re-guardian, or mint a login for any student school wide (audit finding
 * H-1, 2026-07-17). Centralising the check here means a future action that
 * loads a Student can't quietly skip it the way those did. 404, never 403,
 * per the standing "never leak that a resource exists" convention.
 */
class StudentAccessGuard
{
    public function __construct(protected TeacherScope $teacherScope, protected FamilyScope $familyScope)
    {
    }

    public function assertVisible(User $user, School $school, Student $student): void
    {
        $scopedArmIds = $this->teacherScope->classArmIds($user, $school);
        if ($scopedArmIds !== null) {
            abort_unless($student->current_class_arm_id && $scopedArmIds->contains($student->current_class_arm_id), 404);
        }

        $familyStudentIds = $this->familyScope->studentIds($user, $school);
        if ($familyStudentIds !== null) {
            abort_unless($familyStudentIds->contains($student->id), 404);
        }
    }
}
