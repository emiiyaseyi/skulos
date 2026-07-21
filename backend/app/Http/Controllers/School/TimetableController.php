<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\TimetablePeriod;
use App\Services\Rbac\FamilyScope;
use App\Services\Rbac\Rbac;
use App\Services\Timetable\TimetableService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Timetable Management (Advance plan feature, user request 2026-07-18,
 * previously deferred then reconfirmed and built the same day). Setting
 * the period catalog and editing any class's weekly grid is an admin
 * action (`timetable.update`/`timetable.*`, the same role tier already
 * granted it in config('skulos.roles') well before this screen existed).
 * Everyone else (`timetable.view`: teacher, class-teacher, subject-teacher,
 * class-assistant, parent, student) only ever sees their OWN schedule via
 * "My Timetable", never browses another class's or teacher's.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class TimetableController extends Controller
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

    public function periods(Request $request, TimetableService $timetable): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'timetable.update');

        return view('school.timetable.periods', [
            'school' => $this->school($request),
            'accent' => $this->school($request)->branding()['color'],
            'periods' => $timetable->periods(),
        ]);
    }

    public function applyDefaultPeriods(Request $request, TimetableService $timetable): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'timetable.update');
        $created = $timetable->applyDefaultPeriods();

        return back()->with('status', $created > 0 ? "{$created} default period(s) added." : 'Every default period already exists.');
    }

    public function storePeriod(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'timetable.update');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'is_teaching_period' => ['nullable', 'boolean'],
        ]);

        TimetablePeriod::create([
            'name' => $data['name'],
            'sequence' => (TimetablePeriod::max('sequence') ?? 0) + 1,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'is_teaching_period' => (bool) ($data['is_teaching_period'] ?? false),
        ]);

        return back()->with('status', 'Period added.');
    }

    public function updatePeriod(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'timetable.update');
        $period = TimetablePeriod::findOrFail($request->route('periodId'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'is_teaching_period' => ['nullable', 'boolean'],
        ]);

        $period->update([
            'name' => $data['name'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'is_teaching_period' => (bool) ($data['is_teaching_period'] ?? false),
        ]);

        return back()->with('status', 'Period updated.');
    }

    public function destroyPeriod(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'timetable.update');
        $period = TimetablePeriod::findOrFail($request->route('periodId'));
        $period->delete();

        return back()->with('status', 'Period removed, along with any class assignments in that slot.');
    }

    /** Bulk version of destroyPeriod(), same list-screen bulk-action pattern used across the app. */
    public function bulkDestroyPeriods(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'timetable.update');
        $data = $request->validate([
            'period_ids' => ['required', 'array', 'min:1'],
            'period_ids.*' => ['exists:tenant.timetable_periods,id'],
        ]);

        TimetablePeriod::whereIn('id', $data['period_ids'])->delete();

        return back()->with('status', count($data['period_ids']).' period(s) removed, along with any class assignments in those slots.');
    }

    public function index(Request $request, TimetableService $timetable): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'timetable.update');
        $school = $this->school($request);

        $classArms = ClassArm::with('schoolClass.section', 'arm')->get()
            ->sortBy(fn (ClassArm $arm) => $arm->orderKey())->values();
        $classArmsBySection = $classArms->groupBy(fn (ClassArm $arm) => $arm->schoolClass?->section?->name ?? 'Other');

        $classArmId = $request->integer('class_arm_id') ?: null;
        $selectedArm = $classArmId ? $classArms->firstWhere('id', $classArmId) : null;
        if ($classArmId && ! $selectedArm) {
            abort(404);
        }

        $periods = $timetable->periods();
        abort_if($periods->isEmpty() && $selectedArm, 422, 'Set up the period catalog first.');

        $grid = $selectedArm ? $timetable->gridForClassArm($selectedArm->id) : [];
        $subjects = $selectedArm ? ($selectedArm->schoolClass?->subjects()->orderBy('name')->get() ?? collect()) : collect();
        // Every active staff member, not just those already teaching this
        // class, a timetable is exactly where an admin adds a teacher to
        // a class for the first time.
        $staffOptions = $selectedArm ? Staff::active()->orderBy('first_name')->get() : collect();

        return view('school.timetable.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'classArms' => $classArms,
            'classArmsBySection' => $classArmsBySection,
            'selectedArm' => $selectedArm,
            'periods' => $periods,
            'weekDays' => $timetable->weekDays(),
            'grid' => $grid,
            'subjects' => $subjects,
            'staffOptions' => $staffOptions,
        ]);
    }

    public function save(Request $request, TimetableService $timetable): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'timetable.update');
        $classArm = ClassArm::findOrFail($request->route('classArmId'));

        $data = $request->validate([
            'cells' => ['nullable', 'array'],
        ]);

        $conflicts = $timetable->saveClassArmWeek($classArm, $data['cells'] ?? []);

        $status = 'Timetable saved for '.$classArm->label().'.';
        if ($conflicts) {
            $status .= ' '.count($conflicts).' slot(s) skipped due to a teacher conflict.';
        }

        return back()->with($conflicts ? 'error' : 'status', $status);
    }

    /**
     * A teacher's own weekly schedule, or a student/parent's own class
     * schedule, always read only, never another person's.
     */
    public function mine(Request $request, TimetableService $timetable): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'timetable.view');
        $school = $this->school($request);
        $user = Auth::user();

        $staff = Staff::where('user_id', $user->id)->first();
        $studentIds = app(FamilyScope::class)->studentIds($user, $school);

        $grid = [];
        $subtitle = 'No schedule linked to your account yet.';
        $children = collect();

        if ($staff) {
            $grid = $timetable->gridForStaff($staff->id);
            $subtitle = 'Your own teaching schedule, every class you are booked into.';
        } elseif ($studentIds !== null && $studentIds->isNotEmpty()) {
            $children = Student::whereIn('id', $studentIds)->with('currentClassArm.schoolClass.section', 'currentClassArm.arm')->get();
            $studentId = $request->integer('student_id') ?: $children->first()?->id;
            $selectedChild = $children->firstWhere('id', $studentId) ?? $children->first();
            if ($selectedChild?->currentClassArm) {
                $grid = $timetable->gridForClassArm($selectedChild->currentClassArm->id);
                $subtitle = $selectedChild->currentClassArm->label().'\'s weekly schedule.';
            }
        }

        return view('school.timetable.mine', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'periods' => $timetable->periods(),
            'weekDays' => $timetable->weekDays(),
            'grid' => $grid,
            'subtitle' => $subtitle,
            'children' => $children,
            'selectedChildId' => $children->isNotEmpty() ? ($request->integer('student_id') ?: $children->first()?->id) : null,
        ]);
    }
}
