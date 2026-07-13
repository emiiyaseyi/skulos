<?php

namespace App\Services\Rbac;

use App\Models\School;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A parent or student should only ever see their own family's data, never
 * the whole school (user request 2026-07-13: "BUILD THE FULL STUDENT AND
 * PARENT PORTAL"). Same data-level ("which rows") vs action-level ("can
 * they at all") split as App\Services\Rbac\TeacherScope, kept as its own
 * service since neither role has a Staff record for TeacherScope's
 * class-arm based logic to key off, a parent/student is scoped to
 * specific student ids instead of a class arm.
 */
class FamilyScope
{
    /**
     * Which student ids this user may see, or null when unrestricted
     * (every role except config('skulos.family_scoped_roles')). A
     * student with no linked Student record yet, or a parent with no
     * linked children yet, gets an empty collection, not null, so they
     * see "nothing yet" rather than the whole school.
     */
    public function studentIds(User $user, School $school): ?Collection
    {
        if ($user->is_super_admin) {
            return null;
        }

        $role = $user->roleIn($school);
        if (! $role || ! in_array($role->slug, config('skulos.family_scoped_roles', []), true)) {
            return null;
        }

        if ($role->slug === 'student') {
            $student = Student::where('user_id', $user->id)->first();

            return $student ? collect([$student->id]) : collect();
        }

        // parent
        return StudentGuardian::where('guardian_user_id', $user->id)->pluck('student_id')->unique()->values();
    }

    public function isScoped(User $user, School $school): bool
    {
        return $this->studentIds($user, $school) !== null;
    }
}
