<?php

namespace App\Services\Dashboard;

use App\Models\School;
use App\Models\User;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\Attendance;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\ReportCardReview;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Models\Tenant\Term;
use App\Services\Maintenance\HealthService;
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
    public function __construct(protected ResultService $results, protected HealthService $health)
    {
    }

    public function build(User $user, School $school, int $unread = 0): array
    {
        $role = $user->is_super_admin ? null : $user->roleIn($school);
        $slug = $user->is_super_admin ? 'school-owner' : ($role?->slug ?? '');

        return match (true) {
            // School Owner and School Administrator are two different real
            // roles (user correction 2026-07-12: "there's a difference
            // between School Administrator and school super admin/admin
            // ... create the unique difference to avoid mixup") that this
            // code had been silently merging into one identical dashboard.
            // Owner/Principal/Head Teacher/Vice Principal get the business
            // wide leadership view; School Administrator gets the day to
            // day operational one below.
            in_array($slug, config('skulos.leadership_role_slugs'), true) => $this->admin($school),
            $slug === 'school-admin' => $this->schoolAdministrator(),
            $slug === 'class-teacher' => $this->classTeam($user, 'class_teacher'),
            $slug === 'class-assistant' => $this->classTeam($user, 'class_assistant'),
            $slug === 'subject-teacher' => $this->subjectTeacher($user),
            $slug === 'examination-officer' => $this->examinationOfficer(),
            $slug === 'registrar' => $this->registrar(),
            $slug === 'ict-officer' => $this->ictOfficer($school),
            $slug === 'parent' => $this->parent($user),
            $slug === 'student' => $this->student($user),
            // These roles' real modules (Library, Medical, Hostel, Transport)
            // are not built yet, on any plan, so their dashboard shows
            // whatever real data does overlap with their own permission
            // grants (never fabricated module figures) plus a clear note of
            // what is still coming (user direction 2026-07-12: "update for
            // all roles in the DASHBOARD DESIGN doc... as we build more
            // data, the ones left will show on the necessary user
            // dashboards" — i.e. this grows in place as each module gets
            // built, rather than staying a permanent placeholder). Bursar/
            // Accountant moved off this pattern 2026-07-17 once the
            // Foundation Finance slice gave them something real to see.
            in_array($slug, ['bursar', 'accountant'], true) => $this->financeOverview(),
            $slug === 'librarian' => $this->peopleOnly('library', false),
            $slug === 'nurse' => $this->peopleOnly('medical', false),
            $slug === 'hostel-master' => $this->peopleOnly('hostel', false),
            $slug === 'transport-officer' => $this->peopleOnly('transport', false),
            // Store Keeper (inventory.* only) and Security (no grants at
            // all, by design) have no real school data that overlaps with
            // their role whatsoever, unlike the five above which can at
            // least see student/parent counts, so these two stay an
            // honest "coming soon" rather than showing unrelated figures.
            in_array($slug, ['store-keeper', 'security'], true) => $this->comingSoon($slug),
            default => $this->general($unread),
        };
    }

    /**
     * The owner/admin/principal/head teacher/vice principal figures (user
     * report 2026-07-12: "my current admins dashboard is yet to change"
     * — the dashboard audit above added three brand new role dashboards
     * but left this one, the one most admins actually look at, exactly as
     * it was). Now surfaces two things that did not exist when this was
     * first built: report card review status (tying directly into the
     * 2026-07-11 review workflow) and backup health, both real, both
     * already computed for the Examination Officer / ICT Officer
     * dashboards, reused here rather than re-implemented. Eight figures,
     * not four, so the grid still resolves to complete rows (design
     * system rule: a set of items divides evenly into its column count).
     */
    protected function admin(?School $school = null): array
    {
        $term = Term::current()->first();
        $avg = $term ? app(\App\Services\Results\ResultService::class)->termAveragePercentage($term->id) : null;
        $pendingReviews = $term
            ? ReportCardReview::where('term_id', $term->id)
                ->whereIn('status', [ReportCardReview::STATUS_PENDING_REVIEW, ReportCardReview::STATUS_NEEDS_CORRECTION])
                ->with('student.currentClassArm.schoolClass')
                ->get()
            : collect();
        $students = Student::active()->with('currentClassArm.schoolClass')->get();
        [$boys, $girls] = $this->genderCounts($students);
        $newThisTerm = $term ? Student::active()->where('created_at', '>=', $term->created_at)->count() : 0;
        $unassigned = Student::active()->whereNull('current_class_arm_id')->count();
        $uniqueGuardians = StudentGuardian::get(['email', 'phone', 'name'])
            ->map(fn ($g) => $g->email ?: ($g->phone ?: $g->name))
            ->unique()
            ->count();
        $studentRate = $this->attendanceRate(Attendance::PERSON_STUDENT);
        $staffRate = $this->attendanceRate(Attendance::PERSON_STAFF);
        // School Health Score (Dashboard Design Cross-Check, 2026-07-13:
        // "Would need most of the above to compute honestly") reuses the
        // exact same score HealthService already computes for the
        // Maintenance Center, not a second parallel scoring formula.
        $healthScore = $school ? $this->health->check($school)['score'] : null;

        // Every figure links to the real screen it summarises (user
        // directive 2026-07-16: "MAKE EACH CARD CLICKABLE THAT IT TAKES
        // ONE TO THE ACTUAL PAGE"). Built once here since every route
        // needs the school slug; a card with no obviously matching real
        // screen (Boys/Girls, both just a slice of Students) links to
        // the same page as its parent metric rather than going nowhere.
        $href = fn (string $route, array $params = []) => $school
            ? route($route, array_merge(['school' => $school->slug], $params))
            : null;
        $activeAssignments = \App\Models\Tenant\Assignment::whereNull('due_on')
            ->orWhere('due_on', '>=', now()->toDateString())->count();

        return [
            'kind' => 'admin',
            'figs' => [
                ['n' => number_format($students->count()), 'l' => 'Students', 'ic' => 'students', 'c' => '#0EA5A0', 'href' => $href('school.students')],
                ['n' => number_format($boys), 'l' => 'Boys', 'ic' => 'students', 'c' => '#6D4FE0', 'href' => $href('school.students')],
                ['n' => number_format($girls), 'l' => 'Girls', 'ic' => 'students', 'c' => '#E14F63', 'href' => $href('school.students')],
                // "Staff" and "Teachers" merged into one card (user
                // directive 2026-07-16: "WHY DO WE HAVE STAFF AND
                // TEACHERS DATA... MERGE INTO ONE AS STAFF"), the same
                // merge already applied to their nav/portal links earlier
                // this session, now applied to this dashboard card set
                // too.
                ['n' => number_format(Staff::active()->count()), 'l' => 'Staff', 'ic' => 'staff', 'c' => '#6D4FE0', 'href' => $href('school.staff.directory')],
                ['n' => number_format(SchoolClass::count()), 'l' => 'Classes', 'ic' => 'academics', 'c' => '#E5901C', 'href' => $href('school.academics.index')],
                ['n' => number_format(\App\Models\Tenant\House::count()), 'l' => 'Houses', 'ic' => 'school', 'c' => '#0EA5A0', 'href' => $href('school.houses.index')],
                ['n' => number_format($uniqueGuardians), 'l' => 'Parents', 'ic' => 'students', 'c' => '#6D4FE0', 'href' => $href('school.parents.directory')],
                ['n' => number_format(Subject::count()), 'l' => 'Subjects', 'ic' => 'academics', 'c' => '#0EA5A0', 'href' => $href('school.academics.index')],
                ['n' => $studentRate !== null ? "{$studentRate}%" : 'N/A', 'l' => 'Attendance Rate', 'ic' => 'students', 'c' => '#12A97B', 'href' => $href('school.attendance.dashboard')],
                ['n' => $staffRate !== null ? "{$staffRate}%" : 'N/A', 'l' => 'Staff Attendance', 'ic' => 'staff', 'c' => '#12A97B', 'href' => $href('school.attendance.staff')],
                ['n' => number_format($newThisTerm), 'l' => 'New Registrations', 'ic' => 'students', 'c' => '#E5901C', 'href' => $href('school.students')],
                // Replaces "Awaiting Class" (user report 2026-07-16: "WHAT
                // IS THE MEANING OF AWAITING CLASS, CHANGE THE DATA
                // THERE" — a niche admin metric, students registered but
                // not yet placed in a class arm, that read as unclear/not
                // obviously useful at a glance). Active Assignments is a
                // real, self-explanatory, immediately useful count
                // instead: homework/coursework not yet past its due date
                // (or with no due date at all), ties into the Assignments
                // feature moved under Academic earlier this session.
                ['n' => number_format($activeAssignments), 'l' => 'Active Assignments', 'ic' => 'assignments', 'c' => '#E14F63', 'href' => $href('school.assignments.index')],
                ['n' => number_format($pendingReviews->count()), 'l' => 'Reports Pending Review', 'ic' => 'results', 'c' => '#E5901C', 'href' => $href('school.report-cards.pending-review')],
                ['n' => $avg !== null ? "{$avg}%" : 'N/A', 'l' => 'Term Average', 'ic' => 'results', 'c' => '#6D4FE0', 'href' => $href('school.report-cards.index')],
                ['n' => $healthScore !== null ? $healthScore : 'N/A', 'l' => 'Health Score', 'ic' => 'health', 'c' => $healthScore === null ? '#E14F63' : ($healthScore >= 80 ? '#12A97B' : ($healthScore >= 50 ? '#E5901C' : '#E14F63')), 'href' => $href('school.maintenance.index')],
            ],
            'panel' => [
                'type' => 'admin_overview',
                'term' => $term?->name,
                'birthdaysToday' => $this->todaysBirthdays($students),
                'birthdaysUpcoming' => $this->upcomingBirthdays($students),
                'pendingReviews' => $pendingReviews->take(6)->map(fn ($r) => [
                    'student' => $r->student?->fullName(),
                    'class' => $r->student?->currentClassArm?->schoolClass?->name,
                    'status' => ReportCardReview::statusLabel($r->status),
                ])->all(),
                'pendingReviewsMore' => max(0, $pendingReviews->count() - 6),
                'resultTrend' => $this->resultTrend(),
                'attendanceAtRisk' => $this->attendanceAtRisk($students, $term),
                'performanceAtRisk' => $this->performanceAtRisk($students, $term),
            ],
        ];
    }

    /**
     * Case insensitive: seeded/imported student gender values are stored
     * as "Male"/"Female" (Title Case), not lowercase, a real bug found
     * live 2026-07-12 ("why is gender data saying no data, this is
     * wrong") — a straight ->where('gender', 'male') silently matched
     * zero rows against real data ever since this was first built
     * 2026-07-09.
     *
     * @param \Illuminate\Support\Collection<int, Student> $students
     * @return array{0: int, 1: int} [boys, girls]
     */
    protected function genderCounts($students): array
    {
        $boys = $students->filter(fn ($s) => strtolower((string) $s->gender) === 'male')->count();
        $girls = $students->filter(fn ($s) => strtolower((string) $s->gender) === 'female')->count();

        return [$boys, $girls];
    }

    /**
     * Today's attendance rate for students or staff (Dashboard Design
     * Cross-Check, 2026-07-13: "Attendance Rate, Staff Attendance" was
     * blocked because nothing could mark attendance yet; now that
     * AttendanceController exists, this reads the real register). Null,
     * not 0%, when nobody has ever been marked, so an empty register
     * reads as "not marked yet" rather than "everyone absent" (standing
     * no-dummy-data rule). Falls back to the most recent day within the
     * last 7 that actually has marks if today has none yet (user report
     * 2026-07-16, screenshot of a real school's dashboard: "FIX THE
     * Attendance Rate AND Staff Attendance SHOWING n/A - ADD THE DATA IF
     * NEEDED"): a bare "N/A" the moment nobody has opened the register
     * yet today gives strictly less information than the real, only
     * slightly stale rate from the last day it actually was taken.
     */
    protected function attendanceRate(string $personType): ?float
    {
        $date = Attendance::where('person_type', $personType)
            ->where('date', '>=', now()->subDays(7)->toDateString())
            ->orderByDesc('date')
            ->value('date');
        if (! $date) {
            return null;
        }

        $counts = Attendance::where('person_type', $personType)->forDate($date)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $marked = $counts->sum();
        if ($marked === 0) {
            return null;
        }

        return round((($counts['present'] ?? 0) / $marked) * 100, 1);
    }

    /**
     * The school's own configured at-risk thresholds (user directive
     * 2026-07-16: "add it to what admin can configure threshold for under
     * settings"). `config('skulos.at_risk_thresholds')` is only ever the
     * seed default now, per the project's "nothing hardcoded" rule (the
     * same DB-backed-not-config-read pattern as grading_scales/comment
     * bank): a school's own tenant `Setting` wins once it exists, edited
     * from AtRiskThresholdController.
     *
     * @return array{attendance_pct: int, score_pct: int}
     */
    protected function atRiskThresholds(): array
    {
        return \App\Models\Tenant\Setting::grab('at_risk_thresholds', config('skulos.at_risk_thresholds', ['attendance_pct' => 75, 'score_pct' => 40]));
    }

    /**
     * Students At Risk used to be one combined list flagging either a low
     * attendance rate or a low average score with both reasons folded into
     * one line (Dashboard Design Cross-Check, rule-based). Split into two
     * separate cards (user directive 2026-07-16, screenshot of the
     * combined widget: "the 'Students at risk'... section should be
     * divided into two cards, one for attendance and one for Performance
     * (based on scores from assessments scored enter)"), this one is the
     * attendance half. A student with no attendance marks yet this term is
     * not flagged (nothing to be "below" yet), matching the standing
     * no-dummy-data rule rather than treating missing data as a 0%.
     *
     * @param \Illuminate\Support\Collection<int, Student> $students
     * @return list<array{name: string, class: ?string, reason: string}>
     */
    protected function attendanceAtRisk($students, ?Term $term, int $limit = 8): array
    {
        if (! $term || ! $term->starts_on) {
            return [];
        }

        $threshold = $this->atRiskThresholds()['attendance_pct'];
        $end = min($term->ends_on ?? now(), now());

        $attendanceByStudent = Attendance::where('person_type', Attendance::PERSON_STUDENT)
            ->whereBetween('date', [$term->starts_on->toDateString(), $end->toDateString()])
            ->selectRaw('person_id, status, count(*) as total')
            ->groupBy('person_id', 'status')
            ->get()
            ->groupBy('person_id');

        $atRisk = [];
        foreach ($students as $student) {
            $marks = $attendanceByStudent->get($student->id);
            if (! $marks) {
                continue;
            }
            $total = $marks->sum('total');
            $present = $marks->firstWhere('status', 'present')?->total ?? 0;
            $rate = $total > 0 ? round(($present / $total) * 100, 1) : null;
            if ($rate !== null && $rate < $threshold) {
                $atRisk[] = [
                    'name' => $student->fullName(),
                    'class' => $student->currentClassArm?->schoolClass?->name,
                    'reason' => "Attendance {$rate}%",
                ];
            }
        }

        return array_slice($atRisk, 0, $limit);
    }

    /**
     * The performance half of the same split (see attendanceAtRisk()'s
     * docblock). Bug found 2026-07-16, the exact same one
     * ResultService::termAveragePercentage() was fixed for on
     * 2026-07-15: this used to be `Score::avg('score')` per student, a raw
     * average across individual ASSESSMENT COMPONENT rows (a CA out of 20
     * and an Exam out of 60 averaged together as if on the same scale),
     * not real subject percentages. Fixed to the same per-subject-sum-
     * then-normalize approach, computed in one bulk grouped query for
     * every student at once (not a per-student query in the loop, this
     * list can cover the whole school on the admin panel) rather than
     * calling ResultService::studentAveragePercentage() once per student.
     *
     * @param \Illuminate\Support\Collection<int, Student> $students
     * @return list<array{name: string, class: ?string, reason: string}>
     */
    protected function performanceAtRisk($students, ?Term $term, int $limit = 8): array
    {
        if (! $term) {
            return [];
        }

        $threshold = $this->atRiskThresholds()['score_pct'];
        // Delegates to ResultService::studentsBelowAverageThreshold()
        // (2026-07-16, moved there so the Student Performance Dashboard
        // screen can share the exact same computation for its own, full
        // uncapped list), reformatting 'average' back into this widget's
        // own 'reason' string shape for the shared _students-at-risk
        // partial and existing callers.
        $atRisk = app(\App\Services\Results\ResultService::class)
            ->studentsBelowAverageThreshold($students, $term->id, $threshold, $limit);

        return array_map(fn ($row) => [
            'name' => $row['name'],
            'class' => $row['class'],
            'reason' => "Average {$row['average']}%",
        ], $atRisk);
    }

    /**
     * Result Performance trend (Dashboard Design Cross-Check, 2026-07-13:
     * "Partial, Term Average card stands in, no trend chart"). The last 6
     * terms with any real scores recorded, in real chronological order
     * (academic session then term sequence, not creation order), each
     * with the real average across every score in that term. A term with
     * zero scores yet is skipped rather than shown as a misleading 0%.
     *
     * @return list<array{label: string, average: float}>
     */
    protected function resultTrend(int $limit = 6): array
    {
        // Moved to ResultService::resultTrend() (2026-07-16, Student
        // Performance Dashboard) so this widget and that dedicated
        // screen share the exact same real trend computation.
        return app(\App\Services\Results\ResultService::class)->resultTrend($limit);
    }

    /**
     * @param \Illuminate\Support\Collection<int, Student> $students
     */
    protected function birthdayEntry(Student $s): array
    {
        return [
            'name' => $s->fullName(),
            'age' => $s->date_of_birth?->age,
            'class' => $s->currentClassArm?->schoolClass?->name,
        ];
    }

    /**
     * Today's Birthdays (DASHBOARD DESIGN doc widget, School Owner/Admin
     * dashboards). Compared in PHP, not a database date function, to stay
     * MySQL/SQLite agnostic per the project's standing database rule.
     *
     * @param \Illuminate\Support\Collection<int, Student> $students
     */
    protected function todaysBirthdays($students): array
    {
        $today = now()->format('m-d');

        return $students->filter(fn ($s) => $s->date_of_birth && $s->date_of_birth->format('m-d') === $today)
            ->map(fn ($s) => $this->birthdayEntry($s))
            ->values()
            ->all();
    }

    /**
     * The next 5 birthdays in the next 30 days, not counting today (user
     * request 2026-07-12: "show a few upcoming birthdays with the same
     * approach"), each still with age and class.
     *
     * @param \Illuminate\Support\Collection<int, Student> $students
     */
    protected function upcomingBirthdays($students, int $withinDays = 30, int $limit = 5): array
    {
        $today = now()->startOfDay();

        return $students->filter(fn ($s) => $s->date_of_birth)
            ->map(function ($s) use ($today) {
                $next = $s->date_of_birth->copy()->setYear($today->year);
                if ($next->lt($today)) {
                    $next = $next->addYear();
                }

                return ['student' => $s, 'next' => $next, 'daysAway' => (int) $today->diffInDays($next)];
            })
            ->filter(fn ($row) => $row['daysAway'] > 0 && $row['daysAway'] <= $withinDays)
            ->sortBy('daysAway')
            ->take($limit)
            ->map(fn ($row) => array_merge($this->birthdayEntry($row['student']), ['date' => $row['next']->format('M j')]))
            ->values()
            ->all();
    }

    /**
     * The School Administrator role (DASHBOARD DESIGN doc: "Students,
     * Teachers, Admissions, Attendance, Timetable, Fees, Notifications,
     * Tasks" / "Pending Admissions, New Registrations, Missing Attendance,
     * Today's Events, Staff on Leave"), day to day operations rather than
     * the owner's business wide view (user correction 2026-07-12: these
     * are two different roles that must not share one dashboard).
     * Admissions/Attendance tracking/Timetable/Fees/Staff Leave are not
     * built on any plan yet, so their real operational task queue today is
     * new registrations and students still awaiting a class placement,
     * both genuine, both actionable from this screen.
     */
    protected function schoolAdministrator(): array
    {
        $term = Term::current()->first();
        $newThisTerm = $term && $term->created_at
            ? Student::active()->where('created_at', '>=', $term->created_at)->count()
            : 0;
        $unassigned = Student::active()->whereNull('current_class_arm_id')->count();
        $studentRate = $this->attendanceRate(Attendance::PERSON_STUDENT);
        $staffRate = $this->attendanceRate(Attendance::PERSON_STAFF);
        $uniqueGuardians = StudentGuardian::get(['email', 'phone', 'name'])
            ->map(fn ($g) => $g->email ?: ($g->phone ?: $g->name))
            ->unique()
            ->count();

        return [
            'kind' => 'school_admin',
            'figs' => [
                ['n' => number_format(Student::active()->count()), 'l' => 'Students', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '72%'],
                ['n' => number_format(Staff::active()->count()), 'l' => 'Staff', 'ic' => 'staff', 'c' => '#6D4FE0', 'pct' => '64%'],
                ['n' => number_format($uniqueGuardians), 'l' => 'Parents', 'ic' => 'students', 'c' => '#6D4FE0', 'pct' => '60%'],
                ['n' => number_format(Subject::count()), 'l' => 'Subjects', 'ic' => 'academics', 'c' => '#0EA5A0', 'pct' => '70%'],
                ['n' => $studentRate !== null ? "{$studentRate}%" : 'N/A', 'l' => 'Attendance Today', 'ic' => 'students', 'c' => '#12A97B', 'pct' => $studentRate !== null ? "{$studentRate}%" : '0%'],
                ['n' => $staffRate !== null ? "{$staffRate}%" : 'N/A', 'l' => 'Staff Attendance', 'ic' => 'staff', 'c' => '#12A97B', 'pct' => $staffRate !== null ? "{$staffRate}%" : '0%'],
                ['n' => number_format($newThisTerm), 'l' => 'New Registrations', 'ic' => 'students', 'c' => '#E5901C', 'pct' => '50%'],
                ['n' => number_format($unassigned), 'l' => 'Awaiting Class', 'ic' => 'students', 'c' => '#E14F63', 'pct' => '40%'],
            ],
            'panel' => ['type' => 'registrar_overview', 'unassigned' => $unassigned],
        ];
    }

    protected function classTeam(User $user, string $kind): array
    {
        $staff = Staff::where('user_id', $user->id)->first();
        // Bug found 2026-07-14 while extending dashboard test coverage: this
        // always looked up class_teacher_staff_id regardless of $kind, so a
        // real class assistant (assigned via the separate
        // assistant_teacher_staff_id column, see AcademicsController's own
        // save logic) never resolved a class arm here at all and silently
        // fell through to the empty "no_class" state.
        $column = $kind === 'class_assistant' ? 'assistant_teacher_staff_id' : 'class_teacher_staff_id';
        $classArm = $staff ? ClassArm::where($column, $staff->id)->first() : null;

        if (! $classArm) {
            return ['kind' => $kind, 'figs' => $this->emptyFigs(), 'panel' => ['type' => 'no_class']];
        }

        $students = Student::where('current_class_arm_id', $classArm->id)->active()->get();
        [$boys, $girls] = $this->genderCounts($students);
        $subjectsCount = $classArm?->offeredSubjects()->count() ?? 0;

        $term = Term::current()->first();
        $average = null;
        if ($term) {
            $broadsheet = $this->results->classBroadsheet($classArm->id, $term->id);
            $average = count($broadsheet) ? round(array_sum(array_column($broadsheet, 'average')) / count($broadsheet), 1) : null;
        }

        $todayCounts = Attendance::students()->where('class_arm_id', $classArm->id)->forDate(now())
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $marked = $todayCounts->sum();
        $present = $todayCounts['present'] ?? 0;
        $absent = $todayCounts['absent'] ?? 0;
        $attendanceRate = $marked > 0 ? round(($present / $marked) * 100, 1) : null;

        return [
            'kind' => $kind,
            'figs' => [
                ['n' => number_format($students->count()), 'l' => 'My Students', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '72%'],
                ['n' => number_format($boys), 'l' => 'Boys', 'ic' => 'students', 'c' => '#6D4FE0', 'pct' => '55%'],
                ['n' => number_format($girls), 'l' => 'Girls', 'ic' => 'students', 'c' => '#E14F63', 'pct' => '55%'],
                ['n' => number_format($subjectsCount), 'l' => 'Subjects', 'ic' => 'academics', 'c' => '#E5901C', 'pct' => '80%'],
                ['n' => $attendanceRate !== null ? "{$attendanceRate}%" : 'N/A', 'l' => 'Attendance Today', 'ic' => 'students', 'c' => '#12A97B', 'pct' => $attendanceRate !== null ? "{$attendanceRate}%" : '0%'],
                ['n' => number_format($present), 'l' => 'Present Today', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '60%'],
                ['n' => number_format($absent), 'l' => 'Absent Today', 'ic' => 'students', 'c' => '#E14F63', 'pct' => '30%'],
                ['n' => $average !== null ? "{$average}%" : 'N/A', 'l' => 'Class Average', 'ic' => 'results', 'c' => '#6D4FE0', 'pct' => '65%'],
            ],
            'panel' => [
                'type' => 'class_overview',
                'class_arm_id' => $classArm->id,
                'class_label' => $classArm->label(),
                'boys' => $boys,
                'girls' => $girls,
                'term' => $term?->name,
                'average' => $average,
                'read_only' => $kind === 'class_assistant',
                'birthdaysToday' => $this->todaysBirthdays($students),
                'birthdaysUpcoming' => $this->upcomingBirthdays($students),
                'attendanceAtRisk' => $this->attendanceAtRisk($students, $term),
                'performanceAtRisk' => $this->performanceAtRisk($students, $term),
                'upcomingExams' => \App\Models\Tenant\CalendarEvent::exams()->upcoming()
                    ->where(fn ($q) => $q->whereNull('class_arm_id')->orWhere('class_arm_id', $classArm->id))
                    ->with('subject')->take(5)->get()
                    ->map(fn ($e) => ['title' => $e->title, 'date' => $e->starts_on?->format('d M Y'), 'subject' => $e->subject?->name])
                    ->all(),
                'upcomingAssignments' => \App\Models\Tenant\Assignment::where('class_arm_id', $classArm->id)
                    ->upcoming()->with('subject')->take(5)->get()
                    ->map(fn ($a) => ['title' => $a->title, 'date' => $a->due_on?->format('d M Y'), 'subject' => $a->subject?->name])
                    ->all(),
            ],
        ];
    }

    protected function subjectTeacher(User $user): array
    {
        $staff = Staff::where('user_id', $user->id)->first();
        $assignments = $staff ? TeachingAssignment::where('staff_id', $staff->id)->with(['subject', 'classArm.schoolClass.section'])->get() : collect();

        if ($assignments->isEmpty()) {
            return ['kind' => 'subject_teacher', 'figs' => $this->emptyFigs(), 'panel' => ['type' => 'no_assignments']];
        }

        $subjectIds = $assignments->pluck('subject_id')->unique();
        $classArmIds = $assignments->pluck('class_arm_id')->unique();
        $taughtStudents = Student::whereIn('current_class_arm_id', $classArmIds)->active()->get();
        $studentsCount = $taughtStudents->count();

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

        $todayCounts = Attendance::students()->whereIn('class_arm_id', $classArmIds)->forDate(now())
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $marked = $todayCounts->sum();
        $present = $todayCounts['present'] ?? 0;
        $absent = $todayCounts['absent'] ?? 0;
        $attendanceRate = $marked > 0 ? round(($present / $marked) * 100, 1) : null;
        $classesMarkedToday = Attendance::students()->whereIn('class_arm_id', $classArmIds)->forDate(now())
            ->distinct('class_arm_id')->count('class_arm_id');

        return [
            'kind' => 'subject_teacher',
            'figs' => [
                ['n' => number_format($subjectIds->count()), 'l' => 'My Subjects', 'ic' => 'academics', 'c' => '#0EA5A0', 'pct' => '72%'],
                ['n' => number_format($classArmIds->count()), 'l' => 'My Classes', 'ic' => 'academics', 'c' => '#6D4FE0', 'pct' => '60%'],
                ['n' => number_format($studentsCount), 'l' => 'Students Taught', 'ic' => 'students', 'c' => '#E5901C', 'pct' => '75%'],
                ['n' => $average !== null ? "{$average}%" : 'N/A', 'l' => 'Average Score', 'ic' => 'results', 'c' => '#E14F63', 'pct' => '65%'],
                ['n' => $attendanceRate !== null ? "{$attendanceRate}%" : 'N/A', 'l' => 'Attendance Today', 'ic' => 'students', 'c' => '#12A97B', 'pct' => $attendanceRate !== null ? "{$attendanceRate}%" : '0%'],
                ['n' => number_format($present), 'l' => 'Present Today', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '60%'],
                ['n' => number_format($absent), 'l' => 'Absent Today', 'ic' => 'students', 'c' => '#E14F63', 'pct' => '30%'],
                ['n' => $classesMarkedToday.'/'.$classArmIds->count(), 'l' => 'Classes Marked Today', 'ic' => 'academics', 'c' => '#6D4FE0', 'pct' => $classArmIds->count() > 0 ? round(($classesMarkedToday / $classArmIds->count()) * 100).'%' : '0%'],
            ],
            'panel' => [
                'type' => 'subject_overview',
                // Grouped Section > Subject > class labels (user directive
                // 2026-07-12, "it should be effected on the dashboard
                // too": the same grouping built for the Students page
                // applies here as well, not just a flat assignment list.
                'groups' => $assignments
                    ->groupBy(fn ($a) => $a->classArm?->schoolClass?->section?->name ?? 'Other')
                    ->map(fn ($bySection) => $bySection
                        ->groupBy(fn ($a) => $a->subject?->name ?? 'Unknown')
                        ->map(fn ($bySubject) => $bySubject->map(fn ($a) => $a->classArm?->label())->filter()->values()->all())
                        ->all())
                    ->all(),
                'term' => $term?->name,
                'birthdaysToday' => $this->todaysBirthdays($taughtStudents),
                'birthdaysUpcoming' => $this->upcomingBirthdays($taughtStudents),
                'attendanceAtRisk' => $this->attendanceAtRisk($taughtStudents, $term),
                'performanceAtRisk' => $this->performanceAtRisk($taughtStudents, $term),
                'upcomingExams' => \App\Models\Tenant\CalendarEvent::exams()->upcoming()
                    ->where(fn ($q) => $q->whereNull('class_arm_id')->orWhereIn('class_arm_id', $classArmIds))
                    ->with('subject', 'classArm')->take(5)->get()
                    ->map(fn ($e) => ['title' => $e->title, 'date' => $e->starts_on?->format('d M Y'), 'subject' => $e->subject?->name, 'class' => $e->classArm?->label()])
                    ->all(),
                'upcomingAssignments' => \App\Models\Tenant\Assignment::whereIn('class_arm_id', $classArmIds)
                    ->upcoming()->with('subject', 'classArm')->take(5)->get()
                    ->map(fn ($a) => ['title' => $a->title, 'date' => $a->due_on?->format('d M Y'), 'subject' => $a->subject?->name, 'class' => $a->classArm?->label()])
                    ->all(),
            ],
        ];
    }

    /**
     * The Examination Officer cares about where results and report cards
     * stand right now (user request 2026-07-11, DASHBOARD DESIGN doc:
     * "Exams Scheduled, Results Pending, CA Pending, Published Results" /
     * "Missing Scores, Result Approval, CBT Sessions"). CBT and a
     * scheduled-exams concept do not exist in the product yet, so this
     * only surfaces what is real: report card review status, now that the
     * review workflow exists, plus assessment/grading setup counts.
     */
    protected function examinationOfficer(): array
    {
        $term = Term::current()->first();
        $pending = $term
            ? ReportCardReview::where('term_id', $term->id)
                ->whereIn('status', [ReportCardReview::STATUS_PENDING_REVIEW, ReportCardReview::STATUS_NEEDS_CORRECTION])
                ->with('student.currentClassArm.schoolClass')
                ->get()
            : collect();
        $released = $term
            ? ReportCardReview::where('term_id', $term->id)->where('status', ReportCardReview::STATUS_RELEASED)->count()
            : 0;
        // Exams Scheduled (Dashboard Design Cross-Check: "Exams Scheduled,
        // Results Pending, CA Pending, Published Results"), real now that
        // the simple exam calendar exists (2026-07-15): CalendarEvent rows
        // with category=exam. "Grading Scales" (a setup count, not a day
        // to day figure) is swapped out for it to keep the grid at 4,
        // closer to the doc's original intended 4 figures for this role.
        $upcomingExams = \App\Models\Tenant\CalendarEvent::exams()->upcoming()->with('subject', 'classArm.schoolClass')->get();

        return [
            'kind' => 'examination_officer',
            'figs' => [
                ['n' => number_format($pending->count()), 'l' => 'Pending Review', 'ic' => 'results', 'c' => '#E5901C', 'pct' => '50%'],
                ['n' => number_format($released), 'l' => 'Released', 'ic' => 'results', 'c' => '#0EA5A0', 'pct' => '70%'],
                ['n' => number_format(AssessmentComponent::count()), 'l' => 'Assessment Components', 'ic' => 'academics', 'c' => '#6D4FE0', 'pct' => '60%'],
                ['n' => number_format($upcomingExams->count()), 'l' => 'Exams Scheduled', 'ic' => 'calendar', 'c' => '#E14F63', 'pct' => '60%'],
            ],
            'panel' => [
                'type' => 'pending_reviews',
                'term' => $term?->name,
                'items' => $pending->take(8)->map(fn ($r) => [
                    'student' => $r->student?->fullName(),
                    'class' => $r->student?->currentClassArm?->schoolClass?->name,
                    'status' => ReportCardReview::statusLabel($r->status),
                ])->all(),
                'more' => max(0, $pending->count() - 8),
                'upcomingExams' => $upcomingExams->take(6)->map(fn ($e) => [
                    'title' => $e->title,
                    'date' => $e->starts_on?->format('d M Y'),
                    'subject' => $e->subject?->name,
                    'class' => $e->classArm?->label(),
                ])->all(),
            ],
        ];
    }

    /**
     * The Registrar cares about the student/parent records they maintain
     * (user request 2026-07-11, DASHBOARD DESIGN doc: "New Applications,
     * Pending Admissions, Accepted, Rejected"). Admissions is a deferred
     * module with no data behind it yet, so this surfaces the real
     * records the registrar role already has access to instead: total
     * students, total distinct guardians, and students still missing a
     * class placement, a genuine actionable data quality nudge.
     */
    protected function registrar(): array
    {
        $totalStudents = Student::active()->count();
        $unassigned = Student::active()->whereNull('current_class_arm_id')->count();
        $uniqueGuardians = StudentGuardian::get(['email', 'phone', 'name'])
            ->map(fn ($g) => $g->email ?: ($g->phone ?: $g->name))
            ->unique()
            ->count();

        return [
            'kind' => 'registrar',
            'figs' => [
                ['n' => number_format($totalStudents), 'l' => 'Students', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '72%'],
                ['n' => number_format($uniqueGuardians), 'l' => 'Parents/Guardians', 'ic' => 'students', 'c' => '#6D4FE0', 'pct' => '60%'],
                ['n' => number_format($unassigned), 'l' => 'Awaiting Class', 'ic' => 'students', 'c' => '#E5901C', 'pct' => '40%'],
                ['n' => number_format(SchoolClass::active()->count()), 'l' => 'Active Classes', 'ic' => 'academics', 'c' => '#E14F63', 'pct' => '80%'],
            ],
            'panel' => [
                'type' => 'registrar_overview',
                'unassigned' => $unassigned,
            ],
        ];
    }

    /**
     * The ICT Officer role (DASHBOARD DESIGN doc: "Active Users, Failed
     * Logins, Storage, Backups" / "System Health, Database Status, Queue
     * Status, Security Alerts"). Failed login tracking, storage metering,
     * and queue monitoring do not exist in the product yet, so this
     * surfaces the two things that are real and already built this
     * platform: how many accounts the school actually has, and its
     * backup history via the existing Backup model.
     */
    protected function ictOfficer(School $school): array
    {
        $activeUsers = $school->users()->wherePivot('status', 'active')->count();
        $backups = \App\Models\Backup::where('school_id', $school->id)->orderByDesc('created_at')->get();
        $lastBackup = $backups->first();
        // Storage card (Dashboard Design Cross-Check): the real per-school
        // breakdown already built for the Maintenance Center
        // (App\Services\Maintenance\StorageService), reused here rather
        // than a second computation, same pattern as Health Score reusing
        // HealthService on the admin dashboard.
        $storage = app(\App\Services\Maintenance\StorageService::class)->breakdown($school);
        $totalUsedBytes = ($storage['database_bytes'] ?? 0) + ($storage['backups_bytes'] ?? 0);
        // Failed Logins (Dashboard Design Cross-Check: "Active Users,
        // Failed Logins, Storage, Backups"), real now that LoginController
        // logs every failed attempt (2026-07-15). Scoped to this school
        // only (a failed attempt on the base platform /login, school_id
        // null, is a platform-level concern, not this school's ICT
        // Officer's). Passive tracking only, no lockout policy.
        $recentFailedLogins = \App\Models\FailedLogin::where('school_id', $school->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('created_at')
            ->get();
        $failedLogins24h = $recentFailedLogins->where('created_at', '>=', now()->subDay())->count();
        $uniqueIps7d = $recentFailedLogins->pluck('ip_address')->filter()->unique()->count();
        $lastFailedLogin = $recentFailedLogins->first();

        return [
            'kind' => 'ict_officer',
            'figs' => [
                ['n' => number_format($activeUsers), 'l' => 'Active Users', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '70%'],
                ['n' => number_format($backups->count()), 'l' => 'Backups', 'ic' => 'settings', 'c' => '#6D4FE0', 'pct' => '60%'],
                ['n' => $lastBackup ? $lastBackup->created_at->diffForHumans() : 'Never', 'l' => 'Last Backup', 'ic' => 'settings', 'c' => '#E5901C', 'pct' => '50%'],
                ['n' => $lastBackup ? $lastBackup->humanSize() : 'N/A', 'l' => 'Backup Size', 'ic' => 'settings', 'c' => '#E14F63', 'pct' => '40%'],
                ['n' => \App\Services\Maintenance\StorageService::humanBytes($storage['database_bytes']), 'l' => 'Database Size', 'ic' => 'settings', 'c' => '#0EA5A0', 'pct' => '60%'],
                ['n' => \App\Services\Maintenance\StorageService::humanBytes($storage['backups_bytes']), 'l' => 'Backups Storage', 'ic' => 'settings', 'c' => '#6D4FE0', 'pct' => '55%'],
                ['n' => \App\Services\Maintenance\StorageService::humanBytes($totalUsedBytes), 'l' => 'Storage Used', 'ic' => 'settings', 'c' => '#E5901C', 'pct' => '65%'],
                ['n' => $storage['limit_mb'] ? number_format($storage['limit_mb']).' MB' : 'No limit', 'l' => 'Storage Limit', 'ic' => 'settings', 'c' => '#E14F63', 'pct' => '50%'],
                ['n' => number_format($recentFailedLogins->count()), 'l' => 'Failed Logins (7d)', 'ic' => 'settings', 'c' => '#E14F63', 'pct' => '50%'],
                ['n' => number_format($failedLogins24h), 'l' => 'Failed Logins (24h)', 'ic' => 'settings', 'c' => '#E5901C', 'pct' => '50%'],
                ['n' => number_format($uniqueIps7d), 'l' => 'Unique IPs (7d)', 'ic' => 'settings', 'c' => '#6D4FE0', 'pct' => '50%'],
                ['n' => $lastFailedLogin ? $lastFailedLogin->created_at->diffForHumans() : 'None', 'l' => 'Last Failed Attempt', 'ic' => 'settings', 'c' => '#0EA5A0', 'pct' => '50%'],
            ],
            'panel' => [
                'type' => 'ict_overview',
                'has_backup' => (bool) $lastBackup,
                'recentFailedLogins' => $recentFailedLogins->take(8)->map(fn ($f) => [
                    'identifier' => $f->identifier,
                    'reason' => \App\Models\FailedLogin::reasonLabel($f->reason),
                    'ip' => $f->ip_address,
                    'when' => $f->created_at->diffForHumans(),
                ])->all(),
            ],
        ];
    }

    /**
     * Bursar/Accountant, Librarian, Nurse, Hostel Master, and Transport
     * Officer all share one shape today: their real module (Finance,
     * Library, Medical, Hostel, Transport) is not built yet, but every one
     * of them can already see students (and Bursar/Accountant can see
     * guardians too, the people they would eventually bill), so that is
     * what their dashboard shows now instead of a blank "coming soon", a
     * real, if modest, number rather than an invented one. As each module
     * gets built, its own controller should extend this method's figures
     * directly (user direction 2026-07-12: "as we build more data, the
     * ones left will show on the necessary user dashboards").
     */
    protected function peopleOnly(string $moduleKey, bool $withGuardians): array
    {
        $moduleLabels = [
            'finance' => 'Finance', 'library' => 'Library', 'medical' => 'Medical / Clinic',
            'hostel' => 'Hostel', 'transport' => 'Transport',
        ];

        $figs = [
            ['n' => number_format(Student::active()->count()), 'l' => 'Students', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '72%'],
        ];

        if ($withGuardians) {
            $uniqueGuardians = StudentGuardian::get(['email', 'phone', 'name'])
                ->map(fn ($g) => $g->email ?: ($g->phone ?: $g->name))
                ->unique()
                ->count();
            $figs[] = ['n' => number_format($uniqueGuardians), 'l' => 'Parents/Guardians', 'ic' => 'students', 'c' => '#6D4FE0', 'pct' => '60%'];
        }

        return [
            'kind' => 'people_only',
            'figs' => $figs,
            'panel' => [
                'type' => 'people_only',
                'module' => $moduleLabels[$moduleKey] ?? ucfirst($moduleKey),
            ],
        ];
    }

    /**
     * Bursar/Accountant (Foundation Finance slice, 2026-07-17): real
     * figures now that Finance has something behind it, not the
     * peopleOnly() placeholder. Verified This Term is a real collected
     * total (sum of Payment rows verified within the current term's
     * dates), never a fabricated revenue number.
     */
    protected function financeOverview(): array
    {
        $pendingCount = \App\Models\Tenant\Payment::pending()->count();
        $term = Term::current()->first();
        $verifiedTotal = $term
            ? \App\Models\Tenant\Payment::where('status', \App\Models\Tenant\Payment::STATUS_VERIFIED)
                ->whereBetween('verified_at', [$term->starts_on ?? $term->created_at, $term->ends_on ?? now()])
                ->sum('amount')
            : 0;

        return [
            'kind' => 'finance_overview',
            'figs' => [
                ['n' => number_format($pendingCount), 'l' => 'Pending Payments', 'ic' => 'billing', 'c' => '#E5901C'],
                ['n' => '₦'.number_format((float) $verifiedTotal, 2), 'l' => 'Verified This Term', 'ic' => 'billing', 'c' => '#12A97B'],
            ],
            'panel' => [
                'type' => 'finance_overview',
                'pendingCount' => $pendingCount,
            ],
        ];
    }

    protected function comingSoon(string $slug): array
    {
        $labels = [
            'store-keeper' => ['role' => 'Store Keeper', 'module' => 'Inventory'],
            'security' => ['role' => 'Security', 'module' => 'Visitor Management'],
        ];

        return [
            'kind' => 'coming_soon',
            'figs' => [],
            'panel' => [
                'type' => 'coming_soon',
                'role' => $labels[$slug]['role'] ?? ucfirst($slug),
                'module' => $labels[$slug]['module'] ?? 'This',
            ],
        ];
    }

    protected function parent(User $user): array
    {
        $children = StudentGuardian::where('guardian_user_id', $user->id)->with('student.currentClassArm')->get();
        $upcomingEvents = \App\Models\Tenant\CalendarEvent::upcoming()->count();
        $childIds = $children->pluck('student.id')->filter()->values();
        $childArmIds = $children->pluck('student.currentClassArm.id')->filter()->unique()->values();
        $upcomingExams = \App\Models\Tenant\CalendarEvent::exams()->upcoming()
            ->where(fn ($q) => $q->whereNull('class_arm_id')->orWhereIn('class_arm_id', $childArmIds))
            ->with('subject', 'classArm')->take(5)->get();

        $todayCounts = Attendance::students()->whereIn('person_id', $childIds)->forDate(now())
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $marked = $todayCounts->sum();
        $attendanceRate = $marked > 0 ? round((($todayCounts['present'] ?? 0) / $marked) * 100, 1) : null;

        $term = Term::current()->first();
        $reportsReleased = $term
            ? ReportCardReview::whereIn('student_id', $childIds)->where('term_id', $term->id)
                ->where('status', ReportCardReview::STATUS_RELEASED)->count()
            : 0;

        return [
            'kind' => 'parent',
            'figs' => [
                ['n' => number_format($children->count()), 'l' => 'My Children', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '60%'],
                // Fees and Messages are real figures once the Finance and
                // Messaging modules exist (deferred, see the tracker), a
                // literal 0 here would misleadingly read as "nothing due" /
                // "no messages" rather than "not available yet" (standing
                // "no dummy data" rule), so these two tiles are left out
                // entirely for now rather than shown with a fabricated
                // value.
                ['n' => number_format($upcomingEvents), 'l' => 'Upcoming Events', 'ic' => 'calendar', 'c' => '#E14F63', 'pct' => $upcomingEvents > 0 ? '70%' : '10%'],
                ['n' => $attendanceRate !== null ? "{$attendanceRate}%" : 'N/A', 'l' => 'Attendance Today', 'ic' => 'students', 'c' => '#12A97B', 'pct' => $attendanceRate !== null ? "{$attendanceRate}%" : '0%'],
                ['n' => number_format($reportsReleased), 'l' => 'Report Cards Released', 'ic' => 'results', 'c' => '#6D4FE0', 'pct' => $reportsReleased > 0 ? '70%' : '10%'],
            ],
            'panel' => [
                'type' => 'children',
                'children' => $children->map(function ($g) {
                    $latestReport = $g->student
                        ? ReportCardReview::where('student_id', $g->student->id)
                            ->where('status', ReportCardReview::STATUS_RELEASED)
                            ->with('term')
                            ->orderByDesc('released_at')
                            ->first()
                        : null;

                    return [
                        'id' => $g->student?->id,
                        'name' => $g->student?->fullName(),
                        'class' => $g->student?->currentClassArm?->label(),
                        'latestReportTerm' => $latestReport?->term?->name,
                        'latestReportReleasedAt' => $latestReport?->released_at,
                    ];
                })->all(),
                'upcomingExams' => $upcomingExams->map(fn ($e) => [
                    'title' => $e->title,
                    'date' => $e->starts_on?->format('d M Y'),
                    'subject' => $e->subject?->name,
                    'class' => $e->classArm?->label(),
                ])->all(),
                'upcomingAssignments' => \App\Models\Tenant\Assignment::whereIn('class_arm_id', $childArmIds)
                    ->upcoming()->with('subject', 'classArm')->take(5)->get()
                    ->map(fn ($a) => ['title' => $a->title, 'date' => $a->due_on?->format('d M Y'), 'subject' => $a->subject?->name, 'class' => $a->classArm?->label()])
                    ->all(),
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
        $subjectsCount = $classArm?->offeredSubjects()->count() ?? 0;

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

        $termMarks = ($term && $term->starts_on)
            ? Attendance::where('person_id', $student->id)->where('person_type', Attendance::PERSON_STUDENT)
                ->whereBetween('date', [$term->starts_on->toDateString(), min($term->ends_on ?? now(), now())->toDateString()])
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            : collect();
        $termMarked = $termMarks->sum();
        $termRate = $termMarked > 0 ? round((($termMarks['present'] ?? 0) / $termMarked) * 100, 1) : null;
        $todayMark = Attendance::where('person_id', $student->id)->where('person_type', Attendance::PERSON_STUDENT)
            ->forDate(now())->first();

        return [
            'kind' => 'student',
            'figs' => [
                ['n' => $classArm?->label() ?? 'N/A', 'l' => 'My Class', 'ic' => 'academics', 'c' => '#0EA5A0', 'pct' => '60%'],
                ['n' => number_format($subjectsCount), 'l' => 'Subjects', 'ic' => 'academics', 'c' => '#6D4FE0', 'pct' => '75%'],
                ['n' => $average !== null ? "{$average}%" : 'N/A', 'l' => 'My Average', 'ic' => 'results', 'c' => '#E5901C', 'pct' => '70%'],
                ['n' => $position ?? 'N/A', 'l' => 'Position', 'ic' => 'results', 'c' => '#E14F63', 'pct' => '65%'],
                ['n' => $todayMark ? ucfirst($todayMark->status) : 'Not marked', 'l' => 'Today', 'ic' => 'students', 'c' => '#12A97B', 'pct' => $todayMark?->status === 'present' ? '100%' : '30%'],
                ['n' => $termRate !== null ? "{$termRate}%" : 'N/A', 'l' => 'My Attendance', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => $termRate !== null ? "{$termRate}%" : '0%'],
                ['n' => number_format($termMarks['present'] ?? 0), 'l' => 'Days Present', 'ic' => 'students', 'c' => '#6D4FE0', 'pct' => '60%'],
                ['n' => number_format($termMarks['absent'] ?? 0), 'l' => 'Days Absent', 'ic' => 'students', 'c' => '#E14F63', 'pct' => '30%'],
            ],
            'panel' => [
                'type' => 'own_result',
                'term' => $term?->name,
                'student_id' => $student->id,
                'upcomingExams' => $classArm
                    ? \App\Models\Tenant\CalendarEvent::exams()->upcoming()
                        ->where(fn ($q) => $q->whereNull('class_arm_id')->orWhere('class_arm_id', $classArm->id))
                        ->with('subject')->take(5)
                        ->get()
                        ->map(fn ($e) => ['title' => $e->title, 'date' => $e->starts_on?->format('d M Y'), 'subject' => $e->subject?->name])
                        ->all()
                    : [],
                'upcomingAssignments' => $classArm
                    ? \App\Models\Tenant\Assignment::where('class_arm_id', $classArm->id)
                        ->upcoming()->with('subject')->take(5)->get()
                        ->map(fn ($a) => ['title' => $a->title, 'date' => $a->due_on?->format('d M Y'), 'subject' => $a->subject?->name])
                        ->all()
                    : [],
            ],
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
