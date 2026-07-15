<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Assignment;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
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

    public function index(Request $request, TeacherScope $teacherScope, FamilyScope $familyScope): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'assignments.view');
        $school = $this->school($request);
        $user = Auth::user();

        $query = Assignment::with('subject', 'classArm.schoolClass', 'creator')->orderByDesc('due_on');

        $armIds = $teacherScope->classArmIds($user, $school);
        if ($armIds !== null) {
            $query->whereIn('class_arm_id', $armIds);
        }

        $studentIds = $familyScope->studentIds($user, $school);
        if ($studentIds !== null) {
            $familyArmIds = Student::whereIn('id', $studentIds)->pluck('current_class_arm_id')->filter()->unique();
            $query->whereIn('class_arm_id', $familyArmIds);
        }

        return view('school.assignments.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'assignments' => $query->paginate(30),
            'canCreate' => app(Rbac::class)->check($user, $school, 'assignments.create'),
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

        return view('school.assignments.create', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'subjects' => Subject::orderBy('name')->get(),
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
