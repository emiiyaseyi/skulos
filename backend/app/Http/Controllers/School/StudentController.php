<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\House;
use App\Models\Tenant\Notification;
use App\Models\Tenant\ReportCardFeedback;
use App\Models\Tenant\ReportCardReview;
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
use App\Services\Media\ImageProcessingService;
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
            // Sort and filter (user report 2026-07-22: "add the sort and
            // filter option to this page"), the same ?sort=/?dir=/status
            // shape the Staff and Parent lists already established.
            $status = in_array($request->query('status'), [Student::STATUS_ACTIVE, Student::STATUS_GRADUATED, Student::STATUS_WITHDRAWN, Student::STATUS_SUSPENDED], true)
                ? $request->query('status') : null;
            $sort = in_array($request->query('sort'), ['name', 'admission_number', 'gender', 'status'], true) ? $request->query('sort') : 'name';
            $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
            $sortColumns = $sort === 'name' ? ['last_name', 'first_name'] : [$sort];

            $students = Student::query()
                ->when($scopedArmIds !== null, fn ($q) => $q->whereIn('current_class_arm_id', $scopedArmIds))
                ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                    $w->where('admission_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                }))
                ->when($status, fn ($q) => $q->where('status', $status));
            foreach ($sortColumns as $column) {
                $students->orderBy($column, $dir);
            }
            $students = $students->paginate(20)->withQueryString();

            return view('school.students.index', [
                'school' => $school,
                'accent' => $school->branding()['color'],
                'students' => $students,
                'search' => $search,
                'status' => $status,
                'sort' => $sort,
                'dir' => $dir,
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

        // Built from the column list rather than a hand-written array
        // (2026-08-16): those two lists silently drifted apart the
        // moment guardian columns were added, writing 13 values under
        // 21 headers so every field after "parent mobile" landed in the
        // wrong column. Keying off columns() means a future column
        // cannot misalign the file again.
        $students->load('guardians');
        foreach ($students as $student) {
            $guardians = $student->guardians->sortByDesc('is_primary')->values();
            $values = [
                'admission_number' => $student->admission_number,
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'other_names' => $student->other_names,
                'gender' => $student->gender,
                'date_of_birth' => $student->date_of_birth?->toDateString(),
                'email' => $student->email,
                'parent_mobile' => $student->parent_mobile,
                'parent1_name' => $guardians[0]->name ?? null,
                'parent1_email' => $guardians[0]->email ?? null,
                'parent1_phone' => $guardians[0]->phone ?? null,
                'parent1_relationship' => $guardians[0]->relationship ?? null,
                'parent2_name' => $guardians[1]->name ?? null,
                'parent2_email' => $guardians[1]->email ?? null,
                'parent2_phone' => $guardians[1]->phone ?? null,
                'parent2_relationship' => $guardians[1]->relationship ?? null,
                'class' => $student->currentClassArm?->label(),
                'house' => $student->house?->name,
                'state_of_origin' => $student->state_of_origin,
                'lga' => $student->lga,
                'address' => $student->address,
            ];

            fputcsv($out, array_map(fn ($key) => $values[$key] ?? null, array_keys($columns)));
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
            // xlsx accepted alongside csv (2026-08-16): the blank sheet
            // this app hands out is now a real workbook, because a
            // school has to PICK its class from a dropdown of its own
            // classes and plain CSV cannot carry a validation list.
            // Refusing the very file we just gave them would be absurd.
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
            'mode' => ['required', 'in:strict,partial'],
        ]);

        $upload = $request->file('file');
        $isSpreadsheet = in_array(mb_strtolower($upload->getClientOriginalExtension()), ['xlsx', 'xls'], true);

        try {
            $rows = $isSpreadsheet
                ? $this->rowsFromSpreadsheet($upload->getRealPath())
                : app(CsvReader::class)->rows(file_get_contents($upload->getRealPath()));
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', 'That file could not be read. Download a fresh sheet and fill that in.');
        }

        // Preview first, always (2026-08-16, direct instruction): show
        // the good and bad rows and let the admin decide, rather than
        // importing on upload and reporting afterwards when it is too
        // late to change their mind. The parsed rows are held in the
        // session so confirming does not need the file uploaded twice.
        $preview = (new StudentImporter)->preview($rows);
        $request->session()->put('student_import_rows', $rows);

        return view('school.students.import', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'columns' => (new StudentImporter)->columns(),
            'requiredFields' => Setting::grab('student_required_fields', []),
            'preview' => $preview,
            'result' => null,
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

        $roleSlug = optional($user->roleIn($school))->slug;

        // A quick way to jump to a sibling's own profile without going
        // back to My Children first (user request 2026-07-27: "for a
        // parent that have more than one child, let's add a child
        // switcher to the academic report page and the child profile
        // page too"). Empty for anyone not family scoped, or a parent/
        // student with only the one linked child.
        $isFamilyScoped = in_array($roleSlug, config('skulos.family_scoped_roles', []), true);
        $siblings = $isFamilyScoped
            ? Student::whereIn('id', app(FamilyScope::class)->studentIds($user, $school))
                ->with('currentClassArm.schoolClass')->orderBy('first_name')->get()
            : collect();

        // A class teacher (and their assistant) can always see guardian
        // details, a subject teacher only if the school has opted in
        // (user directive 2026-07-12), every unrestricted role always
        // could and still can, this setting only ever narrows the two
        // scoped teacher roles, never widens beyond what already applied.
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
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'students.update'),
            // A parent can ask to change their own child's details, subject
            // to class-teacher-then-admin approval (user request 2026-07-26).
            'isParent' => $roleSlug === 'parent',
            'siblings' => $siblings,
            // A direct way to this child's timetable from their profile
            // (user report 2026-07-27: "examination timetable button is
            // missing here where the parent will see the timetable for
            // the child"); My Timetable already supported a ?student_id=
            // per-child picker, it just had no entry point from here.
            'canViewTimetable' => \Illuminate\Support\Facades\Route::has('school.timetable.mine') && app(Rbac::class)->check(Auth::user(), $school, 'timetable.view'),
            // Provisioning a login is a school-administration action, not
            // a routine per-record edit (user report 2026-07-22: "class
            // teacher or subject teacher should not have option to see or
            // access this function... teachers should not be able to see
            // or click on the create login for parent too"). A class
            // teacher genuinely holds students.update (they edit their
            // own class's student details), which had been silently
            // gating this too; scoped to students.manage_settings, the
            // same admin-tier permission Student Field Settings already
            // requires, matching in the routes below too.
            'canManageLogins' => app(Rbac::class)->check(Auth::user(), $school, 'students.manage_settings'),
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

    /**
     * The Academic Report, its own standalone page (user request
     * 2026-07-26: "Academic report should be removed from the student
     * profile, it should have a stand alone submenu under student
     * portal"), not a card embedded in the student profile any more.
     * A parent/student never sees a report card that has not been
     * released yet ("parent should not be able to see report cards that
     * are yet to be release"), and can switch to any PREVIOUS term that
     * has been released ("switch to previous terms report cards that
     * has been release") — staff is unaffected, still sees the current
     * term's live, in-progress scores by default, since a teacher
     * genuinely needs to see them before release. A term counts as
     * released once it has ever reached ReportCardReview::VISIBLE_TO_
     * FAMILY (released, and still visible once locked/archived
     * afterward, those are later stages of the same released card, not
     * an un-release).
     */
    public function academicReport(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.view');
        $school = $this->school($request);
        $user = Auth::user();
        $student = Student::with('currentClassArm.schoolClass')->findOrFail($request->route('studentId'));
        app(StudentAccessGuard::class)->assertVisible($user, $school, $student);

        $roleSlug = optional($user->roleIn($school))->slug;
        $isFamilyScoped = in_array($roleSlug, config('skulos.family_scoped_roles', []), true);
        // Child switcher (user request 2026-07-27), same as show()'s own.
        $siblings = $isFamilyScoped
            ? Student::whereIn('id', app(FamilyScope::class)->studentIds($user, $school))
                ->with('currentClassArm.schoolClass')->orderBy('first_name')->get()
            : collect();
        $allTerms = Term::with('session')->get()
            ->sortByDesc(fn (Term $t) => $t->chronologicalSortKey())
            ->values();

        $currentTerm = Term::current()->first();
        if ($isFamilyScoped) {
            $releasedTermIds = ReportCardReview::where('student_id', $student->id)
                ->whereIn('status', ReportCardReview::VISIBLE_TO_FAMILY)
                ->pluck('term_id');
            $availableTerms = $allTerms->whereIn('id', $releasedTermIds)->values();
            $currentTermReleased = $currentTerm && $releasedTermIds->contains($currentTerm->id);
        } else {
            $availableTerms = $allTerms;
            $currentTermReleased = true;
        }

        $requestedTermId = $request->query('term_id');
        $term = $requestedTermId ? $availableTerms->firstWhere('id', (int) $requestedTermId) : null;
        if (! $term) {
            $term = $isFamilyScoped ? $availableTerms->first() : ($currentTerm ?: $availableTerms->first());
        }

        $report = $term ? app(ResultService::class)->studentReport($student->id, $term->id, GradingScale::default()) : [];
        $canSeeThisTermsReport = ! $isFamilyScoped || $availableTerms->contains('id', $term?->id);

        // Analytics summary shown above the scores table (user request
        // 2026-07-27: "an insightful section for parents before they see
        // the report card... highest score, lowest score, % performance
        // in current term vs previous term"). "Previous term" is only
        // ever the chronologically preceding entry in $availableTerms
        // (family scoped stays release-gated the same way the switcher
        // already is; staff can compare against any term), never a
        // fabricated figure, if there is nothing to compare against yet
        // the comparison is simply omitted rather than shown as zero.
        $analytics = null;
        if ($term && ! empty($report)) {
            $scale = GradingScale::default();
            $totals = collect($report)->pluck('total');
            $average = round($totals->avg(), 1);
            $maxScore = $totals->max();

            // "Needs attention" must mean genuinely at risk, not merely
            // whichever subject happens to be lowest (real bug found live
            // 2026-07-27: a student scoring 100 in everything still had
            // one subject arbitrarily labelled "needs attention"). Uses
            // the school's own configured at-risk score threshold
            // (Settings > At Risk Thresholds), never a hardcoded cutoff.
            $riskThreshold = (Setting::grab('at_risk_thresholds', config('skulos.at_risk_thresholds', ['attendance_pct' => 75, 'score_pct' => 40])))['score_pct'] ?? 40;

            $highestSubjects = collect($report)->filter(fn ($r) => $r['total'] == $maxScore)->keys();
            $atRiskSubjects = collect($report)->filter(fn ($r) => $r['total'] < $riskThreshold)->keys();

            $termIndex = $availableTerms->search(fn (Term $t) => $t->id === $term->id);
            $previousTerm = ($termIndex !== false && $availableTerms->has($termIndex + 1)) ? $availableTerms[$termIndex + 1] : null;
            $previousAverage = null;
            if ($previousTerm) {
                $previousReport = app(ResultService::class)->studentReport($student->id, $previousTerm->id, GradingScale::default());
                if (! empty($previousReport)) {
                    $previousAverage = round(collect($previousReport)->pluck('total')->avg(), 1);
                }
            }

            $analytics = [
                'average' => $average,
                'overallGrade' => $scale?->bandFor($totals->avg())?->grade,
                'highestSubjects' => $highestSubjects,
                'highestScore' => $maxScore,
                'atRiskSubjects' => $atRiskSubjects,
                'riskThreshold' => $riskThreshold,
                'previousTerm' => $previousTerm,
                'previousAverage' => $previousAverage,
                'diff' => $previousAverage !== null ? round($average - $previousAverage, 1) : null,
            ];
        }
        $existingFeedback = ($isFamilyScoped && $term)
            ? ReportCardFeedback::where('student_id', $student->id)->where('term_id', $term->id)
                ->where('submitted_by', $roleSlug)->latest()->first()
            : null;

        // "There should be a button to view the detailed report card
        // which is the pdf format" (user request 2026-07-26): only once
        // the term is actually visible to this viewer (staff always;
        // family scoped only when released), ReportCardController::
        // show() re-checks this same release gate itself regardless.
        $canOpenFullReportCard = $term && $canSeeThisTermsReport
            && ($isFamilyScoped || app(Rbac::class)->check($user, $school, 'report_cards.view'));

        return view('school.students.academic-report', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'student' => $student,
            'term' => $term,
            'report' => $report,
            'isFamilyScoped' => $isFamilyScoped,
            'availableTerms' => $availableTerms,
            'canSeeThisTermsReport' => $canSeeThisTermsReport,
            'currentTermReleased' => $currentTermReleased,
            'currentTerm' => $currentTerm,
            'existingFeedback' => $existingFeedback,
            'canOpenFullReportCard' => $canOpenFullReportCard,
            'showRemark' => app(\App\Services\ReportCards\ReportCardPreferences::class)->shows('subject_remark'),
            'analytics' => $analytics,
            'siblings' => $siblings,
        ]);
    }

    /**
     * "Academic Report" as its own nav entry (user request 2026-07-26)
     * needs somewhere to land without already knowing a student id: a
     * parent/student with exactly one linked child/self goes straight to
     * their own report, more than one gets a quick picker, staff (who
     * has no single "own" student) lands on the Students list instead,
     * the same place they would naturally start from anyway.
     */
    public function myAcademicReport(Request $request): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.view');
        $school = $this->school($request);
        $user = Auth::user();

        $studentIds = app(FamilyScope::class)->studentIds($user, $school);
        if ($studentIds === null) {
            return redirect()->route('school.students', ['school' => $school->slug]);
        }

        $students = Student::whereIn('id', $studentIds)->with('currentClassArm.schoolClass')->orderBy('first_name')->get();
        if ($students->count() === 1) {
            return redirect()->route('school.students.academic-report', ['school' => $school->slug, 'studentId' => $students->first()->id]);
        }

        return view('school.students.academic-report-picker', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'students' => $students,
        ]);
    }

    /**
     * A parent/student's optional comment on an already-released report
     * card, passed to the class teacher and school leadership (user
     * request 2026-07-26: "add a comment to be passed to the teacher/
     * school admin if there's a comment based on the child term report
     * but its optional"). Saved to ReportCardFeedback (built 2026-07-11,
     * ready for exactly this, never wired to anything until now) and
     * delivered as a real notification, since there is no dedicated
     * staff-side feedback inbox screen (yet) for anyone to browse to.
     */
    public function submitReportFeedback(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.view');
        $school = $this->school($request);
        $user = Auth::user();
        $student = Student::with('currentClassArm.schoolClass')->findOrFail($request->route('studentId'));
        app(StudentAccessGuard::class)->assertVisible($user, $school, $student);

        $roleSlug = optional($user->roleIn($school))->slug;
        abort_unless(in_array($roleSlug, config('skulos.family_scoped_roles', []), true), 403, 'You do not have permission for this.');

        $data = $request->validate([
            'term_id' => ['required', 'exists:tenant.terms,id'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        // Only ever for a term this family is actually allowed to see,
        // never trust the client on this, a crafted term id for a card
        // that has not been released yet is dropped, not acted on.
        $released = ReportCardReview::where('student_id', $student->id)->where('term_id', $data['term_id'])
            ->whereIn('status', ReportCardReview::VISIBLE_TO_FAMILY)->exists();
        abort_unless($released, 404);

        $message = trim((string) ($data['message'] ?? ''));
        if ($message === '') {
            return back()->with('status', 'Nothing to send, the comment was left blank.');
        }

        $term = Term::findOrFail($data['term_id']);
        ReportCardFeedback::create([
            'student_id' => $student->id,
            'term_id' => $term->id,
            'submitted_by' => $roleSlug,
            'message' => $message,
        ]);

        $this->notifyAboutReportFeedback($school, $student, $term, $message);

        return back()->with('status', 'Your comment was sent to the school.')->withFragment('report-feedback');
    }

    protected function notifyAboutReportFeedback(School $school, Student $student, Term $term, string $message): void
    {
        $userIds = collect();

        $classTeacherUserId = $student->currentClassArm?->classTeacher?->user_id;
        if ($classTeacherUserId) {
            $userIds->push($classTeacherUserId);
        }

        $leadershipRoleIds = \App\Models\Role::where('school_id', $school->id)
            ->whereIn('slug', config('skulos.leadership_role_slugs', []))
            ->pluck('id');
        $userIds = $userIds->merge(
            \Illuminate\Support\Facades\DB::table('school_user')
                ->where('school_id', $school->id)->where('status', 'active')
                ->whereIn('role_id', $leadershipRoleIds)->pluck('user_id')
        );

        foreach ($userIds->unique()->filter() as $userId) {
            Notification::create([
                'user_id' => $userId,
                'category' => 'report_card_feedback',
                'type' => Notification::typeFor('report_card_feedback'),
                'title' => "Report card comment from {$student->fullName()}'s family",
                'body' => "On {$term->name}: \"{$message}\"",
            ]);
        }
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

    /**
     * A student's photo (user request 2026-07-23/24: "the option to
     * upload student picture under each student profile should be
     * built too... remember our database recommendation here so that
     * the site wont be too heavy with lots of images"). Reuses the
     * existing, previously unused `passport_path` column, converted to
     * WebP and compressed down to the school's own configured limit
     * (Student, Parent & Staff Field Settings, default 100KB) regardless
     * of how large the original upload was. Shown on the right of the
     * report card header when present, left blank otherwise, no
     * placeholder.
     */
    public function updatePhoto(Request $request, ImageProcessingService $images): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.update');
        $school = $this->school($request);
        $student = Student::findOrFail($request->route('studentId'));
        app(StudentAccessGuard::class)->assertVisible(Auth::user(), $school, $student);

        $data = $request->validate([
            'photo' => ['required', 'image', 'max:5120'],
        ]);

        $maxKb = (int) Setting::grab('student_photo_max_kb', config('skulos.upload_limits.student_photo_kb', 100));
        $student->update([
            'passport_path' => $images->toWebp($request->file('photo'), "students/{$school->id}", maxDimension: 600, maxKb: $maxKb),
        ]);

        return redirect()->route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id])
            ->with('status', "{$student->fullName()}'s photo updated.");
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
            'email' => [$identifier === 'phone' ? 'nullable' : ($identifier === 'both' ? 'required_without:phone' : 'required'), 'email', 'max:150'],
            'phone' => [$identifier === 'email' ? 'nullable' : ($identifier === 'both' ? 'required_without:email' : 'required'), 'string', 'max:40'],
            'is_primary' => ['sometimes', 'boolean'],
        ], $customFields->validationRules('guardian')));
        $custom = Arr::pull($data, 'custom', []);

        $guardian = $student->guardians()->create($data + ['is_primary' => $request->boolean('is_primary')]);
        $customFields->saveValues('guardian', $guardian->id, $custom);
        $this->syncParentMobile($student);

        // Auto-provisioned Parent Portal login (user request 2026-07-21:
        // "Parents can login with either email or phone number"), no
        // manual "create login" step. A guardian always has a real email
        // or phone at this point (whichever parent_identifier requires),
        // so unlike the student path above this never needs a synthetic
        // placeholder identity.
        $password = Setting::grab('default_parent_password', config('skulos.default_parent_password'));
        $syntheticEmail = 'guardian-'.$guardian->id.'@parents.'.$school->slug.'.skulos.local';
        $result = $accounts->provisionAuto($school, $guardian->name, $guardian->email, $guardian->phone, 'parent', $password, $syntheticEmail);

        // A guardian sharing an email with one already provisioned for a
        // sibling reuses that same login (see provisionAuto's own doc)
        // rather than diverging into a second account, but that account
        // keeps whichever phone it was FIRST given, this form's own
        // submission is silently ignored for it. Left unsynced, the two
        // records disagree, "Guardian details" on this student shows one
        // phone, that person's own account shows another, a real bug
        // found live 2026-07-27. Syncing the freshly saved row back to
        // the account's actual identity (never the synthetic email
        // placeholder) keeps every screen agreeing on the same contact.
        if (! $result['isNew']) {
            $guardian->update([
                'phone' => $result['user']->phone ?: $guardian->phone,
                'email' => ($result['user']->email && ! str_ends_with($result['user']->email, '.skulos.local')) ? $result['user']->email : $guardian->email,
            ]);
        }
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
            'email' => [$identifier === 'phone' ? 'nullable' : ($identifier === 'both' ? 'required_without:phone' : 'required'), 'email', 'max:150'],
            'phone' => [$identifier === 'email' ? 'nullable' : ($identifier === 'both' ? 'required_without:email' : 'required'), 'string', 'max:40'],
            'is_primary' => ['sometimes', 'boolean'],
        ], $customFields->validationRules('guardian')));
        $custom = Arr::pull($data, 'custom', []);

        $guardian->update($data + ['is_primary' => $request->boolean('is_primary')]);
        $customFields->saveValues('guardian', $guardian->id, $custom);
        $this->syncParentMobile($guardian->student);

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
        $student = $guardian->student;
        $guardian->delete();
        $this->syncParentMobile($student);

        return back()->with('status', "{$name} removed as a guardian.");
    }

    /**
     * Keeps the student's own "Parent Mobile" field (a plain legacy
     * column, distinct from StudentGuardian.phone, shown on the Details
     * card) in sync with the primary guardian's phone (or, absent one, the
     * first guardian's), rather than requiring it to be typed twice.
     * User report 2026-07-22: "there's a contact phone for parents but
     * its showing not set under the child details, once parent details
     * are populated, it update the student record" — adding a guardian
     * never touched this column at all before this fix.
     */
    protected function syncParentMobile(Student $student): void
    {
        $student->refresh();
        $guardian = $student->guardians()->where('is_primary', true)->first()
            ?? $student->guardians()->orderBy('id')->first();
        $student->update(['parent_mobile' => $guardian?->phone]);
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
        $this->authorizeAction($request, 'students.manage_settings');
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
        $this->authorizeAction($request, 'students.manage_settings');
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
            'photoMaxKb' => Setting::grab('student_photo_max_kb', config('skulos.upload_limits.student_photo_kb', 100)),
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
            // Dbase recommendation standing rule (user request 2026-07-23:
            // "max size of image should be 100kb (admin can reduce or
            // increase it under settings, add it)"): the site's own real,
            // storable ceiling for a student photo after WebP conversion,
            // not the raw upload size (which is whatever the browser sends,
            // always compressed down to this regardless).
            'student_photo_max_kb' => ['nullable', 'integer', 'min:20', 'max:1000'],
        ]);

        Setting::put('student_required_fields', $data['required_fields'] ?? []);
        Setting::put('parent_identifier', $data['parent_identifier']);
        Setting::put('staff_identifier', $data['staff_identifier']);
        Setting::put('guardian_visibility', $data['guardian_visibility']);
        Setting::put('default_student_password', $data['default_student_password']);
        Setting::put('default_parent_password', $data['default_parent_password']);
        if (isset($data['student_photo_max_kb'])) {
            Setting::put('student_photo_max_kb', $data['student_photo_max_kb']);
        }

        return back()->with('status', 'Student field settings updated.');
    }

    /**
     * Reads an uploaded workbook into the same shape CsvReader produces,
     * so the importer itself needs no knowledge of the file format.
     * Header labels are mapped back to their column keys because the
     * sheet shows a person "Admission Number", not "admission_number".
     */
    protected function rowsFromSpreadsheet(string $path): array
    {
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheet(0);
        $grid = $sheet->toArray(null, true, true, false);

        if (count($grid) < 2) {
            throw new \InvalidArgumentException('That sheet has no rows to import.');
        }

        $labels = (new StudentImporter)->columns();
        $keyByLabel = [];
        foreach ($labels as $key => $label) {
            $keyByLabel[mb_strtolower($label)] = $key;
            $keyByLabel[mb_strtolower($key)] = $key;
        }

        $header = array_map(fn ($h) => $keyByLabel[mb_strtolower(trim((string) $h))] ?? trim((string) $h), array_shift($grid));

        $rows = [];
        foreach ($grid as $line) {
            $row = [];
            foreach ($header as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $value = $line[$i] ?? null;
                $row[$key] = is_string($value) ? trim($value) : $value;
            }
            // Skip the blank starter row the template ships with.
            if (collect($row)->filter(fn ($v) => filled($v))->isNotEmpty()) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Writes what the admin approved after seeing the preview. Only
     * ever imports the rows the preview marked good, so "import the
     * good ones" cannot quietly pull in a row they were shown as bad.
     */
    public function confirmImport(Request $request): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.bulk');
        $school = $this->school($request);

        $rows = $request->session()->get('student_import_rows');
        if (! is_array($rows) || $rows === []) {
            return redirect()->route('school.students.import', ['school' => $school->slug])
                ->with('error', 'That upload is no longer available. Upload the file again.');
        }

        $importer = new StudentImporter;
        $preview = $importer->preview($rows);

        $goodRowNumbers = collect($preview['good'])->pluck('row')->all();
        $onlyGood = collect($rows)
            ->filter(fn ($row, $index) => in_array($index + 2, $goodRowNumbers, true))
            ->values()->all();

        $result = $importer->import($onlyGood, 'partial');
        $request->session()->forget('student_import_rows');

        return view('school.students.import', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'columns' => $importer->columns(),
            'requiredFields' => Setting::grab('student_required_fields', []),
            'preview' => null,
            'result' => $result,
            'skipped' => count($preview['bad']),
        ]);
    }
}
