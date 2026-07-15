<?php

namespace App\Services\Communication;

use App\Models\School;
use App\Models\Tenant\ChatThread;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\User;
use App\Services\Rbac\FamilyScope;
use App\Services\Rbac\TeacherScope;
use Illuminate\Support\Collection;

/**
 * Messaging increment 4 (2026-07-15): private 1-to-1 chat between a
 * teacher and a student's guardian, about that student. Deliberately
 * narrow, not a general any-role chat, matching the concrete pairing the
 * user asked for ("NOTE THAT ADMIN CAN TURN OFF TEACHERS MESSAGE TO
 * PARENTS" names this exact direction).
 */
class ChatService
{
    public function __construct(protected TeacherScope $teacherScope, protected FamilyScope $familyScope)
    {
    }

    public const SETTING_KEY = 'teacher_parent_messaging_enabled';

    public function teacherParentMessagingEnabled(): bool
    {
        return (bool) Setting::grab(self::SETTING_KEY, true);
    }

    public function setTeacherParentMessagingEnabled(bool $enabled): void
    {
        Setting::put(self::SETTING_KEY, $enabled);
    }

    /** Whether $user is one of $student's class/subject teachers or class assistant. */
    public function isTeacherOf(User $user, School $school, Student $student): bool
    {
        $role = optional($user->roleIn($school))->slug;
        if (! in_array($role, config('skulos.teacher_scoped_roles', []), true)) {
            return false;
        }

        $armIds = $this->teacherScope->classArmIds($user, $school);

        return $armIds !== null && $student->current_class_arm_id && $armIds->contains($student->current_class_arm_id);
    }

    /**
     * Whether $user is a guardian of $student. Deliberately checks the
     * role slug is exactly "parent", not just FamilyScope::studentIds()
     * containing the student, because a "student" role also passes that
     * check for their OWN record (it is their own family scope), which
     * would otherwise let a student chat with their own teacher through
     * this teacher<->guardian-only pairing, out of this increment's scope.
     */
    public function isGuardianOf(User $user, School $school, Student $student): bool
    {
        if (optional($user->roleIn($school))->slug !== 'parent') {
            return false;
        }

        $studentIds = $this->familyScope->studentIds($user, $school);

        return $studentIds !== null && $studentIds->contains($student->id);
    }

    /** Guardians of $student who have a login account, for a teacher to message. */
    public function guardianTargetsFor(Student $student): Collection
    {
        return StudentGuardian::where('student_id', $student->id)
            ->whereNotNull('guardian_user_id')
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter();
    }

    /** The class teacher and class assistant of $student (with logins), for a guardian to message. */
    public function teacherTargetsFor(Student $student, School $school): Collection
    {
        $classArm = $student->currentClassArm;
        if (! $classArm) {
            return collect();
        }

        $staffIds = collect([$classArm->class_teacher_staff_id, $classArm->assistant_teacher_staff_id])->filter();

        return \App\Models\Tenant\Staff::whereIn('id', $staffIds)->whereNotNull('user_id')->with('user')->get()
            ->pluck('user')->filter();
    }

    /**
     * Find or create the single thread between $a and $b about $student,
     * normalizing participant order so the same pair/student never
     * produces two threads regardless of who started it.
     */
    public function threadFor(User $a, User $b, Student $student): ChatThread
    {
        [$one, $two] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        return ChatThread::firstOrCreate([
            'user_one_id' => $one,
            'user_two_id' => $two,
            'student_id' => $student->id,
        ]);
    }
}
