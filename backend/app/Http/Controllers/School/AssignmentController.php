<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Assignment;
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
     * A subject teacher's (class_arm_id, subject_id) pairs from their real
     * TeachingAssignment rows, or null if this user is not specifically a
     * subject-teacher (nothing further to narrow beyond the class arm
     * scope TeacherScope already applies).
     */
    protected function subjectTeacherPairs(School $school): ?\Illuminate\Support\Collection
    {
        $user = Auth::user();
        $role = $user->roleIn($school);
        if (! $role || $role->slug !== 'subject-teacher') {
            return null;
        }
        if (app(Rbac::class)->hasModuleOverride($school, $user, 'students')) {
            return null;
        }
        $staff = Staff::where('user_id', $user->id)->first();

        return $staff
            ? TeachingAssignment::where('staff_id', $staff->id)->get(['class_arm_id', 'subject_id'])
            : collect();
    }

    /**
     * A class teacher sees every subject's assignments across their whole
     * class (TeacherScope's class-arm-wide scope already covers this
     * unchanged); a subject teacher only sees their own subject's, not
     * every subject taught in that class arm by someone else (user
     * directive 2026-07-16: "CLASS TEACHER CAN SEE ALL THE ASSIGNMENT FOR
     * THE CLASS, ACROSS ALL SUBJECTS WHILE OTHER USERS CAN ONLY SEE THE
     * ONW FOR THEM").
     */
    protected function applySubjectScope(\Illuminate\Database\Eloquent\Builder $query, School $school): void
    {
        $pairs = $this->subjectTeacherPairs($school);
        if ($pairs === null) {
            return;
        }
        if ($pairs->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where(function ($q) use ($pairs) {
            foreach ($pairs as $pair) {
                $q->orWhere(fn ($q2) => $q2->where('class_arm_id', $pair->class_arm_id)->where('subject_id', $pair->subject_id));
            }
        });
    }

    public function index(Request $request, TeacherScope $teacherScope, FamilyScope $familyScope): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'assignments.view');
        $school = $this->school($request);
        $user = Auth::user();
        $unrestricted = ! app(TeacherScope::class)->isScoped($user, $school) && $familyScope->studentIds($user, $school) === null;

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

        $summary = $unrestricted ? (clone $query)
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
        // every subject the school offers.
        $pairs = $this->subjectTeacherPairs($school);
        $subjects = $pairs === null
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
        // rights" rule as viewing, see applySubjectScope()'s docblock).
        $pairs = $this->subjectTeacherPairs($school);
        if ($pairs !== null && ! $pairs->contains(fn ($p) => $p->class_arm_id === (int) $data['class_arm_id'] && $p->subject_id === (int) $data['subject_id'])) {
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

    public function destroy(Request $request, string $assignmentId, TeacherScope $teacherScope): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assignments.delete');
        $school = $this->school($request);
        $user = Auth::user();

        $assignment = Assignment::findOrFail($assignmentId);

        $armIds = $teacherScope->classArmIds($user, $school);
        if ($armIds !== null && ! $armIds->contains($assignment->class_arm_id)) {
            abort(404);
        }

        $assignment->delete();

        return redirect()->route('school.assignments.index', ['school' => $school->slug])
            ->with('status', 'Assignment removed.');
    }
}
