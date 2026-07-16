<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Attendance;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Services\Attendance\AttendanceService;
use App\Services\Rbac\Rbac;
use App\Services\Rbac\TeacherScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Attendance Management (queued 2026-07-13, from the "add all partial
 * builds" batch). The Attendance model and AttendanceService already
 * existed and were unit tested, but no screen let a school actually mark
 * or view attendance. Student attendance is class-arm scoped through
 * TeacherScope, same as StudentController: a class teacher/assistant/
 * subject teacher only ever sees and, if they hold attendance.create,
 * marks their own class arm(s), never the whole school. Staff attendance
 * is a separate, simpler screen gated on staff.view too, which none of
 * the teacher-scoped roles hold, so it naturally never appears for them.
 *
 * The {school} route sits under a domain wildcard, so nested route params
 * are read via $request->route() rather than typed method parameters
 * (the recurring gotcha documented in CLAUDE.md).
 */
class AttendanceController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request, string $permission): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), $permission),
            403,
            'You do not have permission for this.'
        );
    }

    protected function canMark(School $school, string $permission = 'attendance.create'): bool
    {
        return app(Rbac::class)->check(Auth::user(), $school, $permission);
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'attendance.view');
        $school = $this->school($request);
        $user = Auth::user();

        $scopedArmIds = app(TeacherScope::class)->classArmIds($user, $school);

        $classArms = ClassArm::with('schoolClass.section', 'arm')
            ->when($scopedArmIds !== null, fn ($q) => $q->whereIn('id', $scopedArmIds))
            ->get()
            ->sortBy(fn (ClassArm $arm) => $arm->orderKey())
            ->values();

        $classArmId = $request->integer('class_arm_id') ?: null;
        $date = $request->query('date') ?: now()->toDateString();

        if ($classArmId && $scopedArmIds !== null && ! $scopedArmIds->contains($classArmId)) {
            abort(404);
        }

        $selectedArm = $classArmId ? $classArms->firstWhere('id', $classArmId) : null;
        if ($classArmId && ! $selectedArm) {
            abort(404);
        }

        $students = collect();
        $existingMarks = collect();
        $summary = [];
        if ($selectedArm) {
            $students = Student::active()->where('current_class_arm_id', $selectedArm->id)
                ->orderBy('last_name')->orderBy('first_name')->get();
            $existingMarks = Attendance::students()->where('class_arm_id', $selectedArm->id)
                ->forDate($date)->pluck('status', 'person_id');
            $summary = app(AttendanceService::class)->classSummary($selectedArm->id, $date);
        }

        // A bounded window (a year back to a term ahead) around today, so
        // the client side calendar can grey out weekends and holidays
        // before a date is even picked, without loading the whole
        // calendar's history (user directive 2026-07-16: "GREY OUT WEEKEND
        // ... AND HOLIDAYS FROM THE ATTENDANCE SO THAT IT WAS NOT PICKED").
        $nonSchoolDates = app(AttendanceService::class)->nonSchoolDatesWithin(now()->subYear(), now()->addMonths(3));

        return view('school.attendance.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'classArms' => $classArms,
            'selectedArm' => $selectedArm,
            'date' => $date,
            'students' => $students,
            'existingMarks' => $existingMarks,
            'summary' => $summary,
            'statuses' => Attendance::statuses(),
            'canMark' => $this->canMark($school),
            'nonSchoolDates' => $nonSchoolDates,
        ]);
    }

    public function markClass(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'attendance.create');
        $school = $this->school($request);
        $user = Auth::user();

        $data = $request->validate([
            'class_arm_id' => ['required', 'exists:tenant.class_arms,id'],
            'date' => ['required', 'date'],
            'marks' => ['required', 'array', 'min:1'],
            'marks.*' => ['required', 'string', Rule::in(Attendance::statuses())],
        ]);

        $scopedArmIds = app(TeacherScope::class)->classArmIds($user, $school);
        if ($scopedArmIds !== null && ! $scopedArmIds->contains((int) $data['class_arm_id'])) {
            abort(404);
        }

        // Only ever mark students who genuinely belong to this class arm
        // right now, a stale or crafted student id in the submitted form
        // is silently dropped rather than acted on.
        $validStudentIds = Student::where('current_class_arm_id', $data['class_arm_id'])->pluck('id')->all();
        $marks = collect($data['marks'])->only($validStudentIds)->all();

        try {
            $result = app(AttendanceService::class)->markClass(
                (int) $data['class_arm_id'], $data['date'], $marks, $user->id
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('school.attendance.index', [
            'school' => $school->slug, 'class_arm_id' => $data['class_arm_id'], 'date' => $data['date'],
        ])->with('status', "Attendance saved for {$result['marked']} student(s).");
    }

    public function staffIndex(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'attendance.view');
        $this->authorizeAction($request, 'staff.view');
        $school = $this->school($request);

        $date = $request->query('date') ?: now()->toDateString();
        $staff = Staff::active()->orderBy('last_name')->orderBy('first_name')->get();
        $existingMarks = Attendance::staff()->forDate($date)->pluck('status', 'person_id');
        $nonSchoolDates = app(AttendanceService::class)->nonSchoolDatesWithin(now()->subYear(), now()->addMonths(3));

        return view('school.attendance.staff', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'date' => $date,
            'staff' => $staff,
            'existingMarks' => $existingMarks,
            'statuses' => Attendance::statuses(),
            'canMark' => $this->canMark($school),
            'nonSchoolDates' => $nonSchoolDates,
        ]);
    }

    public function markStaff(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'attendance.create');
        $this->authorizeAction($request, 'staff.view');
        $school = $this->school($request);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'marks' => ['required', 'array', 'min:1'],
            'marks.*' => ['required', 'string', Rule::in(Attendance::statuses())],
        ]);

        $service = app(AttendanceService::class);
        if ($reason = $service->nonSchoolDayReason($data['date'])) {
            return back()->withInput()->with('error', "Attendance cannot be marked for {$data['date']}: it is a {$reason}.");
        }

        $validStaffIds = Staff::whereIn('id', array_keys($data['marks']))->pluck('id')->all();
        $marked = 0;
        foreach ($data['marks'] as $staffId => $status) {
            if (! in_array((int) $staffId, $validStaffIds, true)) {
                continue;
            }
            $service->markStaff((int) $staffId, $data['date'], $status, Auth::id());
            $marked++;
        }

        return redirect()->route('school.attendance.staff', ['school' => $school->slug, 'date' => $data['date']])
            ->with('status', "Attendance saved for {$marked} staff member(s).");
    }
}
