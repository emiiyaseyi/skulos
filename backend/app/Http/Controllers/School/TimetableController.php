<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Term;
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

    /**
     * NULL is a real, intentional state here (the universal grid every
     * already-provisioned school's timetable already is), not "term not
     * found, abort" the way ReportCardController::resolveTerm() treats
     * it — so this is its own small helper rather than reusing that
     * one. A first visit (no term_id in the query string at all)
     * defaults to the school's current term (user directive 2026-07-28:
     * "the timetable term switcher should have the school current term
     * as the default one"), not undifferentiated "All terms". Explicitly
     * picking "All terms" from the dropdown uses the sentinel 'all',
     * the same convention ?student_id=all already uses on this same
     * screen, deliberately NOT an empty string: every link this
     * controller/its views build passes term_id through array_filter()
     * (which drops empty/falsy values), so an empty-string "explicit
     * all terms" would silently degrade back into "absent" on the very
     * next link clicked, re-triggering the current-term default instead
     * of staying on All Terms. 'all' is truthy, so it survives
     * array_filter() and is never confused with "absent" again.
     */
    protected function resolveOptionalTerm(Request $request): ?Term
    {
        $termId = $request->query('term_id');
        if ($termId === null) {
            return Term::current()->first();
        }
        if ($termId === 'all') {
            return null;
        }

        return Term::find($termId);
    }

    public function index(Request $request, TimetableService $timetable): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
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
        // A school that has never set up its period catalog yet is a
        // completely normal, anticipated state (found live 2026-07-21: a
        // real school hit a raw 422 error page just from picking a class
        // before ever visiting Timetable > Periods), not an error —
        // redirect to where that is fixed in one click ("Load default
        // day"), the same graceful pattern every other "nothing set up
        // yet" screen in this app already uses instead of hard aborting.
        if ($periods->isEmpty() && $selectedArm) {
            return redirect()->route('school.timetable.periods', ['school' => $school->slug])
                ->with('status', 'Set up your period catalog first, then come back and build this class\'s timetable.');
        }

        // A real term switcher (user request 2026-07-28), defaulting
        // to the school's current term; picking a specific term shows
        // that term's own overrides layered on top of the universal
        // rows, and Save below then writes to that term specifically.
        // "All terms" ($term null) is its own explicit choice.
        $terms = Term::with('session')->get()
            ->sortByDesc(fn (Term $t) => $t->chronologicalSortKey())
            ->values();
        $term = $this->resolveOptionalTerm($request);

        $grid = $selectedArm ? $timetable->gridForClassArm($selectedArm->id, $term) : [];
        $subjects = $selectedArm ? $selectedArm->offeredSubjects()->sortBy('name')->values() : collect();
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
            'terms' => $terms,
            'term' => $term,
        ]);
    }

    public function save(Request $request, TimetableService $timetable): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'timetable.update');
        $school = $this->school($request);
        $classArm = ClassArm::findOrFail($request->route('classArmId'));
        $term = $this->resolveOptionalTerm($request);

        $data = $request->validate([
            'cells' => ['nullable', 'array'],
        ]);

        $conflicts = $timetable->saveClassArmWeek($classArm, $data['cells'] ?? [], $term);

        $status = 'Timetable saved for '.$classArm->label().($term ? " ({$term->name})" : ' (all terms)').'.';
        if ($conflicts) {
            $status .= ' '.count($conflicts).' slot(s) skipped due to a teacher conflict.';
        }

        // An explicit redirect back to the same class+term (rather than
        // back(), whatever the submitting form's own URL happened to
        // carry) so the just-saved term selection is never silently
        // lost after a save, regardless of how the form itself is built.
        return redirect()->route('school.timetable.index', array_filter(['school' => $school->slug, 'class_arm_id' => $classArm->id, 'term_id' => $term?->id]))
            ->with($conflicts ? 'error' : 'status', $status);
    }

    /**
     * A teacher's own weekly schedule, or a student/parent's own class
     * schedule, always read only, never another person's. User request
     * 2026-07-22: "when the teacher clicks the timetable menu, he should
     * be able to select class timetable or the My timetable option. Note
     * that subject teachers wont see the class timetable option, it just
     * goes direct to their own personal timetable since they are not
     * class teacher" — a class teacher (holding class_teacher_staff_id
     * on at least one ClassArm) gets a toggle between their own class's
     * full grid and their personal cross-class teaching schedule, on
     * this same screen; a subject teacher (or any staff member who is
     * not a class teacher) has nothing to toggle, so none is shown.
     */
    public function mine(Request $request, TimetableService $timetable): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'timetable.view');
        $school = $this->school($request);
        $resolved = $this->resolveMineGrid($request, $timetable);

        return view('school.timetable.mine', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'periods' => $timetable->periods(),
            'weekDays' => $timetable->weekDays(),
            'grid' => $resolved['grid'],
            'subtitle' => $resolved['subtitle'],
            'children' => $resolved['children'],
            'selectedChildId' => $resolved['children']->isNotEmpty() ? ($request->integer('student_id') ?: $resolved['children']->first()?->id) : null,
            'ownClassArm' => $resolved['ownClassArm'],
            'view' => $resolved['view'],
            'allGrids' => $resolved['allGrids'],
            'term' => $resolved['term'],
            'terms' => $resolved['terms'],
        ]);
    }

    /**
     * Resolves exactly which grid "My Timetable" shows for the signed in
     * user (their own teaching schedule, the class they lead, or a
     * chosen child's), shared by mine() and mineExport() so the CSV
     * download below always matches whatever is currently on screen.
     * $term follows the same "All terms" (universal grid) default as
     * the admin builder above (resolveOptionalTerm()), a real term
     * switcher here too (user request 2026-07-28), not just on the
     * admin builder.
     *
     * @return array{grid: array, subtitle: string, children: \Illuminate\Support\Collection, ownClassArm: ?ClassArm, view: string, term: ?Term, terms: \Illuminate\Support\Collection}
     */
    protected function resolveMineGrid(Request $request, TimetableService $timetable): array
    {
        $school = $this->school($request);
        $user = Auth::user();

        $staff = Staff::where('user_id', $user->id)->first();
        $studentIds = app(FamilyScope::class)->studentIds($user, $school);
        $term = $this->resolveOptionalTerm($request);
        $terms = Term::with('session')->get()
            ->sortByDesc(fn (Term $t) => $t->chronologicalSortKey())
            ->values();

        $grid = [];
        $subtitle = 'No schedule linked to your account yet.';
        $children = collect();
        $ownClassArm = null;
        $view = 'personal';

        if ($staff) {
            $ownClassArm = ClassArm::with('schoolClass.section', 'arm')->where('class_teacher_staff_id', $staff->id)->first();
            $view = ($ownClassArm && $request->query('view') !== 'personal') ? 'class' : 'personal';

            if ($view === 'class') {
                $grid = $timetable->gridForClassArm($ownClassArm->id, $term);
                $subtitle = $ownClassArm->label().'\'s full weekly schedule, every subject and teacher.';
            } else {
                $grid = $timetable->gridForStaff($staff->id, $term);
                $subtitle = 'Your own teaching schedule, every class you are booked into.';
            }
        } elseif ($studentIds !== null && $studentIds->isNotEmpty()) {
            $children = Student::whereIn('id', $studentIds)->with('currentClassArm.schoolClass.section', 'currentClassArm.arm')->get();

            // "See all children's timetable at once" (user request
            // 2026-07-27: "just the child or see all children timetable
            // at once if the parent have more than one child"), each
            // child's own grid stacked on the one page rather than
            // switching one at a time.
            if ($request->query('student_id') === 'all' && $children->count() > 1) {
                $allGrids = $children->filter(fn (Student $s) => $s->currentClassArm)->map(fn (Student $s) => [
                    'student' => $s,
                    'grid' => $timetable->gridForClassArm($s->currentClassArm->id, $term),
                    'subtitle' => $s->currentClassArm->label().'\'s weekly schedule.',
                ])->values();
                $subtitle = 'Every child\'s weekly schedule.';

                return compact('grid', 'subtitle', 'children', 'ownClassArm', 'view', 'allGrids', 'term', 'terms');
            }

            $studentId = $request->integer('student_id') ?: $children->first()?->id;
            $selectedChild = $children->firstWhere('id', $studentId) ?? $children->first();
            if ($selectedChild?->currentClassArm) {
                $grid = $timetable->gridForClassArm($selectedChild->currentClassArm->id, $term);
                $subtitle = $selectedChild->currentClassArm->label().'\'s weekly schedule.';
            }
        }

        $allGrids = collect();

        return compact('grid', 'subtitle', 'children', 'ownClassArm', 'view', 'allGrids', 'term', 'terms');
    }

    /**
     * A CSV export of "My Timetable" (user request 2026-07-25: timetable
     * export was only ever available from the admin only Master
     * Timetable screen, not from the personal/class view every teacher,
     * parent, and student actually lands on). Same flat day/period row
     * shape and the same timetable.view gate the page itself already
     * uses, so anyone who can see their own schedule can also export it.
     * PDF is covered by the page's own Print button, same as the master
     * export.
     */
    public function mineExport(Request $request, TimetableService $timetable): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeAction($request, 'timetable.view');
        $resolved = $this->resolveMineGrid($request, $timetable);
        $weekDays = $timetable->weekDays();
        $teachingPeriods = $timetable->periods()->where('is_teaching_period', true);
        $grid = $resolved['grid'];

        return response()->streamDownload(function () use ($weekDays, $teachingPeriods, $grid) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Day', 'Period', 'Time', 'Subject', 'Teacher']);
            foreach ($weekDays as $dayKey => $dayLabel) {
                foreach ($teachingPeriods as $period) {
                    $entry = $grid[$dayKey.':'.$period->id] ?? null;
                    fputcsv($out, [
                        $dayLabel,
                        $period->name,
                        $period->timeLabel(),
                        $entry?->subject?->name ?? '',
                        $entry?->staff?->fullName() ?? '',
                    ]);
                }
            }
            fclose($out);
        }, 'my-timetable.csv');
    }

    /**
     * A parent/student's own read only Examination Timetable (user
     * request 2026-07-27: "this is also applicable to examination
     * timetable where parent click on the button there to view the
     * examination timetable and if its not available, it shows not
     * available... timetable pages should also have term switcher
     * too"). Unlike examIndex() above (the admin builder, an explicit
     * scope/date-range picker), this resolves the child's own class and
     * section automatically and is read only. A term switcher is
     * buildable here, unlike the plain weekly timetable which has no
     * term concept anywhere in its schema, because ExamTimetableEntry
     * rows carry a real exam_date and a Term has real starts_on/ends_on
     * dates, so filtering is just a date range match, no schema change
     * needed.
     */
    public function examMine(Request $request, \App\Services\Timetable\ExamTimetableService $exams): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'timetable.view');
        $school = $this->school($request);
        $user = Auth::user();
        $studentIds = app(FamilyScope::class)->studentIds($user, $school);
        abort_if($studentIds === null || $studentIds->isEmpty(), 404);

        $children = Student::whereIn('id', $studentIds)
            ->with('currentClassArm.schoolClass.section')->get();

        $terms = Term::with('session')->get()
            ->sortByDesc(fn (Term $t) => $t->chronologicalSortKey())
            ->values();
        $termId = $request->integer('term_id') ?: Term::current()->first()?->id;
        $term = $terms->firstWhere('id', $termId);

        $papers = $exams->papers()->where('is_break', false);

        // "All children at once" (user request 2026-07-27), the same
        // ?student_id=all pattern the plain weekly timetable already
        // uses, one card per child rather than switching one at a time.
        if ($request->query('student_id') === 'all' && $children->count() > 1) {
            $allExamGrids = $children->map(function (Student $s) use ($term, $exams) {
                [$entries, $dates] = $this->resolveExamDates($s, $term, $exams);

                return ['student' => $s, 'entries' => $entries, 'dates' => $dates];
            })->values();

            return view('school.timetable.exam-mine', [
                'school' => $school,
                'accent' => $school->branding()['color'],
                'children' => $children,
                'selectedChildId' => null,
                'papers' => $papers,
                'entries' => [],
                'dates' => collect(),
                'allExamGrids' => $allExamGrids,
                'terms' => $terms,
                'term' => $term,
            ]);
        }

        $studentId = $request->integer('student_id') ?: $children->first()?->id;
        $selectedChild = $children->firstWhere('id', $studentId) ?? $children->first();
        [$entries, $dates] = $selectedChild ? $this->resolveExamDates($selectedChild, $term, $exams) : [[], collect()];

        return view('school.timetable.exam-mine', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'children' => $children,
            'selectedChildId' => $selectedChild?->id,
            'papers' => $papers,
            'entries' => $entries,
            'dates' => $dates,
            'allExamGrids' => collect(),
            'terms' => $terms,
            'term' => $term,
        ]);
    }

    /**
     * @return array{0: array<string, \App\Models\Tenant\ExamTimetableEntry>, 1: \Illuminate\Support\Collection}
     */
    protected function resolveExamDates(Student $student, ?Term $term, \App\Services\Timetable\ExamTimetableService $exams): array
    {
        $entries = [];
        $dates = collect();
        $class = $student->currentClassArm?->schoolClass;
        if ($class) {
            // A class specific entry and a whole section entry can both
            // exist for the same date/paper; both are shown (merged, class
            // last so it wins the keyBy collision) rather than picking one
            // scope and silently hiding the other, matching how the admin
            // builder itself treats scope as two independent grids, never
            // merged into one on save.
            $sectionEntries = $class->section ? $exams->entriesFor(\App\Models\Tenant\ExamTimetableEntry::SCOPE_SECTION, $class->section->id) : [];
            $classEntries = $exams->entriesFor(\App\Models\Tenant\ExamTimetableEntry::SCOPE_CLASS, $class->id);
            $entries = $sectionEntries + $classEntries;

            $dates = collect($entries)->map(fn ($e) => $e->exam_date)->unique()->sort()->values();
            if ($term) {
                $dates = $dates->filter(fn ($d) => (! $term->starts_on || $d >= $term->starts_on->toDateString())
                    && (! $term->ends_on || $d <= $term->ends_on->toDateString()))->values();
            }
        }

        return [$entries, $dates];
    }

    /**
     * Every class's weekly grid on one screen (user request 2026-07-21),
     * for leadership roles who want to see the whole school at a glance
     * rather than picking one class at a time. Same timetable.update
     * gate as the admin grid editor above: exactly the admin-tier/
     * leadership roles the request named already hold it via their own
     * blanket grants (school-owner/school-admin/principal/head-teacher
     * hold '*', vice-principal holds 'timetable.*' explicitly).
     */
    public function master(Request $request, TimetableService $timetable): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'timetable.update');
        $school = $this->school($request);
        $term = $this->resolveOptionalTerm($request);
        $terms = Term::with('session')->get()
            ->sortByDesc(fn (Term $t) => $t->chronologicalSortKey())
            ->values();

        return view('school.timetable.master', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'periods' => $timetable->periods(),
            'weekDays' => $timetable->weekDays(),
            'classGrids' => $timetable->masterGrid($term),
            'term' => $term,
            'terms' => $terms,
        ]);
    }

    /**
     * A CSV export of the master grid (user request 2026-07-22: "Make the
     * Master Timetable exportable as csv or pdf"). One row per class per
     * teaching period per day rather than trying to reproduce the on
     * screen day-by-day tables in CSV, since a flat row-per-slot shape is
     * what a spreadsheet is actually useful for (filtering, sorting by
     * teacher, etc). "PDF" is covered by the page's own Print button
     * (browser print/save-as-PDF), the same generate-on-demand pattern
     * every other exportable document in this app already uses, per the
     * standing Dbase recommendation against storing generated PDFs.
     */
    public function masterExport(Request $request, TimetableService $timetable): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeAction($request, 'timetable.update');
        $weekDays = $timetable->weekDays();
        $teachingPeriods = $timetable->periods()->where('is_teaching_period', true);
        $classGrids = $timetable->masterGrid($this->resolveOptionalTerm($request));

        return response()->streamDownload(function () use ($weekDays, $teachingPeriods, $classGrids) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Day', 'Class', 'Period', 'Time', 'Subject', 'Teacher']);
            foreach ($weekDays as $dayKey => $dayLabel) {
                foreach ($classGrids as $row) {
                    foreach ($teachingPeriods as $period) {
                        $entry = $row['grid'][$dayKey.':'.$period->id] ?? null;
                        fputcsv($out, [
                            $dayLabel,
                            $row['arm']->label(),
                            $period->name,
                            $period->timeLabel(),
                            $entry?->subject?->name ?? '',
                            $entry?->staff?->fullName() ?? '',
                        ]);
                    }
                }
            }
            fclose($out);
        }, 'master-timetable.csv');
    }

    /**
     * Examination Timetable (user request 2026-07-21): a genuine
     * subsection under Timetable, deliberately its own screens/service
     * (App\Services\Timetable\ExamTimetableService) rather than reusing
     * the weekly grid above — an exam timetable is dated across a real
     * calendar window and duration-driven, not a recurring day-of-week
     * pattern with independently timed rows.
     */
    public function examPapers(Request $request, \App\Services\Timetable\ExamTimetableService $exams): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'timetable.update');
        $school = $this->school($request);

        return view('school.timetable.exam-papers', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'papers' => $exams->papers(),
        ]);
    }

    public function examPapersUpdate(Request $request, \App\Services\Timetable\ExamTimetableService $exams): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'timetable.update');
        $data = $request->validate([
            'first_start' => ['required', 'date_format:H:i'],
            'papers' => ['required', 'array', 'min:1'],
            'papers.*.name' => ['required', 'string', 'max:60'],
            'papers.*.duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'papers.*.is_break' => ['nullable', 'boolean'],
        ]);

        $exams->applyPapers($data['first_start'], $data['papers']);

        return redirect()->route('school.timetable.exam.papers', ['school' => $this->school($request)->slug])
            ->with('status', 'Exam day structure saved, every paper\'s time updated.');
    }

    protected function examScopeOptions(): array
    {
        return [
            'sections' => \App\Models\Tenant\Section::orderBy('sequence')->get(),
            'classes' => \App\Models\Tenant\SchoolClass::with('section')->get()
                ->sortBy(fn ($c) => sprintf('%05d-%05d', $c->section?->sequence ?? 0, $c->sequence ?? 0)),
        ];
    }

    public function examIndex(Request $request, \App\Services\Timetable\ExamTimetableService $exams): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'timetable.update');
        $school = $this->school($request);
        $papers = $exams->papers();

        $scopeType = in_array($request->query('scope_type'), [\App\Models\Tenant\ExamTimetableEntry::SCOPE_SECTION, \App\Models\Tenant\ExamTimetableEntry::SCOPE_CLASS], true)
            ? $request->query('scope_type') : null;
        $scopeId = $request->integer('scope_id') ?: null;
        $startDate = $request->query('start_date');
        $days = $request->integer('days') ?: null;

        // Default to whatever already has real entries, rather than an
        // empty picker with nothing selected (user report 2026-07-22:
        // visiting this page showed no data even though it had already
        // been populated — the picker just never had anything chosen).
        if (! $scopeType || ! $scopeId || ! $startDate) {
            $firstEntry = \App\Models\Tenant\ExamTimetableEntry::orderBy('exam_date')->first();
            if ($firstEntry) {
                $scopeType = $scopeType ?: $firstEntry->scope_type;
                $scopeId = $scopeId ?: $firstEntry->scope_id;
                if (! $startDate) {
                    $startDate = $firstEntry->exam_date;
                    $lastEntry = \App\Models\Tenant\ExamTimetableEntry::where('scope_type', $scopeType)->where('scope_id', $scopeId)
                        ->orderByDesc('exam_date')->first();
                    $days = $days ?: min(30, max(1, \Illuminate\Support\Carbon::parse($startDate)->diffInDays(\Illuminate\Support\Carbon::parse($lastEntry->exam_date)) + 1));
                }
            }
        }
        $days = max(1, min(30, $days ?: 10));

        $dates = [];
        $entries = [];
        if ($scopeType && $scopeId && $startDate) {
            $cursor = \Illuminate\Support\Carbon::parse($startDate);
            while (count($dates) < $days) {
                if (! $cursor->isWeekend()) {
                    $dates[] = $cursor->toDateString();
                }
                $cursor->addDay();
            }
            $entries = $exams->entriesFor($scopeType, $scopeId);
        }

        return view('school.timetable.exam-index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'papers' => $papers,
            'teachingPapers' => $papers->where('is_break', false),
            'subjects' => \App\Models\Tenant\Subject::orderBy('name')->get(),
            'scopeOptions' => $this->examScopeOptions(),
            'scopeType' => $scopeType,
            'scopeId' => $scopeId,
            'startDate' => $startDate,
            'days' => $days,
            'dates' => $dates,
            'entries' => $entries,
        ]);
    }

    public function examSave(Request $request, \App\Services\Timetable\ExamTimetableService $exams): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'timetable.update');
        $data = $request->validate([
            'scope_type' => ['required', 'in:section,class'],
            'scope_id' => ['required', 'integer'],
            'rows' => ['nullable', 'array'],
        ]);

        $exams->saveEntries($data['scope_type'], $data['scope_id'], $data['rows'] ?? []);

        return back()->with('status', 'Examination timetable saved.');
    }

    /**
     * A blank fill-in template for the currently selected scope/date
     * window (user request: "the system should provide a template to be
     * filled and uploaded"), one row per date x teaching paper.
     */
    public function examTemplate(Request $request, \App\Services\Timetable\ExamTimetableService $exams): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeAction($request, 'timetable.update');
        $data = $request->validate([
            'scope_type' => ['required', 'in:section,class'],
            'scope_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            'days' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        $teachingPapers = $exams->papers()->where('is_break', false)->values();
        $dates = [];
        $cursor = \Illuminate\Support\Carbon::parse($data['start_date']);
        while (count($dates) < $data['days']) {
            if (! $cursor->isWeekend()) {
                $dates[] = $cursor->toDateString();
            }
            $cursor->addDay();
        }

        return response()->streamDownload(function () use ($dates, $teachingPapers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Paper', 'Time', 'Subject']);
            foreach ($dates as $date) {
                foreach ($teachingPapers as $paper) {
                    fputcsv($out, [$date, $paper->name, $paper->timeLabel(), '']);
                }
            }
            fclose($out);
        }, 'exam-timetable-template.csv');
    }

    public function examUpload(Request $request, \App\Services\Timetable\ExamTimetableService $exams): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'timetable.update');
        $data = $request->validate([
            'scope_type' => ['required', 'in:section,class'],
            'scope_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $papersByName = $exams->papers()->keyBy(fn ($p) => strtolower(trim($p->name)));
        $subjectsByName = \App\Models\Tenant\Subject::get()->keyBy(fn ($s) => strtolower(trim($s->name)));

        $handle = fopen($data['file']->getRealPath(), 'r');
        fgetcsv($handle); // header row
        $rows = [];
        $skipped = 0;
        while (($line = fgetcsv($handle)) !== false) {
            [$date, $paperName, , $subjectName] = array_pad($line, 4, null);
            $paper = $papersByName->get(strtolower(trim((string) $paperName)));
            $subject = $subjectName ? $subjectsByName->get(strtolower(trim($subjectName))) : null;
            if (! $paper || ! $date || ! $subject) {
                if (trim((string) $subjectName) !== '') {
                    $skipped++;
                }

                continue;
            }
            $rows[trim($date)][$paper->id] = $subject->id;
        }
        fclose($handle);

        $exams->saveEntries($data['scope_type'], $data['scope_id'], $rows);

        return back()->with('status', $skipped > 0
            ? 'Examination timetable uploaded, '.$skipped.' row(s) skipped (unrecognized paper or subject name).'
            : 'Examination timetable uploaded.');
    }

    /**
     * A CSV export of the currently displayed exam grid (user request
     * 2026-07-22: "Examination timetable should also appear like the
     * [Master Timetable, exportable]"). Distinct from examTemplate()
     * above: that one is always blank, for filling in and uploading back;
     * this one carries whatever subjects are actually already assigned,
     * for the same "download and see what we have" use case the Master
     * Timetable export serves.
     */
    public function examExport(Request $request, \App\Services\Timetable\ExamTimetableService $exams): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeAction($request, 'timetable.update');
        $data = $request->validate([
            'scope_type' => ['required', 'in:section,class'],
            'scope_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            'days' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        $teachingPapers = $exams->papers()->where('is_break', false)->values();
        $entries = $exams->entriesFor($data['scope_type'], $data['scope_id']);
        $dates = [];
        $cursor = \Illuminate\Support\Carbon::parse($data['start_date']);
        while (count($dates) < $data['days']) {
            if (! $cursor->isWeekend()) {
                $dates[] = $cursor->toDateString();
            }
            $cursor->addDay();
        }

        return response()->streamDownload(function () use ($dates, $teachingPapers, $entries) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Paper', 'Time', 'Subject']);
            foreach ($dates as $date) {
                foreach ($teachingPapers as $paper) {
                    $entry = $entries[$date.':'.$paper->id] ?? null;
                    fputcsv($out, [$date, $paper->name, $paper->timeLabel(), $entry?->subject?->name ?? '']);
                }
            }
            fclose($out);
        }, 'exam-timetable.csv');
    }
}
