<?php

namespace App\Services\Rbac;

use App\Models\School;
use App\Models\User;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Staff;
use App\Models\Tenant\TeachingAssignment;
use Illuminate\Support\Collection;

/**
 * A class teacher or subject teacher should only see the classes and
 * students actually assigned to them, not the whole school (user directive
 * 2026-07-12, live QA: "a subject teacher should not be seeing all
 * students, should only see the class assigned to him with the students
 * taking the subjects he takes... except a new feature was given to
 * them"). This is a data-level restriction on top of RBAC's normal
 * action-level permission check (Rbac::check() answers "can this user do
 * X at all", this answers "on which rows"), so it lives as its own small
 * service rather than overloading Rbac itself.
 */
class TeacherScope
{
    public function __construct(protected Rbac $rbac)
    {
    }

    /**
     * Which class arm ids this user may see students/data for, or null
     * when the user is unrestricted (every role except the ones listed in
     * config('skulos.teacher_scoped_roles'), or a scoped role that was
     * explicitly given the "students" module as an extra grant beyond
     * their base role via Staff Extra Access).
     */
    public function classArmIds(User $user, School $school): ?Collection
    {
        if ($user->is_super_admin) {
            return null;
        }

        $role = $user->roleIn($school);
        if (! $role || ! in_array($role->slug, config('skulos.teacher_scoped_roles', []), true)) {
            return null;
        }

        if ($this->rbac->hasModuleOverride($school, $user, 'students')) {
            return null;
        }

        $staff = Staff::where('user_id', $user->id)->first();
        if (! $staff) {
            return collect();
        }

        $own = ClassArm::where('class_teacher_staff_id', $staff->id)
            ->orWhere('assistant_teacher_staff_id', $staff->id)
            ->pluck('id');
        $taught = TeachingAssignment::where('staff_id', $staff->id)->pluck('class_arm_id');

        return $own->merge($taught)->unique()->values();
    }

    public function isScoped(User $user, School $school): bool
    {
        return $this->classArmIds($user, $school) !== null;
    }
}
