<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Attendance;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Services\Attendance\AttendanceService;
use App\Services\Bulk\CsvReader;
use App\Services\Rbac\Rbac;
use App\Services\Rbac\TeacherScope;
use Illuminate\Support\Carbon;
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
        // A teacher assigned to exactly one class should not have to pick
        // it from a dropdown of one (user report 2026-07-22: "each class
        // teacher should not have to select their class if its just one
        // class assigned to them but if its more than one class assigned,
        // the dropdown option should show"). Only auto-selects when
        // nothing was explicitly chosen, so switching classes via the
        // dropdown still works normally for anyone with more than one.
        if (! $selectedArm && ! $classArmId && $classArms->count() === 1) {
            $selectedArm = $classArms->first();
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

        // Surfaced up front rather than only as a failed-save error (user
        // directive 2026-08-05) — a scoped teacher sees exactly why the
        // form is closed before they even try to save. The same
        // markClass()'s own check is the real enforcement; this is just
        // an honest heads up.
        $termClosedMessage = null;
        if ($scopedArmIds !== null) {
            $dateTerm = \App\Models\Tenant\Term::where('starts_on', '<=', $date)
                ->where('ends_on', '>=', $date)->first();
            if ($dateTerm && $dateTerm->ends_on->isPast()) {
                $termClosedMessage = "{$dateTerm->name} ended on {$dateTerm->ends_on->format('d M Y')}. Attendance can no longer be marked for it.";
            }
        }

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
            'canMark' => $this->canMark($school) && ! $termClosedMessage,
            'termClosedMessage' => $termClosedMessage,
            'nonSchoolDates' => $nonSchoolDates,
        ]);
    }

    /**
     * Attendance Dashboard (user directive 2026-07-16, "ALL THERE": build
     * out the remaining Reports & Analytics "Soon" items). A trend over
     * the last 14 real school days (weekends/holidays excluded, reusing
     * AttendanceService::nonSchoolDatesWithin() rather than a second copy
     * of that logic) plus, for an unrestricted (admin type) user, a per
     * class comparison; a restricted teacher role only ever sees the
     * trend for their own class arm(s), same TeacherScope narrowing
     * markClass()/index() already apply.
     */
    public function dashboard(Request $request, TeacherScope $teacherScope): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'attendance.view');
        $school = $this->school($request);
        $user = Auth::user();
        $armIds = $teacherScope->classArmIds($user, $school);

        // Staff/student toggle and a specific-date picker (user report
        // 2026-07-22: "one should be able to switch between staff
        // attendance and student attendance here... one should have a
        // date/calendar at the top which allows one to go to a date and
        // see the data there which should only allows previous dates
        // only"). Staff attendance already had its own mark screen
        // (staffIndex()/markStaff()), it just never surfaced here.
        // Staff.view also gates the staff type, the same pairing
        // staffIndex() itself already requires.
        $type = $request->query('type') === 'staff' && app(Rbac::class)->check($user, $school, 'staff.view')
            ? Attendance::PERSON_STAFF : Attendance::PERSON_STUDENT;
        $scope = fn ($q) => $type === Attendance::PERSON_STAFF ? $q->staff() : $q->students();

        $requestedDate = $request->query('date');
        $selectedDate = ($requestedDate && Carbon::parse($requestedDate)->lte(now()))
            ? Carbon::parse($requestedDate) : now();

        $service = app(AttendanceService::class);
        $to = $selectedDate->copy();
        $from = $selectedDate->copy()->subDays(60);
        $nonSchoolDates = $service->nonSchoolDatesWithin($from, $to);

        $schoolDates = collect();
        for ($d = $to->copy(); $d->gte($from) && $schoolDates->count() < 14; $d->subDay()) {
            if (! isset($nonSchoolDates[$d->toDateString()])) {
                $schoolDates->push($d->toDateString());
            }
        }
        $schoolDates = $schoolDates->sort()->values();

        $marksQuery = Attendance::query()->tap($scope)->whereIn('date', $schoolDates->all());
        if ($type === Attendance::PERSON_STUDENT && $armIds !== null) {
            $marksQuery->whereIn('class_arm_id', $armIds);
        }
        $marksByDate = $marksQuery->selectRaw('date, status, count(*) as total')
            ->groupBy('date', 'status')->get()->groupBy('date');

        $trend = $schoolDates->map(function ($date) use ($marksByDate) {
            $dayMarks = $marksByDate->get($date, collect());
            $total = $dayMarks->sum('total');
            $present = $dayMarks->firstWhere('status', 'present')?->total ?? 0;

            return [
                'date' => $date,
                'label' => \Illuminate\Support\Carbon::parse($date)->format('j M'),
                'rate' => $total > 0 ? round($present / $total * 100, 1) : null,
                'total' => $total,
            ];
        })->values();

        // Per class comparison only makes sense for students; staff are
        // not tied to a class arm at all.
        $classRates = collect();
        if ($type === Attendance::PERSON_STUDENT && $armIds === null) {
            $classArms = ClassArm::with('schoolClass.section', 'arm')->get();
            $classMarks = Attendance::students()->whereIn('date', $schoolDates->all())
                ->selectRaw('class_arm_id, status, count(*) as total')
                ->groupBy('class_arm_id', 'status')->get()->groupBy('class_arm_id');

            foreach ($classArms as $arm) {
                $armMarks = $classMarks->get($arm->id, collect());
                $total = $armMarks->sum('total');
                if ($total === 0) {
                    continue;
                }
                $present = $armMarks->firstWhere('status', 'present')?->total ?? 0;
                $classRates->push(['label' => $arm->label(), 'rate' => round($present / $total * 100, 1)]);
            }
            $classRates = $classRates->sortByDesc('rate')->values();
        }

        $selectedCounts = Attendance::query()->tap($scope)
            ->when($type === Attendance::PERSON_STUDENT && $armIds !== null, fn ($q) => $q->whereIn('class_arm_id', $armIds))
            ->forDate($selectedDate)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $selectedMarked = $selectedCounts->sum();

        return view('school.attendance.dashboard', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'trend' => $trend,
            'classRates' => $classRates,
            'statuses' => Attendance::statuses(),
            'type' => $type,
            'canViewStaff' => app(Rbac::class)->check($user, $school, 'staff.view'),
            'selectedDate' => $selectedDate->toDateString(),
            'isToday' => $selectedDate->isToday(),
            'todayCounts' => $selectedCounts,
            'todayMarked' => $selectedMarked,
            'todayRate' => $selectedMarked > 0 ? round((($selectedCounts['present'] ?? 0) / $selectedMarked) * 100, 1) : null,
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

        // A term that has ended is closed to new marking for an ordinary
        // (scoped) teacher — user directive 2026-08-05: "make it
        // impossible for teachers to mark attendance after the last day
        // of the term or end of term as set under the calendar". A
        // blanket/admin role (scopedArmIds() null, the same signal
        // TeacherScope already uses to mean "not one of the teacher-
        // scoped roles") can still correct historical records, mirroring
        // how ExaminationController's own blanket roles bypass its
        // window checks.
        if ($scopedArmIds !== null) {
            $term = \App\Models\Tenant\Term::where('starts_on', '<=', $data['date'])
                ->where('ends_on', '>=', $data['date'])->first();
            if ($term && $term->ends_on->isPast()) {
                return back()->withInput()->with('error', "{$term->name} ended on {$term->ends_on->format('d M Y')}. Attendance can no longer be marked for it.");
            }
        }

        // Only ever mark students who genuinely belong to this class arm
        // right now, a stale or crafted student id in the submitted form
        // is silently dropped rather than acted on.
        $validStudentIds = Student::active()->where('current_class_arm_id', $data['class_arm_id'])->pluck('id')->all();
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

    protected function assertScoped(Request $request, School $school, int $classArmId): void
    {
        $scopedArmIds = app(TeacherScope::class)->classArmIds(Auth::user(), $school);
        abort_if($scopedArmIds !== null && ! $scopedArmIds->contains($classArmId), 404);
    }

    /**
     * A pre-filled CSV for one class arm over a date range the user picks
     * (user directive 2026-07-16: "download the class bulk attendance with
     * all student name and the dates (user will ask for the days they want
     * to enter)... fill it and upload it back which will then populate all
     * the attendance record"), same round trip shape as the Results bulk
     * template: one column per date, existing marks already filled in so
     * this doubles as an export. Weekends and holidays are left out of the
     * date columns entirely, not just blocked on upload, reusing
     * AttendanceService::nonSchoolDatesWithin() rather than a second copy
     * of that logic.
     */
    public function bulkTemplate(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request, 'attendance.create');
        $school = $this->school($request);

        $data = $request->validate([
            'class_arm_id' => ['required', 'exists:tenant.class_arms,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $this->assertScoped($request, $school, (int) $data['class_arm_id']);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->startOfDay();
        abort_if($from->diffInDays($to) > 92, 422, 'Pick a date range of 92 days or less.');

        $service = app(AttendanceService::class);
        $nonSchoolDates = $service->nonSchoolDatesWithin($from, $to);
        $dates = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if (! isset($nonSchoolDates[$d->toDateString()])) {
                $dates[] = $d->toDateString();
            }
        }
        abort_if(empty($dates), 422, 'Every date in that range is a weekend or holiday, nothing to download.');

        $students = Student::active()->where('current_class_arm_id', $data['class_arm_id'])
            ->orderBy('first_name')->get();

        $existing = Attendance::students()->where('class_arm_id', $data['class_arm_id'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn (Attendance $a) => $a->person_id.':'.$a->date);

        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_merge(['admission_number', 'name'], $dates));
        foreach ($students as $student) {
            $row = [$student->admission_number, $student->fullName()];
            foreach ($dates as $date) {
                $row[] = $existing->get($student->id.':'.$date)?->status ?? '';
            }
            fputcsv($out, $row);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);

        $classArm = ClassArm::find($data['class_arm_id']);
        $filename = 'attendance-'.\Illuminate\Support\Str::slug($classArm?->label() ?? 'class').'-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Re-upload of the template above. A blank cell is skipped, never
     * treated as absent (same "fill in what changed" convention the
     * Results bulk upload and Houses' bulk CSV already use), so a
     * partially filled sheet never wipes a mark no one meant to touch.
     * Each date column is checked against nonSchoolDayReason() again (not
     * just relying on the template already excluding them), in case
     * someone hand edits the header back in.
     */
    public function bulkUpload(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'attendance.create');
        $school = $this->school($request);

        $data = $request->validate([
            'class_arm_id' => ['required', 'exists:tenant.class_arms,id'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);
        $classArmId = (int) $data['class_arm_id'];
        $this->assertScoped($request, $school, $classArmId);

        $csv = file_get_contents($request->file('file')->getRealPath());
        try {
            $rows = app(CsvReader::class)->rows($csv);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $students = Student::active()->where('current_class_arm_id', $classArmId)->get()->keyBy('admission_number');
        $service = app(AttendanceService::class);
        $validStatuses = array_map('strtolower', Attendance::statuses());

        $dateColumns = [];
        foreach ($rows[0] ?? [] as $column => $value) {
            if (in_array($column, ['admission_number', 'name'], true)) {
                continue;
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $column)) {
                continue;
            }
            $dateColumns[$column] = $service->nonSchoolDayReason($column);
        }

        $saved = 0;
        $errors = [];
        $skippedNonSchoolDay = 0;

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $admissionNumber = trim((string) ($row['admission_number'] ?? ''));
            if ($admissionNumber === '') {
                continue;
            }
            $student = $students->get($admissionNumber);
            if (! $student) {
                $errors[] = "Row {$rowNumber}: no student with admission number \"{$admissionNumber}\" in this class.";

                continue;
            }

            foreach ($dateColumns as $date => $nonSchoolReason) {
                $value = $row[$date] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if ($nonSchoolReason) {
                    $skippedNonSchoolDay++;

                    continue;
                }
                $status = strtolower(trim($value));
                if (! in_array($status, $validStatuses, true)) {
                    $errors[] = "Row {$rowNumber} ({$admissionNumber}), {$date}: \"{$value}\" is not a valid status.";

                    continue;
                }

                Attendance::updateOrCreate(
                    ['person_type' => Attendance::PERSON_STUDENT, 'person_id' => $student->id, 'date' => $date],
                    ['class_arm_id' => $classArmId, 'status' => $status, 'recorded_by_user_id' => Auth::id()]
                );
                $saved++;
            }
        }

        $message = "{$saved} attendance record(s) saved.";
        if ($skippedNonSchoolDay > 0) {
            $message .= " {$skippedNonSchoolDay} cell(s) on a weekend or holiday were skipped.";
        }
        if ($errors) {
            $message .= ' '.count($errors).' issue(s), first: '.$errors[0];
        }

        return redirect()->route('school.attendance.index', ['school' => $school->slug, 'class_arm_id' => $classArmId])
            ->with($errors ? 'error' : 'status', $message);
    }
}
