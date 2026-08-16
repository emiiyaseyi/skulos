<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Assignment;
use App\Models\Tenant\AssignmentSubmission;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Models\Tenant\Term;
use App\Services\Rbac\FamilyScope;
use App\Services\Rbac\Rbac;
use App\Services\Rbac\TeacherScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Assignments (Dashboard Design Cross-Check, scope decision 2026-07-15:
 * "Skip Behaviour entirely for now, build Assignments only"). A teacher
 * posts homework for one subject/class arm; students and parents see it
 * on their own dashboards. Same class-arm/family scoping pattern already
 * established by AttendanceController/StudentController, not
 * re-invented here.
 */
class AssignmentController extends Controller
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

    /**
     * Every class arm this user is the class teacher or assistant class
     * teacher of: they see and post assignments for every subject there,
     * not just ones they hold a TeachingAssignment for (user directive
     * 2026-07-16, restated 2026-07-18: "a class teacher can see all the
     * details of the assignment for her class"). Keyed off the real
     * ClassArm link, not an RBAC role slug, so this is correct whether the
     * user's role happens to be named "Class Teacher", "Teacher", or
     * anything else.
     */
    protected function classTeacherArmIds(School $school): \Illuminate\Support\Collection
    {
        $staff = Staff::where('user_id', Auth::id())->first();

        return $staff
            ? ClassArm::where('class_teacher_staff_id', $staff->id)->orWhere('assistant_teacher_staff_id', $staff->id)->pluck('id')
            : collect();
    }

    /**
     * A scoped teacher's (class_arm_id, subject_id) pairs from their real
     * TeachingAssignment rows, excluding any arm they are already the
     * class/assistant teacher of (classTeacherArmIds() above already
     * covers those unrestricted), or null if this user is not scoped at
     * all (an unrestricted admin-type role, or a scoped role explicitly
     * lifted via Staff Extra Access).
     *
     * Deliberately keyed off TeacherScope::isScoped() rather than a
     * specific RBAC role slug (user report 2026-07-18: a plain "Teacher"
     * role account was seeing every subject in the school under
     * Assignments, since only the "Subject Teacher" role slug specifically
     * was ever narrowed here) — the real TeachingAssignment/
     * class_teacher_staff_id data is authoritative, not which named role a
     * school happened to assign the account.
     */
    protected function subjectTeacherPairs(School $school): ?\Illuminate\Support\Collection
    {
        $user = Auth::user();
        if (! app(TeacherScope::class)->isScoped($user, $school)) {
            return null;
        }
        $staff = Staff::where('user_id', $user->id)->first();
        if (! $staff) {
            return collect();
        }
        $classTeacherArmIds = $this->classTeacherArmIds($school);

        return TeachingAssignment::where('staff_id', $staff->id)
            ->when($classTeacherArmIds->isNotEmpty(), fn ($q) => $q->whereNotIn('class_arm_id', $classTeacherArmIds))
            ->get(['class_arm_id', 'subject_id']);
    }

    /**
     * A class teacher sees every subject's assignments across their whole
     * class; a subject teacher (whatever their role is actually called)
     * only sees their own subject's, not every subject taught in that
     * class arm by someone else (user directive 2026-07-16: "CLASS TEACHER
     * CAN SEE ALL THE ASSIGNMENT FOR THE CLASS, ACROSS ALL SUBJECTS WHILE
     * OTHER USERS CAN ONLY SEE THE ONW FOR THEM").
     */
    protected function applySubjectScope(\Illuminate\Database\Eloquent\Builder $query, School $school): void
    {
        $pairs = $this->subjectTeacherPairs($school);
        if ($pairs === null) {
            return;
        }
        $classTeacherArmIds = $this->classTeacherArmIds($school);
        if ($pairs->isEmpty() && $classTeacherArmIds->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where(function ($q) use ($pairs, $classTeacherArmIds) {
            if ($classTeacherArmIds->isNotEmpty()) {
                $q->orWhereIn('class_arm_id', $classTeacherArmIds);
            }
            foreach ($pairs as $pair) {
                $q->orWhere(fn ($q2) => $q2->where('class_arm_id', $pair->class_arm_id)->where('subject_id', $pair->subject_id));
            }
        });
    }

    /**
     * The same class-arm/subject/family scoping index() has always
     * applied, extracted so show() (a single assignment, reached
     * directly by id) can reuse it exactly rather than duplicating the
     * rules: filtering by id afterward naturally 404s an out-of-scope
     * assignment instead of needing a second, separately-maintained check.
     */
    protected function scopedAssignmentsQuery(School $school, TeacherScope $teacherScope, FamilyScope $familyScope): \Illuminate\Database\Eloquent\Builder
    {
        $user = Auth::user();
        $query = Assignment::with('subject', 'classArm.schoolClass.section', 'creator')->orderByDesc('due_on');

        $armIds = $teacherScope->classArmIds($user, $school);
        if ($armIds !== null) {
            $query->whereIn('class_arm_id', $armIds);
            $this->applySubjectScope($query, $school);
        }

        $studentIds = $familyScope->studentIds($user, $school);
        if ($studentIds !== null) {
            $familyArmIds = Student::whereIn('id', $studentIds)->pluck('current_class_arm_id')->filter()->unique();
            $query->whereIn('class_arm_id', $familyArmIds);
        }

        return $query;
    }

    public function index(Request $request, TeacherScope $teacherScope, FamilyScope $familyScope): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'assignments.view');
        $school = $this->school($request);
        $user = Auth::user();
        $unrestricted = ! app(TeacherScope::class)->isScoped($user, $school) && $familyScope->studentIds($user, $school) === null;

        $query = $this->scopedAssignmentsQuery($school, $teacherScope, $familyScope);

        // Browsing by section/class is only meaningful for an
        // unrestricted (admin-type) user, whose own scope above did not
        // already narrow anything; a restricted role's own class arm(s)
        // are already the whole of what they can see (user directive
        // 2026-07-16: "ADMIN CAN SEE ALL ACTIVE ASSIGNMENTS BY SECTION,
        // CLASS, THE REPORTS ETC").
        $sectionId = $unrestricted ? $request->integer('section_id') ?: null : null;
        $classArmId = $unrestricted ? $request->integer('class_arm_id') ?: null : null;
        if ($sectionId) {
            $query->whereHas('classArm.schoolClass', fn ($q) => $q->where('section_id', $sectionId));
        }
        if ($classArmId) {
            $query->where('class_arm_id', $classArmId);
        }

        // Due date range filter (user report 2026-07-22: "Assignment
        // pages should have the option of date filters too"), available
        // to everyone regardless of scope, unlike section/class above
        // which only mean anything for the unrestricted whole-school view.
        $dueFrom = $request->query('due_from') ?: null;
        $dueTo = $request->query('due_to') ?: null;
        if ($dueFrom) {
            $query->whereDate('due_on', '>=', $dueFrom);
        }
        if ($dueTo) {
            $query->whereDate('due_on', '<=', $dueTo);
        }

        // reorder() strips the base query's "order by due_on desc"
        // before the aggregates are applied. Without it MySQL rejects
        // the whole statement (error 1140: mixing aggregate columns
        // with a non-aggregate one and no GROUP BY), because ordering
        // by due_on references a plain column alongside count()/sum().
        // SQLite permits it, so this only ever failed in production and
        // showed as a 500 on the Assignments page -- found live
        // 2026-08-16. An ordering is meaningless on a single row of
        // totals anyway.
        $summary = $unrestricted ? (clone $query)->reorder()
            ->selectRaw("count(*) as total, sum(case when due_on is null then 0 when due_on < ? then 1 else 0 end) as overdue, sum(case when due_on >= ? then 1 else 0 end) as upcoming", [now()->toDateString(), now()->toDateString()])
            ->first() : null;

        return view('school.assignments.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'assignments' => $query->paginate(30)->withQueryString(),
            'canCreate' => app(Rbac::class)->check($user, $school, 'assignments.create'),
            'unrestricted' => $unrestricted,
            'sections' => $unrestricted ? Section::with(['classes.arms'])->orderBy('sequence')->get() : collect(),
            'selectedSectionId' => $sectionId,
            'selectedClassArmId' => $classArmId,
            'dueFrom' => $dueFrom,
            'dueTo' => $dueTo,
            'summary' => $summary,
        ]);
    }

    public function create(Request $request, TeacherScope $teacherScope): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'assignments.create');
        $school = $this->school($request);
        $user = Auth::user();

        $armIds = $teacherScope->classArmIds($user, $school);
        $classArms = ClassArm::with('schoolClass.section', 'arm')
            ->when($armIds !== null, fn ($q) => $q->whereIn('id', $armIds))
            ->get()
            ->sortBy(fn (ClassArm $arm) => $arm->orderKey())
            ->values();

        // Narrowed to the subjects a subject teacher actually teaches
        // (somewhere), so the form does not offer a combination store()
        // would then reject; a class teacher/assistant/admin still sees
        // every subject the school offers, since they are free to post
        // any subject for their own class.
        $pairs = $this->subjectTeacherPairs($school);
        $subjects = ($pairs === null || $this->classTeacherArmIds($school)->isNotEmpty())
            ? Subject::orderBy('name')->get()
            : Subject::whereIn('id', $pairs->pluck('subject_id')->unique())->orderBy('name')->get();

        return view('school.assignments.create', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'subjects' => $subjects,
            'classArms' => $classArms,
        ]);
    }

    public function store(Request $request, TeacherScope $teacherScope): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assignments.create');
        $school = $this->school($request);
        $user = Auth::user();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:4000'],
            'subject_id' => ['required', 'integer', 'exists:tenant.subjects,id'],
            'class_arm_id' => ['required', 'integer', 'exists:tenant.class_arms,id'],
            'due_on' => ['nullable', 'date'],
        ]);

        $armIds = $teacherScope->classArmIds($user, $school);
        if ($armIds !== null && ! $armIds->contains((int) $data['class_arm_id'])) {
            abort(404);
        }

        // A subject teacher may only post for a subject they actually
        // teach in that class arm, not any subject just because someone
        // else teaches it in the same class (same "respective access
        // rights" rule as viewing, see applySubjectScope()'s docblock); a
        // class/assistant class teacher may post any subject for their own
        // class arm.
        $pairs = $this->subjectTeacherPairs($school);
        if ($pairs !== null
            && ! $this->classTeacherArmIds($school)->contains((int) $data['class_arm_id'])
            && ! $pairs->contains(fn ($p) => $p->class_arm_id === (int) $data['class_arm_id'] && $p->subject_id === (int) $data['subject_id'])) {
            abort(403, 'You do not teach this subject in this class.');
        }

        $staff = Staff::where('user_id', $user->id)->first();
        $term = Term::current()->first();

        Assignment::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'subject_id' => $data['subject_id'],
            'class_arm_id' => $data['class_arm_id'],
            'term_id' => $term?->id,
            'due_on' => $data['due_on'] ?? null,
            'created_by_staff_id' => $staff?->id,
        ]);

        return redirect()->route('school.assignments.index', ['school' => $school->slug])
            ->with('status', 'Assignment posted.');
    }

    /**
     * $assignmentId is read via $request->route() rather than a typed
     * method parameter: this route sits under a domain wildcard
     * ({school}.<domain>), and Laravel resolves untyped/scalar controller
     * parameters positionally against the full route parameter list
     * (domain parameter included), not by name, so a typed parameter here
     * would silently receive the school slug instead (the recurring
     * gotcha documented in CLAUDE.md; this was the live bug instance of
     * it, found by audit 2026-07-17: delete always 404'd since
     * findOrFail() was looking up the slug string as an id).
     */
    public function destroy(Request $request, TeacherScope $teacherScope): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assignments.delete');
        $school = $this->school($request);
        $user = Auth::user();

        $assignment = Assignment::findOrFail($request->route('assignmentId'));

        $armIds = $teacherScope->classArmIds($user, $school);
        if ($armIds !== null && ! $armIds->contains($assignment->class_arm_id)) {
            abort(404);
        }

        $assignment->delete();

        return redirect()->route('school.assignments.index', ['school' => $school->slug])
            ->with('status', 'Assignment removed.');
    }

    /** Bulk version of destroy(), same teacher class-arm scoping applied per row. */
    public function bulkDestroy(Request $request, TeacherScope $teacherScope): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assignments.delete');
        $school = $this->school($request);
        $user = Auth::user();

        $data = $request->validate([
            'assignment_ids' => ['required', 'array', 'min:1'],
            'assignment_ids.*' => ['exists:assignments,id'],
        ]);

        $armIds = $teacherScope->classArmIds($user, $school);
        $assignments = Assignment::whereIn('id', $data['assignment_ids'])
            ->when($armIds !== null, fn ($q) => $q->whereIn('class_arm_id', $armIds))
            ->get();

        foreach ($assignments as $assignment) {
            $assignment->delete();
        }

        return redirect()->route('school.assignments.index', ['school' => $school->slug])
            ->with('status', "{$assignments->count()} assignment(s) removed.");
    }

    /**
     * Assignment detail (user report 2026-07-18: "a class teacher can see
     * all the details of the assignment for her class, submission, who
     * has and not... if she is assigned a subject, she can create and
     * check submission details"). Reuses scopedAssignmentsQuery() so an
     * out-of-scope assignment 404s exactly the same way the list already
     * hides it, rather than a second hand-maintained check. A teacher
     * (any scope) sees the whole class roster with each student's
     * submission status; a student/parent sees only their own child's
     * submission and a form to add or update it.
     */
    public function show(Request $request, TeacherScope $teacherScope, FamilyScope $familyScope): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'assignments.view');
        $school = $this->school($request);
        $user = Auth::user();

        $assignment = $this->scopedAssignmentsQuery($school, $teacherScope, $familyScope)
            ->findOrFail($request->route('assignmentId'));

        $familyStudentIds = $familyScope->studentIds($user, $school);
        if ($familyStudentIds !== null) {
            // A parent/student: only their own child's submission, never
            // the whole class roster.
            $ownStudentId = Student::whereIn('id', $familyStudentIds)
                ->where('current_class_arm_id', $assignment->class_arm_id)->value('id');
            $submission = $ownStudentId
                ? AssignmentSubmission::where('assignment_id', $assignment->id)->where('student_id', $ownStudentId)->first()
                : null;

            return view('school.assignments.show', [
                'school' => $school,
                'accent' => $school->branding()['color'],
                'assignment' => $assignment,
                'isFamily' => true,
                'ownStudentId' => $ownStudentId,
                'submission' => $submission,
                'canSubmit' => (bool) $ownStudentId,
            ]);
        }

        $students = Student::where('current_class_arm_id', $assignment->class_arm_id)->active()->orderBy('last_name')->get();
        $submissions = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->whereIn('student_id', $students->pluck('id'))->get()->keyBy('student_id');

        return view('school.assignments.show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'assignment' => $assignment,
            'isFamily' => false,
            'students' => $students,
            'submissions' => $submissions,
            'submittedCount' => $submissions->count(),
        ]);
    }

    /**
     * A student submits (or updates) their own work for an assignment;
     * one row per student per assignment (the migration's own unique
     * constraint), updateOrCreate keeps a resubmission as an edit, not a
     * duplicate. A parent may not submit on their child's behalf, this is
     * the student's own account only, matching the real-world "the
     * learner does the homework" shape of the feature.
     */
    public function submit(Request $request, TeacherScope $teacherScope, FamilyScope $familyScope): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assignments.view');
        $school = $this->school($request);
        $user = Auth::user();

        $assignment = $this->scopedAssignmentsQuery($school, $teacherScope, $familyScope)
            ->findOrFail($request->route('assignmentId'));

        $role = $user->roleIn($school);
        abort_unless($role && $role->slug === 'student', 403, 'Only the student may submit their own work.');

        $student = Student::where('user_id', $user->id)->where('current_class_arm_id', $assignment->class_arm_id)->first();
        abort_unless($student, 404);

        $data = $request->validate([
            'content' => ['nullable', 'string', 'max:8000'],
            'file' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx'],
        ]);
        abort_if(empty($data['content']) && ! $request->hasFile('file'), 422, 'Add a written response or attach a file.');

        $filePath = $request->hasFile('file')
            ? $request->file('file')->store("assignment-submissions/{$school->slug}", 'local')
            : null;

        $existing = AssignmentSubmission::where('assignment_id', $assignment->id)->where('student_id', $student->id)->first();
        if ($filePath && $existing?->file_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($existing->file_path);
        }

        AssignmentSubmission::updateOrCreate(
            ['assignment_id' => $assignment->id, 'student_id' => $student->id],
            [
                'content' => $data['content'] ?? $existing?->content,
                'file_path' => $filePath ?? $existing?->file_path,
                'submitted_at' => now(),
            ]
        );

        return redirect()->route('school.assignments.show', ['school' => $school->slug, 'assignmentId' => $assignment->id])
            ->with('status', 'Submitted.');
    }

    /** Streams a submission's attached file from the private disk, scoped the same way the assignment itself is. */
    public function submissionFile(Request $request, TeacherScope $teacherScope, FamilyScope $familyScope): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeAction($request, 'assignments.view');
        $school = $this->school($request);
        $user = Auth::user();

        $assignment = $this->scopedAssignmentsQuery($school, $teacherScope, $familyScope)
            ->findOrFail($request->route('assignmentId'));
        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->findOrFail($request->route('submissionId'));

        $familyStudentIds = $familyScope->studentIds($user, $school);
        if ($familyStudentIds !== null && ! $familyStudentIds->contains($submission->student_id)) {
            abort(404);
        }
        abort_unless($submission->file_path, 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($submission->file_path);
    }

    /**
     * A parent's own per-child view (user directive 2026-08-05: "let's
     * move assignment to under learning management. Parent should also
     * see each child card showing assignments etc same as what we
     * mentioned under assessment"), one card per child with total/
     * submitted/left counts — the flat, all-children-merged index()
     * above stays exactly as it is for every other role, this is an
     * additional, parent-only entry point, same shape as
     * CbtParentAssessmentController.
     */
    public function parentIndex(Request $request, FamilyScope $familyScope): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'assignments.view');
        $school = $this->school($request);
        $children = $this->children($request, $familyScope);

        $cards = $children->map(function (Student $child) {
            $assignments = $child->current_class_arm_id
                ? Assignment::where('class_arm_id', $child->current_class_arm_id)->pluck('id')
                : collect();
            $submittedCount = $assignments->isEmpty() ? 0
                : AssignmentSubmission::where('student_id', $child->id)->whereIn('assignment_id', $assignments)->count();

            return [
                'student' => $child,
                'total' => $assignments->count(),
                'submitted' => $submittedCount,
                'left' => max(0, $assignments->count() - $submittedCount),
            ];
        });

        return view('school.assignments.parent-index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'cards' => $cards,
        ]);
    }

    public function parentChild(Request $request, FamilyScope $familyScope): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'assignments.view');
        $school = $this->school($request);
        $children = $this->children($request, $familyScope);
        $child = $children->firstWhere('id', (int) $request->route('studentId'));
        abort_if(! $child, 404);

        $assignments = $child->current_class_arm_id
            ? Assignment::with('subject', 'creator')->where('class_arm_id', $child->current_class_arm_id)->orderByDesc('due_on')->get()
            : collect();
        $submissions = AssignmentSubmission::where('student_id', $child->id)
            ->whereIn('assignment_id', $assignments->pluck('id'))->get()->keyBy('assignment_id');

        $rows = $assignments->map(fn (Assignment $assignment) => [
            'assignment' => $assignment,
            'submission' => $submissions->get($assignment->id),
        ]);

        return view('school.assignments.parent-child', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'student' => $child,
            'rows' => $rows,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, Student> */
    protected function children(Request $request, FamilyScope $familyScope): \Illuminate\Support\Collection
    {
        $school = $this->school($request);
        $studentIds = $familyScope->studentIds(Auth::user(), $school);
        abort_if($studentIds === null, 403, 'This screen is for parents only.');

        return Student::with('currentClassArm.schoolClass')->whereIn('id', $studentIds)->orderBy('first_name')->get();
    }
}
