<?php

namespace App\Services\Dashboard;

use App\Models\School;
use App\Models\User;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\Campus;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\ReportCardReview;
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
            // School Owner and School Administrator are two different real
            // roles (user correction 2026-07-12: "there's a difference
            // between School Administrator and school super admin/admin
            // ... create the unique difference to avoid mixup") that this
            // code had been silently merging into one identical dashboard.
            // Owner/Principal/Head Teacher/Vice Principal get the business
            // wide leadership view; School Administrator gets the day to
            // day operational one below.
            in_array($slug, ['school-owner', 'principal', 'head-teacher', 'vice-principal'], true) => $this->admin($school),
            $slug === 'school-admin' => $this->schoolAdministrator(),
            $slug === 'class-teacher' => $this->classTeam($user, 'class_teacher'),
            $slug === 'class-assistant' => $this->classTeam($user, 'class_assistant'),
            $slug === 'subject-teacher' => $this->subjectTeacher($user),
            $slug === 'examination-officer' => $this->examinationOfficer(),
            $slug === 'registrar' => $this->registrar(),
            $slug === 'ict-officer' => $this->ictOfficer($school),
            $slug === 'parent' => $this->parent($user),
            $slug === 'student' => $this->student($user),
            // These roles' real modules (Finance, Library, Medical, Hostel,
            // Transport) are not built yet, on any plan, so their dashboard
            // shows whatever real data does overlap with their own
            // permission grants (never fabricated module figures) plus a
            // clear note of what is still coming (user direction
            // 2026-07-12: "update for all roles in the DASHBOARD DESIGN
            // doc... as we build more data, the ones left will show on the
            // necessary user dashboards" — i.e. this grows in place as
            // Finance/Library/Medical/Hostel/Transport get built, rather
            // than staying a permanent placeholder).
            in_array($slug, ['bursar', 'accountant'], true) => $this->peopleOnly('finance', true),
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
        $avg = $term ? round((float) \App\Models\Tenant\Score::where('term_id', $term->id)->avg('score'), 1) : null;
        $pendingReviews = $term
            ? ReportCardReview::where('term_id', $term->id)
                ->whereIn('status', [ReportCardReview::STATUS_PENDING_REVIEW, ReportCardReview::STATUS_NEEDS_CORRECTION])
                ->with('student.currentClassArm.schoolClass')
                ->get()
            : collect();
        $lastBackup = $school ? \App\Models\Backup::where('school_id', $school->id)->orderByDesc('created_at')->first() : null;
        $students = Student::active()->with('currentClassArm.schoolClass')->get();
        [$boys, $girls] = $this->genderCounts($students);
        $newThisTerm = $term ? Student::active()->where('created_at', '>=', $term->created_at)->count() : 0;
        $unassigned = Student::active()->whereNull('current_class_arm_id')->count();

        return [
            'kind' => 'admin',
            'figs' => [
                ['n' => number_format($students->count()), 'l' => 'Students', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '72%'],
                ['n' => number_format($boys), 'l' => 'Boys', 'ic' => 'students', 'c' => '#6D4FE0', 'pct' => '55%'],
                ['n' => number_format($girls), 'l' => 'Girls', 'ic' => 'students', 'c' => '#E14F63', 'pct' => '55%'],
                ['n' => number_format(Staff::active()->count()), 'l' => 'Staff', 'ic' => 'staff', 'c' => '#6D4FE0', 'pct' => '64%'],
                ['n' => number_format(SchoolClass::count()), 'l' => 'Classes', 'ic' => 'academics', 'c' => '#E5901C', 'pct' => '80%'],
                ['n' => number_format(Campus::count()), 'l' => 'Campuses', 'ic' => 'school', 'c' => '#E14F63', 'pct' => '50%'],
                ['n' => number_format(\App\Models\Tenant\House::count()), 'l' => 'Houses', 'ic' => 'school', 'c' => '#0EA5A0', 'pct' => '60%'],
                ['n' => number_format($newThisTerm), 'l' => 'New Registrations', 'ic' => 'students', 'c' => '#E5901C', 'pct' => '50%'],
                ['n' => number_format($unassigned), 'l' => 'Awaiting Class', 'ic' => 'students', 'c' => '#E14F63', 'pct' => '40%'],
                ['n' => number_format($pendingReviews->count()), 'l' => 'Reports Pending Review', 'ic' => 'results', 'c' => '#E5901C', 'pct' => '50%'],
                ['n' => $avg !== null ? "{$avg}%" : 'N/A', 'l' => 'Term Average', 'ic' => 'results', 'c' => '#6D4FE0', 'pct' => '65%'],
                ['n' => $lastBackup ? $lastBackup->created_at->diffForHumans() : 'Never', 'l' => 'Last Backup', 'ic' => 'settings', 'c' => '#E14F63', 'pct' => '45%'],
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

        return [
            'kind' => 'school_admin',
            'figs' => [
                ['n' => number_format(Student::active()->count()), 'l' => 'Students', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '72%'],
                ['n' => number_format(Staff::active()->count()), 'l' => 'Staff', 'ic' => 'staff', 'c' => '#6D4FE0', 'pct' => '64%'],
                ['n' => number_format($newThisTerm), 'l' => 'New Registrations', 'ic' => 'students', 'c' => '#E5901C', 'pct' => '50%'],
                ['n' => number_format($unassigned), 'l' => 'Awaiting Class', 'ic' => 'students', 'c' => '#E14F63', 'pct' => '40%'],
            ],
            'panel' => ['type' => 'registrar_overview', 'unassigned' => $unassigned],
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
        [$boys, $girls] = $this->genderCounts($students);
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
        $assignments = $staff ? TeachingAssignment::where('staff_id', $staff->id)->with(['subject', 'classArm.schoolClass.section'])->get() : collect();

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

        return [
            'kind' => 'examination_officer',
            'figs' => [
                ['n' => number_format($pending->count()), 'l' => 'Pending Review', 'ic' => 'results', 'c' => '#E5901C', 'pct' => '50%'],
                ['n' => number_format($released), 'l' => 'Released', 'ic' => 'results', 'c' => '#0EA5A0', 'pct' => '70%'],
                ['n' => number_format(AssessmentComponent::count()), 'l' => 'Assessment Components', 'ic' => 'academics', 'c' => '#6D4FE0', 'pct' => '60%'],
                ['n' => number_format(GradingScale::count()), 'l' => 'Grading Scales', 'ic' => 'academics', 'c' => '#E14F63', 'pct' => '60%'],
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

        return [
            'kind' => 'ict_officer',
            'figs' => [
                ['n' => number_format($activeUsers), 'l' => 'Active Users', 'ic' => 'students', 'c' => '#0EA5A0', 'pct' => '70%'],
                ['n' => number_format($backups->count()), 'l' => 'Backups', 'ic' => 'settings', 'c' => '#6D4FE0', 'pct' => '60%'],
                ['n' => $lastBackup ? $lastBackup->created_at->diffForHumans() : 'Never', 'l' => 'Last Backup', 'ic' => 'settings', 'c' => '#E5901C', 'pct' => '50%'],
                ['n' => $lastBackup ? $lastBackup->humanSize() : 'N/A', 'l' => 'Backup Size', 'ic' => 'settings', 'c' => '#E14F63', 'pct' => '40%'],
            ],
            'panel' => ['type' => 'ict_overview', 'has_backup' => (bool) $lastBackup],
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
            ],
            'panel' => [
                'type' => 'children',
                'children' => $children->map(fn ($g) => [
                    'id' => $g->student?->id,
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
            'panel' => ['type' => 'own_result', 'term' => $term?->name, 'student_id' => $student->id],
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
