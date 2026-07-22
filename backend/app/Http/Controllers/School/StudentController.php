<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\House;
use App\Models\Tenant\Section;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Accounts\AccountProvisioningService;
use App\Services\Bulk\CsvReader;
use App\Services\Communication\ChatService;
use App\Services\CustomFields\CustomFieldService;
use App\Services\Bulk\Importer;
use App\Services\Bulk\Importers\StudentImporter;
use App\Services\Rbac\FamilyScope;
use App\Services\Rbac\Rbac;
use App\Services\Rbac\StudentAccessGuard;
use App\Services\Rbac\TeacherScope;
use App\Services\Results\ResultService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Students list, profile, single create/edit, and bulk CSV import for a
 * school. Tenant-scoped (the tenant middleware points the connection at
 * the active school). Every action is permission checked via RBAC.
 */
class StudentController extends Controller
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
     * Grouped by section then class when just browsing (user request
     * 2026-07-11: "all students should be categorized by their section
     * Nursery, Primary, Secondary first and then their classes"), a flat
     * search-result list when actually searching (grouping a name/
     * admission number search across every class would scatter the few
     * matches across a dozen mostly empty groups, worse than a plain
     * list). Grouping reuses the class detail page's own roster
     * (`school.academics.classes.show`) rather than duplicating a full
     * student table here, each class just shows its headcount and a link.
     */
    public function index(Request $request): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.view');
        $school = $this->school($request);
        $user = Auth::user();

        $search = trim((string) $request->query('q'));

        /*
         * A parent or student (user request 2026-07-13: "BUILD THE FULL
         * STUDENT AND PARENT PORTAL") never gets the school-wide browse
         * view either, only their own family's record(s). A student with
         * exactly one record of their own goes straight to it, a parent
         * (who may have more than one child) sees a small card per
         * child. Checked before the teacher-scoping branch below, since
         * neither role has a Staff record for TeacherScope to key off.
         */
        $familyStudentIds = app(FamilyScope::class)->studentIds($user, $school);
        if ($familyStudentIds !== null) {
            $roleSlug = optional($user->roleIn($school))->slug;
            if ($roleSlug === 'student' && $familyStudentIds->count() === 1) {
                return redirect()->route('school.students.show', ['school' => $school->slug, 'studentId' => $familyStudentIds->first()]);
            }

            return view('school.students.family', [
                'school' => $school,
                'accent' => $school->branding()['color'],
                'roleSlug' => $roleSlug,
                'familyStudents' => Student::whereIn('id', $familyStudentIds)
                    ->with('currentClassArm.schoolClass', 'currentClassArm.arm')
                    ->orderBy('first_name')
                    ->get(),
            ]);
        }

        /*
         * A class or subject teacher (user directive 2026-07-12: "should
         * only see the class assigned to him with the students taking the
         * subjects he takes in those class") never gets the school-wide
         * grouped browse view, only a flat roster of their own scoped
         * students, the same shape a search result already used. An
         * unrestricted role searching still works exactly as before.
         */
        $scopedArmIds = app(TeacherScope::class)->classArmIds($user, $school);

        /*
         * A subject teacher specifically (user directive 2026-07-12: "the
         * student page should be grouped by section first, then subject
         * before the student can be seen there") gets a drill down
         * instead of one flat roster, since they may teach several
         * subjects across several sections at once (a class teacher only
         * ever has one class, so the flat roster below already is the
         * simplest useful view for them).
         */
        if ($scopedArmIds !== null && ! $search && optional($user->roleIn($school))->slug === 'subject-teacher') {
            $staff = \App\Models\Tenant\Staff::where('user_id', $user->id)->first();
            $assignments = $staff
                ? \App\Models\Tenant\TeachingAssignment::where('staff_id', $staff->id)
                    ->with(['subject', 'classArm.schoolClass.section'])
                    ->get()
                    ->filter(fn ($a) => $a->classArm && $a->subject)
                : collect();

            $subjectGroups = $assignments
                ->groupBy(fn ($a) => $a->classArm->schoolClass?->section?->name ?? 'Other')
                ->map(fn ($bySection) => $bySection->groupBy(fn ($a) => $a->subject->name)
                    ->map(function ($bySubject) {
                        $armIds = $bySubject->pluck('class_arm_id')->unique();

                        return [
                            'classArms' => $bySubject->pluck('classArm')->unique('id')->values(),
                            'students' => Student::whereIn('current_class_arm_id', $armIds)
                                ->with('currentClassArm.schoolClass', 'currentClassArm.arm')
                                ->active()->orderBy('last_name')->get(),
                        ];
                    }));

            return view('school.students.index', [
                'school' => $school,
                'accent' => $school->branding()['color'],
                'students' => null,
                'search' => $search,
                'total' => Student::whereIn('current_class_arm_id', $scopedArmIds)->count(),
                'sections' => null,
                'subjectGroups' => $subjectGroups,
                'canImport' => app(Rbac::class)->check($user, $school, 'students.bulk'),
                'canCreate' => app(Rbac::class)->check($user, $school, 'students.create'),
                'canManage' => app(Rbac::class)->check($user, $school, 'students.update'),
            ]);
        }

        if ($search || $scopedArmIds !== null) {
            $students = Student::query()
                ->when($scopedArmIds !== null, fn ($q) => $q->whereIn('current_class_arm_id', $scopedArmIds))
                ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                    $w->where('admission_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                }))
                ->orderBy('last_name')
                ->paginate(20)
                ->withQueryString();

            return view('school.students.index', [
                'school' => $school,
                'accent' => $school->branding()['color'],
                'students' => $students,
                'search' => $search,
                'total' => $scopedArmIds !== null ? Student::whereIn('current_class_arm_id', $scopedArmIds)->count() : Student::count(),
                'sections' => null,
                'subjectGroups' => null,
                'canImport' => app(Rbac::class)->check($user, $school, 'students.bulk'),
                'canCreate' => app(Rbac::class)->check($user, $school, 'students.create'),
                'canManage' => app(Rbac::class)->check($user, $school, 'students.update'),
            ]);
        }

        $counts = Student::whereNotNull('current_class_arm_id')
            ->selectRaw('current_class_arm_id, count(*) as c')
            ->groupBy('current_class_arm_id')
            ->pluck('c', 'current_class_arm_id');
        $unassigned = Student::whereNull('current_class_arm_id')->orderBy('last_name')->get();

        $sections = Section::with(['classes' => fn ($q) => $q->orderBy('sequence'), 'classes.arms.arm'])
            ->orderBy('sequence')
            ->get();

        return view('school.students.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'students' => null,
            'search' => $search,
            'total' => Student::count(),
            'sections' => $sections,
            'subjectGroups' => null,
            'classCounts' => $counts,
            'unassigned' => $unassigned,
            'canImport' => app(Rbac::class)->check($user, $school, 'students.bulk'),
            'canCreate' => app(Rbac::class)->check($user, $school, 'students.create'),
            'canManage' => app(Rbac::class)->check($user, $school, 'students.update'),
        ]);
    }

    /** Bulk status change or soft delete for ticked students, matching the standing bulk-actions convention every list screen follows. */
    public function bulkAction(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.update');
        $school = $this->school($request);
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['exists:tenant.students,id'],
            'action' => ['required', Rule::in([Student::STATUS_ACTIVE, Student::STATUS_GRADUATED, Student::STATUS_WITHDRAWN, Student::STATUS_SUSPENDED, 'delete'])],
        ]);

        // A class teacher holds students.update (for things like updating
        // their own class's attendance-adjacent status), but that must
        // never let them bulk act on a student outside their own scope,
        // an id typed/crafted for another class is silently dropped
        // rather than acted on.
        $scopedArmIds = app(TeacherScope::class)->classArmIds(Auth::user(), $school);
        $query = Student::whereIn('id', $data['student_ids'])
            ->when($scopedArmIds !== null, fn ($q) => $q->whereIn('current_class_arm_id', $scopedArmIds));

        if ($data['action'] === 'delete') {
            $this->authorizeAction($request, 'students.delete');
            $count = $query->delete();

            return back()->with('status', "{$count} student(s) moved to Trashed. Restore them from there if this was a mistake.");
        }

        $count = $query->update(['status' => $data['action']]);

        return back()->with('status', "{$count} student(s) updated.");
    }

    /** Soft-deleted students (user request: bulk restore/undo, 2026-07-12), so a bulk delete is never a one-way door. */
    public function trashed(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.view');
        $school = $this->school($request);
        $search = trim((string) $request->query('q'));

        $students = Student::onlyTrashed()
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('admission_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            }))
            ->orderBy('deleted_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return view('school.students.trashed', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'students' => $students,
            'search' => $search,
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'students.delete'),
        ]);
    }

    /** Restore or permanently delete ticked trashed students. */
    public function trashedBulkAction(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.delete');
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer'],
            'action' => ['required', 'in:restore,force-delete'],
        ]);

        $query = Student::onlyTrashed()->whereIn('id', $data['student_ids']);

        if ($data['action'] === 'restore') {
            $count = $query->restore();

            return back()->with('status', "{$count} student(s) restored.");
        }

        $count = $query->count();
        $query->forceDelete();

        return back()->with('status', "{$count} student(s) permanently deleted. This cannot be undone.");
    }

    public function importForm(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.bulk');
        $school = $this->school($request);

        return view('school.students.import', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'columns' => (new StudentImporter)->columns(),
            'requiredFields' => Setting::grab('student_required_fields', []),
        ]);
    }

    public function template(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request, 'students.bulk');

        return response((new StudentImporter)->template(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="students-template.csv"',
        ]);
    }

    /**
     * Students CSV export (part of the "ALL THERE" Reports & Analytics
     * backlog, 2026-07-16: covers the export_to_excel/bulk_export/
     * student_reports catalog features, none of which had a real screen
     * behind them, only the import side existed). Reuses
     * `StudentImporter::columns()` for the header row, so the file this
     * produces is round-trip safe: edit it and re-upload through the
     * existing import screen. Streamed as CSV, not a generated .xlsx (no
     * spreadsheet library is a project dependency, and Excel opens a CSV
     * natively), consistent with the standing "no dummy/placeholder
     * data, no unbuilt-feature fakery" rule, a real export instead of a
     * "Soon" line. Honors the same visibility rules as the browse screen:
     * a scoped teacher role only ever exports their own class arm(s), and
     * the same optional search query narrows the export exactly like it
     * narrows the on-screen list, so "export what I'm looking at" holds.
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request, 'students.bulk');
        $user = Auth::user();
        $school = $this->school($request);
        $search = trim((string) $request->query('q'));
        $scopedArmIds = app(TeacherScope::class)->classArmIds($user, $school);

        $students = Student::query()
            ->with('currentClassArm.schoolClass', 'currentClassArm.arm', 'house')
            ->when($scopedArmIds !== null, fn ($q) => $q->whereIn('current_class_arm_id', $scopedArmIds))
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('admission_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            }))
            ->orderBy('last_name')
            ->get();

        $columns = (new StudentImporter)->columns();
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_keys($columns));
        foreach ($students as $student) {
            fputcsv($out, [
                $student->admission_number,
                $student->first_name,
                $student->last_name,
                $student->other_names,
                $student->gender,
                $student->date_of_birth?->toDateString(),
                $student->email,
                $student->parent_mobile,
                $student->currentClassArm?->label(),
                $student->house?->name,
                $student->state_of_origin,
                $student->lga,
                $student->address,
            ]);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="students-'.$school->slug.'-'.now()->toDateString().'.csv"',
        ]);
    }

    public function import(Request $request): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.bulk');
        $school = $this->school($request);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'mode' => ['required', 'in:strict,partial'],
        ]);

        $csv = file_get_contents($request->file('file')->getRealPath());

        try {
            $rows = app(CsvReader::class)->rows($csv);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $result = (new StudentImporter)->import($rows, $request->input('mode'));

        return view('school.students.import', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'columns' => (new StudentImporter)->columns(),
            'requiredFields' => Setting::grab('student_required_fields', []),
            'result' => $result,
        ]);
    }

    /**
     * A student's full profile (user request 2026-07-10: "view the
     * student details, report and every information"): every stored
     * field, their guardians, and their academic report for the current
     * term via the already-built ResultService::studentReport(), which
     * had no screen using it until now.
     */
    public function show(Request $request, CustomFieldService $customFields): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.view');
        $school = $this->school($request);
        $user = Auth::user();
        $student = Student::with('guardians.user', 'user', 'house', 'currentClassArm.schoolClass', 'currentClassArm.arm')
            ->findOrFail($request->route('studentId'));

        // A class/subject teacher opening a student outside their own
        // classes, or a parent/student opening a record outside their own
        // family, gets a 404, not a 403, per the standing "never leak that
        // a resource exists" convention.
        app(StudentAccessGuard::class)->assertVisible($user, $school, $student);

        $term = Term::current()->first();
        $report = $term ? app(ResultService::class)->studentReport($student->id, $term->id, GradingScale::default()) : [];

        // A class teacher (and their assistant) can always see guardian
        // details, a subject teacher only if the school has opted in
        // (user directive 2026-07-12), every unrestricted role always
        // could and still can, this setting only ever narrows the two
        // scoped teacher roles, never widens beyond what already applied.
        $roleSlug = optional($user->roleIn($school))->slug;
        $canSeeGuardianDetails = ! in_array($roleSlug, config('skulos.teacher_scoped_roles', []), true)
            || in_array($roleSlug, ['class-teacher', 'class-assistant'], true)
            || (Setting::grab('guardian_visibility', 'class_teacher_only') === 'both');

        // "Message" buttons on this page (messaging increment 4, 2026-07-15):
        // a teacher of this student can message its guardians, a guardian
        // of this student can message its class teacher/assistant. Neither
        // is shown to anyone else (a school owner/admin viewing this same
        // page does not get a 1-to-1 chat entry point, that is not the
        // pairing this feature covers).
        $chat = app(ChatService::class);
        $canMessageGuardians = $chat->isTeacherOf($user, $school, $student);
        $teacherTargetsForFamily = $chat->isGuardianOf($user, $school, $student)
            ? $chat->teacherTargetsFor($student, $school)
            : collect();

        return view('school.students.show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'student' => $student,
            'term' => $term,
            'report' => $report,
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'students.update'),
            'canViewReportCard' => app(Rbac::class)->check(Auth::user(), $school, 'report_cards.view'),
            'canSeeGuardianDetails' => $canSeeGuardianDetails,
            'parentIdentifier' => Setting::grab('parent_identifier', 'email'),
            'canMessageGuardians' => $canMessageGuardians,
            'teacherTargetsForFamily' => $teacherTargetsForFamily,
            'teacherParentMessagingEnabled' => $chat->teacherParentMessagingEnabled(),
            'studentCustomFields' => $customFields->forEntity('student'),
            'studentCustomValues' => $customFields->valuesFor('student', $student->id),
            'guardianCustomFields' => $customFields->forEntity('guardian'),
            'guardianCustomValues' => $student->guardians->mapWithKeys(
                fn ($guardian) => [$guardian->id => $customFields->valuesFor('guardian', $guardian->id)]
            ),
        ]);
    }

    public function create(Request $request, CustomFieldService $customFields): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.create');
        $school = $this->school($request);

        return view('school.students.form', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'student' => new Student(),
            'classArms' => ClassArm::with('schoolClass.section', 'arm')->whereHas('schoolClass', fn($q) => $q->where('is_active', true))->get()->sortBy(fn ($ca) => $ca->orderKey()),
            'houses' => House::active()->orderBy('name')->get(),
            'requiredFields' => Setting::grab('student_required_fields', []),
            'customFields' => $customFields->forEntity('student'),
            'customValues' => [],
        ]);
    }

    public function store(Request $request, CustomFieldService $customFields, AccountProvisioningService $accounts): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.create');
        $school = $this->school($request);
        $data = $request->validate(array_merge($this->studentValidationRules(), $customFields->validationRules('student')));
        $custom = Arr::pull($data, 'custom', []);

        $student = Student::create($data);
        $customFields->saveValues('student', $student->id, $custom);

        // Auto-provisioned login (user request 2026-07-21: "Why do we have
        // to manually create login for students... students can login
        // with their admission number and the default password which
        // must be change upon first login"). A student has no guaranteed
        // email, so this always uses a synthetic, never-contacted
        // placeholder purely to satisfy the platform's unique-email
        // constraint; the real sign-in path is admission number, already
        // handled by LoginController regardless of what the User's own
        // email column holds.
        $password = Setting::grab('default_student_password', config('skulos.default_student_password'));
        $syntheticEmail = Str::slug($student->admission_number, '-').'@students.'.$school->slug.'.skulos.local';
        $result = $accounts->provisionAuto($school, $student->fullName(), null, null, 'student', $password, $syntheticEmail);
        $student->update(['user_id' => $result['user']->id]);

        return redirect()->route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id])
            ->with('status', "{$student->fullName()} added. Login: admission number {$student->admission_number}, password {$password} (must be changed at first sign in).");
    }

    public function edit(Request $request, CustomFieldService $customFields): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.update');
        $school = $this->school($request);
        $student = Student::findOrFail($request->route('studentId'));
        app(StudentAccessGuard::class)->assertVisible(Auth::user(), $school, $student);

        return view('school.students.form', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'student' => $student,
            'classArms' => ClassArm::with('schoolClass.section', 'arm')->whereHas('schoolClass', fn($q) => $q->where('is_active', true))->get()->sortBy(fn ($ca) => $ca->orderKey()),
            'houses' => House::active()->orderBy('name')->get(),
            'requiredFields' => Setting::grab('student_required_fields', []),
            'customFields' => $customFields->forEntity('student'),
            'customValues' => $customFields->valuesFor('student', $student->id),
        ]);
    }

    public function update(Request $request, CustomFieldService $customFields): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.update');
        $school = $this->school($request);
        $student = Student::findOrFail($request->route('studentId'));
        app(StudentAccessGuard::class)->assertVisible(Auth::user(), $school, $student);
        $data = $request->validate(array_merge($this->studentValidationRules($student), $customFields->validationRules('student')));
        $custom = Arr::pull($data, 'custom', []);

        $student->update($data);
        $customFields->saveValues('student', $student->id, $custom);

        return redirect()->route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id])
            ->with('status', "{$student->fullName()} updated.");
    }

    /** A quick add for one guardian from the student profile page. */
    /**
     * Which of email/phone is required depends on the school's own choice
     * (tenant Setting "parent_identifier", user request 2026-07-11: "the
     * admin can determine what the unique ID is... the school must stick
     * to one only"), the other stays optional contact info.
     */
    public function storeGuardian(Request $request, CustomFieldService $customFields, AccountProvisioningService $accounts): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.update');
        $school = $this->school($request);
        $student = Student::findOrFail($request->route('studentId'));
        app(StudentAccessGuard::class)->assertVisible(Auth::user(), $school, $student);

        $identifier = Setting::grab('parent_identifier', 'email');
        $data = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:150'],
            'relationship' => ['nullable', 'string', 'max:60'],
            'email' => [$identifier === 'email' ? 'required' : 'nullable', 'email', 'max:150'],
            'phone' => [$identifier === 'phone' ? 'required' : 'nullable', 'string', 'max:40'],
            'is_primary' => ['sometimes', 'boolean'],
        ], $customFields->validationRules('guardian')));
        $custom = Arr::pull($data, 'custom', []);

        $guardian = $student->guardians()->create($data + ['is_primary' => $request->boolean('is_primary')]);
        $customFields->saveValues('guardian', $guardian->id, $custom);

        // Auto-provisioned Parent Portal login (user request 2026-07-21:
        // "Parents can login with either email or phone number"), no
        // manual "create login" step. A guardian always has a real email
        // or phone at this point (whichever parent_identifier requires),
        // so unlike the student path above this never needs a synthetic
        // placeholder identity.
        $password = Setting::grab('default_parent_password', config('skulos.default_parent_password'));
        $syntheticEmail = 'guardian-'.$guardian->id.'@parents.'.$school->slug.'.skulos.local';
        $result = $accounts->provisionAuto($school, $guardian->name, $guardian->email, $guardian->phone, 'parent', $password, $syntheticEmail);
        $guardian->update(['guardian_user_id' => $result['user']->id]);

        $loginIdentifier = $guardian->email ?: $guardian->phone;

        return back()->with('status', $result['isNew']
            ? "Guardian added. Login: {$loginIdentifier}, password {$password} (must be changed at first sign in)."
            : 'Guardian added. They already have a Parent Portal login from another child, unchanged.');
    }

    /**
     * Edit an existing guardian's own details (name/relationship/contact),
     * previously the only guardian action was add, deliberately, matching
     * what was asked for at the time ("view every information" plus
     * practical usability, not a full guardian CRUD suite). Kept as a
     * separate pass since the request is now specifically for the missing
     * edit/delete half.
     */
    public function updateGuardian(Request $request, CustomFieldService $customFields): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.update');
        $school = $this->school($request);
        $guardian = StudentGuardian::with('student')->findOrFail($request->route('guardianId'));
        app(StudentAccessGuard::class)->assertVisible(Auth::user(), $school, $guardian->student);

        $identifier = Setting::grab('parent_identifier', 'email');
        $data = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:150'],
            'relationship' => ['nullable', 'string', 'max:60'],
            'email' => [$identifier === 'email' ? 'required' : 'nullable', 'email', 'max:150'],
            'phone' => [$identifier === 'phone' ? 'required' : 'nullable', 'string', 'max:40'],
            'is_primary' => ['sometimes', 'boolean'],
        ], $customFields->validationRules('guardian')));
        $custom = Arr::pull($data, 'custom', []);

        $guardian->update($data + ['is_primary' => $request->boolean('is_primary')]);
        $customFields->saveValues('guardian', $guardian->id, $custom);

        return back()->with('status', 'Guardian updated.');
    }

    /**
     * Removes the guardian's link to this student only. Their platform
     * login (if any) is left untouched, since the same account can be
     * linked to other students (siblings) and deleting a StudentGuardian
     * row must never delete a User record as a side effect.
     */
    public function destroyGuardian(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.update');
        $school = $this->school($request);
        $guardian = StudentGuardian::with('student')->findOrFail($request->route('guardianId'));
        app(StudentAccessGuard::class)->assertVisible(Auth::user(), $school, $guardian->student);
        $name = $guardian->name;
        $guardian->delete();

        return back()->with('status', "{$name} removed as a guardian.");
    }

    /**
     * Creates a parent/guardian's login (Parent Portal, user request
     * 2026-07-12: the parent dashboard already existed once an account
     * was linked, but there was no screen to actually create one). Sign
     * in is always by email, even if this school's parent identifier is
     * set to phone, so the guardian's own email (if captured) pre-fills
     * the form but is not assumed to be present.
     */
    public function createGuardianLogin(Request $request, AccountProvisioningService $accounts): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.update');
        $school = $this->school($request);
        $guardian = StudentGuardian::with('student')->findOrFail($request->route('guardianId'));
        app(StudentAccessGuard::class)->assertVisible(Auth::user(), $school, $guardian->student);

        $data = $request->validate(['email' => ['required', 'email', 'max:150']]);

        $result = $accounts->provision($school, $guardian->name, $data['email'], 'parent');
        $guardian->update(['guardian_user_id' => $result['user']->id, 'email' => $guardian->email ?: $data['email']]);

        return back()->with('status', "{$guardian->name}'s login has been created and emailed to them.");
    }

    public function resetGuardianLoginPassword(Request $request, AccountProvisioningService $accounts): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.update');
        $school = $this->school($request);
        $guardian = StudentGuardian::with('student')->findOrFail($request->route('guardianId'));
        app(StudentAccessGuard::class)->assertVisible(Auth::user(), $school, $guardian->student);
        abort_unless($guardian->guardian_user_id, 404);

        $accounts->resetPassword($school, User::findOrFail($guardian->guardian_user_id));

        return back()->with('status', "{$guardian->name}'s password has been reset and emailed to them.");
    }

    public function toggleGuardianLoginAccess(Request $request, AccountProvisioningService $accounts): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.update');
        $school = $this->school($request);
        $guardian = StudentGuardian::with('student')->findOrFail($request->route('guardianId'));
        app(StudentAccessGuard::class)->assertVisible(Auth::user(), $school, $guardian->student);
        abort_unless($guardian->guardian_user_id, 404);

        $user = User::findOrFail($guardian->guardian_user_id);
        $current = $school->users()->whereKey($user->id)->first()->pivot->status;

        if ($current === 'active') {
            $accounts->revokeAccess($school, $user);
            $statusLabel = 'revoked';
        } else {
            $accounts->restoreAccess($school, $user);
            $statusLabel = 'restored';
        }

        return back()->with('status', "{$guardian->name}'s login access has been {$statusLabel}.");
    }

    /**
     * Creates a student's own login (Student Portal, user request
     * 2026-07-13: "BUILD THE FULL STUDENT AND PARENT PORTAL"). Mirrors
     * createGuardianLogin() exactly, on Student.user_id instead of
     * StudentGuardian.guardian_user_id — student email is optional
     * (standing rule), so an email is always collected here regardless
     * of whether the student record already has one, same as the
     * guardian flow, and backfills the student's own email if it was
     * blank.
     */
    public function createStudentLogin(Request $request, AccountProvisioningService $accounts): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.update');
        $school = $this->school($request);
        $student = Student::findOrFail($request->route('studentId'));
        app(StudentAccessGuard::class)->assertVisible(Auth::user(), $school, $student);

        $data = $request->validate(['email' => ['required', 'email', 'max:150']]);

        $result = $accounts->provision($school, $student->fullName(), $data['email'], 'student');
        $student->update(['user_id' => $result['user']->id, 'email' => $student->email ?: $data['email']]);

        return back()->with('status', "{$student->fullName()}'s login has been created and emailed to them.");
    }

    public function resetStudentLoginPassword(Request $request, AccountProvisioningService $accounts): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.update');
        $school = $this->school($request);
        $student = Student::findOrFail($request->route('studentId'));
        app(StudentAccessGuard::class)->assertVisible(Auth::user(), $school, $student);
        abort_unless($student->user_id, 404);

        $accounts->resetPassword($school, User::findOrFail($student->user_id));

        return back()->with('status', "{$student->fullName()}'s password has been reset and emailed to them.");
    }

    public function toggleStudentLoginAccess(Request $request, AccountProvisioningService $accounts): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.update');
        $school = $this->school($request);
        $student = Student::findOrFail($request->route('studentId'));
        app(StudentAccessGuard::class)->assertVisible(Auth::user(), $school, $student);
        abort_unless($student->user_id, 404);

        $user = User::findOrFail($student->user_id);
        $current = $school->users()->whereKey($user->id)->first()->pivot->status;

        if ($current === 'active') {
            $accounts->revokeAccess($school, $user);
            $statusLabel = 'revoked';
        } else {
            $accounts->restoreAccess($school, $user);
            $statusLabel = 'restored';
        }

        return back()->with('status', "{$student->fullName()}'s login access has been {$statusLabel}.");
    }

    /**
     * Same required-field rules the CSV importer enforces
     * (config('skulos.student_optional_fields') / tenant Setting
     * "student_required_fields"), so the single form and bulk import
     * never disagree about what a school requires. Class/house are real
     * foreign keys here (a <select>), not name matched text like the CSV
     * column, since a form naturally submits an id.
     */
    protected function studentValidationRules(?Student $student = null): array
    {
        $required = Setting::grab('student_required_fields', []);
        $req = fn (string $field) => in_array($field, $required, true) ? 'required' : 'nullable';

        return [
            'admission_number' => [
                'required', 'string', 'max:100',
                Rule::unique('tenant.students', 'admission_number')->ignore($student?->id),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'other_names' => [$req('other_names'), 'nullable', 'string', 'max:100'],
            'gender' => [$req('gender'), 'nullable', 'string', 'max:20'],
            'date_of_birth' => [$req('date_of_birth'), 'nullable', 'date'],
            'email' => ['nullable', 'email', 'max:150'], // optional, never required
            'parent_mobile' => [$req('parent_mobile'), 'nullable', 'string', 'max:40'],
            'current_class_arm_id' => [$req('class'), 'nullable', 'exists:tenant.class_arms,id'],
            'house_id' => [$req('house'), 'nullable', 'exists:tenant.houses,id'],
            'country' => [$req('country'), 'nullable', 'string', 'max:100'],
            'state_of_origin' => [$req('state_of_origin'), 'nullable', 'string', 'max:100'],
            'lga' => [$req('lga'), 'nullable', 'string', 'max:100'],
            'address' => [$req('address'), 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Which optional student fields this school requires (user request
     * 2026-07-10). admission_number/first/last name are always required
     * and email is never required, neither is shown here since neither is
     * actually a choice. Affects both this bulk importer and, later, the
     * single student create/edit form once it exists.
     */
    public function editSettings(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $school = $this->school($request);

        return view('school.students.settings', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'fields' => config('skulos.student_optional_fields'),
            'required' => Setting::grab('student_required_fields', []),
            'identifierOptions' => config('skulos.identifier_options'),
            'parentIdentifier' => Setting::grab('parent_identifier', 'email'),
            'staffIdentifier' => Setting::grab('staff_identifier', 'email'),
            'guardianVisibilityOptions' => config('skulos.guardian_visibility_options'),
            'guardianVisibility' => Setting::grab('guardian_visibility', 'class_teacher_only'),
            'defaultStudentPassword' => Setting::grab('default_student_password', config('skulos.default_student_password')),
            'defaultParentPassword' => Setting::grab('default_parent_password', config('skulos.default_parent_password')),
        ]);
    }

    public function updateSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $identifierKeys = implode(',', array_keys(config('skulos.identifier_options')));
        $data = $request->validate([
            'required_fields' => ['array'],
            'required_fields.*' => ['string', 'in:'.implode(',', array_keys(config('skulos.student_optional_fields')))],
            'parent_identifier' => ['required', 'string', 'in:'.$identifierKeys],
            'staff_identifier' => ['required', 'string', 'in:'.$identifierKeys],
            'guardian_visibility' => ['required', 'string', 'in:'.implode(',', array_keys(config('skulos.guardian_visibility_options')))],
            'default_student_password' => ['required', 'string', 'min:6', 'max:60'],
            'default_parent_password' => ['required', 'string', 'min:6', 'max:60'],
        ]);

        Setting::put('student_required_fields', $data['required_fields'] ?? []);
        Setting::put('parent_identifier', $data['parent_identifier']);
        Setting::put('staff_identifier', $data['staff_identifier']);
        Setting::put('guardian_visibility', $data['guardian_visibility']);
        Setting::put('default_student_password', $data['default_student_password']);
        Setting::put('default_parent_password', $data['default_parent_password']);

        return back()->with('status', 'Student field settings updated.');
    }
}
