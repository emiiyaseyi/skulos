<?php

namespace App\Services\Communication;

use App\Models\Role;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\Tenant\TeachingAssignment;

/**
 * Resolves a message audience (a role group or everyone) and scope (the
 * whole school, one section, or one class) into the concrete recipient
 * list NotificationService::dispatchAnnouncement() expects. Both the
 * audiences and the scopes come from config catalogs
 * (config('skulos.message_audiences'/'message_scopes')), never literals,
 * per the standing "nothing hardcoded" rule.
 *
 * Extracted so the school broadcast composer, the legacy Announcement
 * composer, and (later) the chat/platform paths all share one definition
 * of "who is in this audience" rather than drifting copies.
 */
class AudienceResolver
{
    /**
     * @return list<array{user_id:int, email:?string, audience:string}>
     */
    public function resolve(School $school, string $audience, string $scope, ?int $sectionId, ?int $classArmId): array
    {
        $armIds = $this->scopeArmIds($scope, $sectionId, $classArmId);

        $wantParents = in_array($audience, ['everyone', 'parents'], true);
        $wantStudents = in_array($audience, ['everyone', 'students'], true);
        $wantTeachers = in_array($audience, ['everyone', 'teachers'], true);

        $recipients = [];
        if ($wantStudents) {
            $recipients = array_merge($recipients, $this->students($armIds));
        }
        if ($wantParents) {
            $recipients = array_merge($recipients, $this->parents($armIds));
        }
        if ($wantTeachers) {
            $recipients = array_merge($recipients, $this->teachers($school, $armIds));
        }

        // De-duplicate by user id (a parent of two children in scope, a
        // class teacher who also has a teaching assignment, etc.).
        $seen = [];
        $unique = [];
        foreach ($recipients as $r) {
            if (isset($seen[$r['user_id']])) {
                continue;
            }
            $seen[$r['user_id']] = true;
            $unique[] = $r;
        }

        return $unique;
    }

    /**
     * The class arm ids a scope resolves to, or null for the whole school.
     *
     * @return list<int>|null
     */
    private function scopeArmIds(string $scope, ?int $sectionId, ?int $classArmId): ?array
    {
        return match ($scope) {
            'class' => $classArmId ? [$classArmId] : [],
            'section' => $sectionId
                ? ClassArm::whereIn('school_class_id', SchoolClass::where('section_id', $sectionId)->pluck('id'))->pluck('id')->all()
                : [],
            default => null, // 'all'
        };
    }

    /**
     * @param list<int>|null $armIds
     * @return list<array{user_id:int, email:?string, audience:string}>
     */
    private function students(?array $armIds): array
    {
        $query = Student::active()->whereNotNull('user_id')->with('user');
        if ($armIds !== null) {
            $query->whereIn('current_class_arm_id', $armIds);
        }

        return $query->get()
            ->filter(fn ($s) => $s->user)
            ->map(fn ($s) => ['user_id' => $s->user->id, 'email' => $s->user->email, 'audience' => 'students'])
            ->values()->all();
    }

    /**
     * @param list<int>|null $armIds
     * @return list<array{user_id:int, email:?string, audience:string}>
     */
    private function parents(?array $armIds): array
    {
        $studentIdsQuery = Student::active();
        if ($armIds !== null) {
            $studentIdsQuery->whereIn('current_class_arm_id', $armIds);
        }
        $studentIds = $studentIdsQuery->pluck('id');

        return StudentGuardian::whereIn('student_id', $studentIds)
            ->whereNotNull('guardian_user_id')->with('user')->get()
            ->unique('guardian_user_id')
            ->filter(fn ($g) => $g->user)
            ->map(fn ($g) => ['user_id' => $g->user->id, 'email' => $g->user->email, 'audience' => 'parents'])
            ->values()->all();
    }

    /**
     * @param list<int>|null $armIds
     * @return list<array{user_id:int, email:?string, audience:string}>
     */
    private function teachers(School $school, ?array $armIds): array
    {
        if ($armIds === null) {
            // Whole school: everyone holding a teacher-type role.
            $teacherRoleIds = Role::where('school_id', $school->id)
                ->whereIn('slug', ['teacher', 'class-teacher', 'subject-teacher', 'class-assistant'])
                ->pluck('id');

            return $school->users()->wherePivot('status', 'active')->whereIn('role_id', $teacherRoleIds)->get()
                ->map(fn ($u) => ['user_id' => $u->id, 'email' => $u->email, 'audience' => 'teachers'])
                ->all();
        }

        // Scoped: staff who class-teach/assist those arms, plus anyone with
        // a teaching assignment in them, mapped to their login.
        $staffIds = collect()
            ->merge(ClassArm::whereIn('id', $armIds)->pluck('class_teacher_staff_id'))
            ->merge(ClassArm::whereIn('id', $armIds)->pluck('assistant_teacher_staff_id'))
            ->merge(TeachingAssignment::whereIn('class_arm_id', $armIds)->pluck('staff_id'))
            ->filter()->unique();

        return Staff::whereIn('id', $staffIds)->whereNotNull('user_id')->with('user')->get()
            ->filter(fn ($s) => $s->user)
            ->map(fn ($s) => ['user_id' => $s->user->id, 'email' => $s->user->email, 'audience' => 'teachers'])
            ->values()->all();
    }
}
