<?php

namespace App\Services\Dashboard;

use App\Models\School;
use App\Models\User;
use App\Models\Tenant\Campus;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\Tenant\TeachingAssignment;
use App\Models\Tenant\Term;
use App\Services\Results\ResultService;

/**
 * What a dashboard shows depends on who is looking (user request
 * 2026-07-09): an owner/admin/principal wants school-wide figures; a class
 * teacher wants their own class; a subject teacher (who is not necessarily
 * a class teacher) wants only the subjects/classes they actually teach; a
 * class assistant gets the same picture as a class teacher but read-only;
 * parents and students get their own/their child's snapshot. Figures fall
 * back to zero/empty rather than erroring when the underlying data
 * (scores, class assignment, ...) does not exist yet.
 */
class RoleDashboard
{
    public function __construct(protected ResultService $results)
    {
    }

    public function build(User $user, School $school, int $unread = 0): array
    {
        $role = $user->is_super_admin ? null : $user->roleIn($school);
        $slug = $user->is_super_admin ? 'school-owner' : ($role?->slug ?? '');

        return match (true) {
            in_array($slug, ['school-owner', 'school-admin', 'principal', 'vice-principal'], true) => $this->admin(),
            $slug === 'class-teacher' => $this->classTeam($user, 'class_teacher'),
            $slug === 'class-assistant' => $this->classTeam($user, 'class_assistant'),
            $slug === 'subject-teacher' => $this->subjectTeacher($user),
            $slug === 'parent' => $this->parent($user),
            $slug === 'student' => $this->student($user),
            default => $this->general($unread),
        };
    }

    protected function admin(): array
    {
        $term = Term::current()->first();
        $avg = $term ? round((float) \App\Models\Tenant\Score::where('term_id', $term->id)->avg('score'), 1) : null;

        return [
            'kind' => 'admin',
            'figs' => [
                ['n' => number_format(Student::active()->count()), 'l' => 'Students', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '72%'],
                ['n' => number_format(Staff::active()->count()), 'l' => 'Staff', 'ic' => 'staff', 'c' => '#6D4FE0', 'pct' => '64%'],
                ['n' => number_format(SchoolClass::count()), 'l' => 'Classes', 'ic' => 'academics', 'c' => '#E5901C', 'pct' => '80%'],
                ['n' => number_format(Campus::count()), 'l' => 'Campuses', 'ic' => 'school', 'c' => '#E14F63', 'pct' => '50%'],
            ],
            'panel' => [
                'type' => 'performance',
                'term' => $term?->name,
                'average' => $avg,
            ],
        ];
    }

    protected function classTeam(User $user, string $kind): array
    {
        $staff = Staff::where('user_id', $user->id)->first();
        $classArm = $staff ? ClassArm::where('class_teacher_staff_id', $staff->id)->first() : null;

        if (! $classArm) {
            return ['kind' => $kind, 'figs' => $this->emptyFigs(), 'panel' => ['type' => 'no_class']];
        }

        $students = Student::where('current_class_arm_id', $classArm->id)->active()->get();
        $boys = $students->where('gender', 'male')->count();
        $girls = $students->where('gender', 'female')->count();
        $subjectsCount = $classArm->schoolClass?->subjects()->count() ?? 0;

        $term = Term::current()->first();
        $average = null;
        if ($term) {
            $broadsheet = $this->results->classBroadsheet($classArm->id, $term->id);
            $average = count($broadsheet) ? round(array_sum(array_column($broadsheet, 'average')) / count($broadsheet), 1) : null;
        }

        return [
            'kind' => $kind,
            'figs' => [
                ['n' => number_format($students->count()), 'l' => 'My Students', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '72%'],
                ['n' => number_format($boys), 'l' => 'Boys', 'ic' => 'students', 'c' => '#6D4FE0', 'pct' => '55%'],
                ['n' => number_format($girls), 'l' => 'Girls', 'ic' => 'students', 'c' => '#E14F63', 'pct' => '55%'],
                ['n' => number_format($subjectsCount), 'l' => 'Subjects', 'ic' => 'academics', 'c' => '#E5901C', 'pct' => '80%'],
            ],
            'panel' => [
                'type' => 'class_overview',
                'class_label' => $classArm->label(),
                'boys' => $boys,
                'girls' => $girls,
                'term' => $term?->name,
                'average' => $average,
                'read_only' => $kind === 'class_assistant',
            ],
        ];
    }

    protected function subjectTeacher(User $user): array
    {
        $staff = Staff::where('user_id', $user->id)->first();
        $assignments = $staff ? TeachingAssignment::where('staff_id', $staff->id)->with(['subject', 'classArm.schoolClass'])->get() : collect();

        if ($assignments->isEmpty()) {
            return ['kind' => 'subject_teacher', 'figs' => $this->emptyFigs(), 'panel' => ['type' => 'no_assignments']];
        }

        $subjectIds = $assignments->pluck('subject_id')->unique();
        $classArmIds = $assignments->pluck('class_arm_id')->unique();
        $studentsCount = Student::whereIn('current_class_arm_id', $classArmIds)->active()->count();

        $term = Term::current()->first();
        $average = null;
        if ($term) {
            $averages = [];
            foreach ($assignments as $a) {
                $stats = $this->results->subjectStats($a->class_arm_id, $a->subject_id, $term->id);
                if ($stats['average'] > 0) {
                    $averages[] = $stats['average'];
                }
            }
            $average = count($averages) ? round(array_sum($averages) / count($averages), 1) : null;
        }

        return [
            'kind' => 'subject_teacher',
            'figs' => [
                ['n' => number_format($subjectIds->count()), 'l' => 'My Subjects', 'ic' => 'academics', 'c' => '#0EA5A0', 'pct' => '72%'],
                ['n' => number_format($classArmIds->count()), 'l' => 'My Classes', 'ic' => 'academics', 'c' => '#6D4FE0', 'pct' => '60%'],
                ['n' => number_format($studentsCount), 'l' => 'Students Taught', 'ic' => 'students', 'c' => '#E5901C', 'pct' => '75%'],
                ['n' => $average !== null ? "{$average}%" : 'N/A', 'l' => 'Average Score', 'ic' => 'results', 'c' => '#E14F63', 'pct' => '65%'],
            ],
            'panel' => [
                'type' => 'subject_overview',
                'assignments' => $assignments->map(fn ($a) => [
                    'subject' => $a->subject?->name,
                    'class' => $a->classArm?->label(),
                ])->all(),
                'term' => $term?->name,
            ],
        ];
    }

    protected function parent(User $user): array
    {
        $children = StudentGuardian::where('guardian_user_id', $user->id)->with('student.currentClassArm')->get();

        return [
            'kind' => 'parent',
            'figs' => [
                ['n' => number_format($children->count()), 'l' => 'My Children', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '60%'],
                ['n' => 0, 'l' => 'Fees Due', 'ic' => 'comms', 'c' => '#6D4FE0', 'pct' => '0%'],
                ['n' => 0, 'l' => 'Messages', 'ic' => 'comms', 'c' => '#E5901C', 'pct' => '0%'],
                ['n' => 0, 'l' => 'Events', 'ic' => 'calendar', 'c' => '#E14F63', 'pct' => '0%'],
            ],
            'panel' => [
                'type' => 'children',
                'children' => $children->map(fn ($g) => [
                    'name' => $g->student?->fullName(),
                    'class' => $g->student?->currentClassArm?->label(),
                ])->all(),
            ],
        ];
    }

    protected function student(User $user): array
    {
        $student = Student::where('user_id', $user->id)->first();

        if (! $student) {
            return ['kind' => 'student', 'figs' => $this->emptyFigs(), 'panel' => ['type' => 'no_record']];
        }

        $classArm = $student->currentClassArm;
        $subjectsCount = $classArm?->schoolClass?->subjects()->count() ?? 0;

        $term = Term::current()->first();
        $average = null;
        $position = null;
        if ($term && $classArm) {
            $broadsheet = $this->results->classBroadsheet($classArm->id, $term->id);
            foreach ($broadsheet as $row) {
                if ($row['student_id'] === $student->id) {
                    $average = $row['average'];
                    $position = $row['position'];
                    break;
                }
            }
        }

        return [
            'kind' => 'student',
            'figs' => [
                ['n' => $classArm?->label() ?? 'N/A', 'l' => 'My Class', 'ic' => 'academics', 'c' => '#0EA5A0', 'pct' => '60%'],
                ['n' => number_format($subjectsCount), 'l' => 'Subjects', 'ic' => 'academics', 'c' => '#6D4FE0', 'pct' => '75%'],
                ['n' => $average !== null ? "{$average}%" : 'N/A', 'l' => 'My Average', 'ic' => 'results', 'c' => '#E5901C', 'pct' => '70%'],
                ['n' => $position ?? 'N/A', 'l' => 'Position', 'ic' => 'results', 'c' => '#E14F63', 'pct' => '65%'],
            ],
            'panel' => ['type' => 'own_result', 'term' => $term?->name],
        ];
    }

    protected function general(int $unread): array
    {
        return [
            'kind' => 'general',
            'figs' => [
                ['n' => number_format(Student::active()->count()), 'l' => 'Students', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '72%'],
                ['n' => number_format(Staff::active()->count()), 'l' => 'Staff', 'ic' => 'staff', 'c' => '#6D4FE0', 'pct' => '64%'],
                ['n' => number_format(\App\Models\Tenant\CalendarEvent::count()), 'l' => 'Calendar Events', 'ic' => 'calendar', 'c' => '#E5901C', 'pct' => '88%'],
                ['n' => number_format($unread), 'l' => 'Unread', 'ic' => 'comms', 'c' => '#E14F63', 'pct' => '40%'],
            ],
            'panel' => null,
        ];
    }

    protected function emptyFigs(): array
    {
        return [
            ['n' => '0', 'l' => 'Students', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '0%'],
            ['n' => '0', 'l' => 'Boys', 'ic' => 'students', 'c' => '#6D4FE0', 'pct' => '0%'],
            ['n' => '0', 'l' => 'Girls', 'ic' => 'students', 'c' => '#E14F63', 'pct' => '0%'],
            ['n' => '0', 'l' => 'Subjects', 'ic' => 'academics', 'c' => '#E5901C', 'pct' => '0%'],
        ];
    }
}
