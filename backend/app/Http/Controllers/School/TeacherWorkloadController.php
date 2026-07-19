<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\TeachingAssignment;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Teacher Workload (Advance plan feature, built 2026-07-18, reworked
 * same day after user feedback: "teacher workload has no insights from
 * the data there, no sort and filter options, group by section and
 * classes"). Real subjects/classes/students counts per staff member,
 * from TeachingAssignment rows plus class-teacher/assistant assignment
 * on ClassArm, never a manual entry. There is no Timetable Management
 * yet (deferred), so this is a load proxy, not a schedule.
 */
class TeacherWorkloadController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        abort_unless(app(Rbac::class)->check(Auth::user(), $school, 'staff.view'), 403, 'You do not have permission for this.');

        $studentCountByArm = Student::active()->whereNotNull('current_class_arm_id')
            ->selectRaw('current_class_arm_id, count(*) as c')
            ->groupBy('current_class_arm_id')
            ->pluck('c', 'current_class_arm_id');

        $allArms = ClassArm::with('schoolClass.section', 'arm')->get()->keyBy('id');
        $assignments = TeachingAssignment::get()->groupBy('staff_id');

        $classTeacherArms = $allArms->filter(fn (ClassArm $a) => $a->class_teacher_staff_id)->groupBy('class_teacher_staff_id');
        $assistantArms = $allArms->filter(fn (ClassArm $a) => $a->assistant_teacher_staff_id)->groupBy('assistant_teacher_staff_id');

        $staffIds = collect()
            ->merge($assignments->keys())
            ->merge($classTeacherArms->keys())
            ->merge($assistantArms->keys())
            ->unique();

        $staff = Staff::whereIn('id', $staffIds)->get()->keyBy('id');

        $rows = $staffIds->map(function ($staffId) use ($staff, $assignments, $classTeacherArms, $assistantArms, $studentCountByArm, $allArms) {
            $person = $staff[$staffId] ?? null;
            $subjectAssignments = $assignments->get($staffId, collect());
            $ownedArms = $classTeacherArms->get($staffId, collect());
            $assistArms = $assistantArms->get($staffId, collect());

            $armIds = $subjectAssignments->pluck('class_arm_id')
                ->merge($ownedArms->pluck('id'))
                ->merge($assistArms->pluck('id'))
                ->unique();

            $touchedArms = $armIds->map(fn ($id) => $allArms[$id] ?? null)->filter();
            $students = $armIds->sum(fn ($armId) => (int) ($studentCountByArm[$armId] ?? 0));
            $sectionIds = $touchedArms->map(fn (ClassArm $a) => $a->schoolClass?->section_id)->filter()->unique()->values();

            return [
                'staff_id' => $staffId,
                'name' => $person?->fullName() ?? 'Unknown',
                'position' => $person?->position,
                'subjects' => $subjectAssignments->pluck('subject_id')->unique()->count(),
                'classes' => $armIds->count(),
                'students' => $students,
                'is_class_teacher' => $ownedArms->isNotEmpty(),
                'is_assistant_teacher' => $assistArms->isNotEmpty(),
                'class_labels' => $touchedArms->map(fn (ClassArm $a) => $a->label())->sort()->values()->implode(', '),
                'section_ids' => $sectionIds,
            ];
        });

        // Insights (user request: "no insights from the data there").
        $insights = [
            'teachers' => $rows->count(),
            'avg_subjects' => $rows->isNotEmpty() ? round($rows->avg('subjects'), 1) : 0,
            'avg_students' => $rows->isNotEmpty() ? round($rows->avg('students'), 1) : 0,
            'heaviest' => $rows->sortByDesc('students')->first(),
        ];

        // Sort (user request: "no sort and filter options").
        $sort = in_array($request->query('sort'), ['students', 'subjects', 'classes', 'name'], true) ? $request->query('sort') : 'students';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $sorter = fn ($collection) => $dir === 'asc' ? $collection->sortBy($sort)->values() : $collection->sortByDesc($sort)->values();

        // Group by section, then class (user request: "group by section
        // and classes"): a teacher touching more than one section appears
        // in each relevant group, since their load genuinely spans both.
        $sectionFilter = $request->integer('section_id') ?: null;
        $sections = Section::orderBy('sequence')->get()->when($sectionFilter, fn ($c) => $c->where('id', $sectionFilter));

        $groups = $sections->map(function (Section $section) use ($rows, $sorter) {
            $sectionRows = $rows->filter(fn ($r) => $r['section_ids']->contains($section->id))->values();

            return ['section' => $section, 'rows' => $sorter($sectionRows)];
        })->filter(fn ($g) => $g['rows']->isNotEmpty())->values();

        return view('school.staff.workload', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'insights' => $insights,
            'groups' => $groups,
            'allSections' => Section::orderBy('sequence')->get(),
            'sort' => $sort,
            'dir' => $dir,
            'sectionFilter' => $sectionFilter,
        ]);
    }
}
