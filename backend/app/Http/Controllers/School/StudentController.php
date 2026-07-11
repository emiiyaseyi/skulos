<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\House;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Student;
use App\Models\Tenant\Term;
use App\Services\Bulk\CsvReader;
use App\Services\Bulk\Importer;
use App\Services\Bulk\Importers\StudentImporter;
use App\Services\Rbac\Rbac;
use App\Services\Results\ResultService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.view');
        $school = $this->school($request);

        $search = trim((string) $request->query('q'));
        $students = Student::query()
            ->when($search, function ($query) use ($search) {
                $query->where(function ($w) use ($search) {
                    $w->where('admission_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                });
            })
            ->orderBy('last_name')
            ->paginate(20)
            ->withQueryString();

        return view('school.students.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'students' => $students,
            'search' => $search,
            'total' => Student::count(),
            'canImport' => app(Rbac::class)->check(Auth::user(), $school, 'students.bulk'),
            'canCreate' => app(Rbac::class)->check(Auth::user(), $school, 'students.create'),
        ]);
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
    public function show(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.view');
        $school = $this->school($request);
        $student = Student::with('guardians', 'house', 'currentClassArm.schoolClass', 'currentClassArm.arm')
            ->findOrFail($request->route('studentId'));

        $term = Term::current()->first();
        $report = $term ? app(ResultService::class)->studentReport($student->id, $term->id, GradingScale::default()) : [];

        return view('school.students.show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'student' => $student,
            'term' => $term,
            'report' => $report,
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'students.update'),
            'parentIdentifier' => Setting::grab('parent_identifier', 'email'),
        ]);
    }

    public function create(Request $request): \Illuminate\View\View
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
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.create');
        $school = $this->school($request);
        $data = $request->validate($this->studentValidationRules());

        $student = Student::create($data);

        return redirect()->route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id])
            ->with('status', "{$student->fullName()} added.");
    }

    public function edit(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.update');
        $school = $this->school($request);
        $student = Student::findOrFail($request->route('studentId'));

        return view('school.students.form', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'student' => $student,
            'classArms' => ClassArm::with('schoolClass.section', 'arm')->whereHas('schoolClass', fn($q) => $q->where('is_active', true))->get()->sortBy(fn ($ca) => $ca->orderKey()),
            'houses' => House::active()->orderBy('name')->get(),
            'requiredFields' => Setting::grab('student_required_fields', []),
        ]);
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.update');
        $school = $this->school($request);
        $student = Student::findOrFail($request->route('studentId'));
        $data = $request->validate($this->studentValidationRules($student));

        $student->update($data);

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
    public function storeGuardian(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.update');
        $student = Student::findOrFail($request->route('studentId'));

        $identifier = Setting::grab('parent_identifier', 'email');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'relationship' => ['nullable', 'string', 'max:60'],
            'email' => [$identifier === 'email' ? 'required' : 'nullable', 'email', 'max:150'],
            'phone' => [$identifier === 'phone' ? 'required' : 'nullable', 'string', 'max:40'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $student->guardians()->create($data + ['is_primary' => $request->boolean('is_primary')]);

        return back()->with('status', 'Guardian added.');
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
        ]);

        Setting::put('student_required_fields', $data['required_fields'] ?? []);
        Setting::put('parent_identifier', $data['parent_identifier']);
        Setting::put('staff_identifier', $data['staff_identifier']);

        return back()->with('status', 'Student field settings updated.');
    }
}
