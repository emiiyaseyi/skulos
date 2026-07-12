<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\House;
use App\Models\Tenant\HouseSponsor;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Services\Bulk\CsvReader;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * School houses (user request 2026-07-10): the House model existed with
 * no admin screen at all, only the Student side (house_id) and report
 * card fields referenced it. Three ways to assign a student to a house:
 * a default catalog to load in one click, a manual list with a picker
 * per student, and a CSV round trip (download every current student
 * pre-filled, fill in the house column, re-upload).
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class HouseController extends Controller
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

        return view('school.houses.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'houses' => House::withCount('students')->orderBy('name')->get(),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'students.manage_settings'),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('tenant.houses', 'name')],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        House::create($data);

        return back()->with('status', "\"{$data['name']}\" added.");
    }

    public function applyDefaults(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');

        $added = 0;
        foreach (config('skulos.default_houses') as $name) {
            $house = House::firstOrCreate(['name' => $name]);
            if ($house->wasRecentlyCreated) {
                $added++;
            }
        }

        return back()->with('status', $added > 0 ? "{$added} default house(s) added." : 'The default houses are already on your list.');
    }

    public function destroy(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $house = House::findOrFail($request->route('houseId'));
        $name = $house->name;
        $house->delete(); // Student.house_id is nullOnDelete, members are unassigned, not deleted.

        return back()->with('status', "\"{$name}\" removed. Its members are now unassigned, not deleted.");
    }

    /**
     * One house's detail page (user request 2026-07-10, extended
     * 2026-07-12 with leadership, sponsors, and staff/parent membership):
     * rename, retire or reactivate, set the House Master and Assistant
     * House Master (from the teacher/staff list), add one or more House
     * Sponsors (an existing parent, or a free typed name for a sponsor
     * who is not a parent), and see/download its members, grouped into
     * Students, Teachers, and Parents.
     */
    public function show(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.view');
        $school = $this->school($request);
        $house = House::withCount('students')->with(['housemaster', 'assistantHousemaster', 'sponsors.guardian'])
            ->findOrFail($request->route('houseId'));

        $students = Student::where('house_id', $house->id)
            ->with('currentClassArm.schoolClass', 'currentClassArm.arm')
            ->orderBy('last_name')
            ->get();

        $staffMembers = Staff::where('house_id', $house->id)->orderBy('first_name')->get();

        $parentMembers = StudentGuardian::where('house_id', $house->id)
            ->with('student')
            ->orderBy('name')
            ->get();

        return view('school.houses.show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'house' => $house,
            'students' => $students,
            'staffMembers' => $staffMembers,
            'parentMembers' => $parentMembers,
            'staffOptions' => Staff::active()->orderBy('first_name')->get(),
            'guardianOptions' => StudentGuardian::with('student')->orderBy('name')->get(),
            'houseOptions' => House::active()->orderBy('name')->get(),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'students.manage_settings'),
        ]);
    }

    /** House Master and Assistant House Master, both chosen from the teacher/staff list, both optional and independently changeable (user directive 2026-07-12). */
    public function updateLeadership(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $house = House::findOrFail($request->route('houseId'));

        $data = $request->validate([
            'housemaster_staff_id' => ['nullable', 'exists:tenant.staff,id'],
            'assistant_housemaster_staff_id' => ['nullable', 'exists:tenant.staff,id'],
        ]);

        $house->update([
            'housemaster_staff_id' => $data['housemaster_staff_id'] ?? null,
            'assistant_housemaster_staff_id' => $data['assistant_housemaster_staff_id'] ?? null,
        ]);

        return back()->with('status', "\"{$house->name}\"'s leadership updated.");
    }

    /**
     * Add a sponsor: either an existing parent (student_guardian_id) or a
     * free typed name for a sponsor who is not a parent (user directive
     * 2026-07-12), never both. A house can have more than one.
     */
    public function addSponsor(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $house = House::findOrFail($request->route('houseId'));

        $data = $request->validate([
            'student_guardian_id' => ['nullable', 'exists:tenant.student_guardians,id'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);
        $guardianId = $data['student_guardian_id'] ?? null;
        $name = $data['name'] ?? null;

        abort_if(empty($guardianId) && empty($name), 422, 'Pick a parent or type a sponsor name.');

        $sponsor = HouseSponsor::create([
            'house_id' => $house->id,
            'student_guardian_id' => $guardianId ?: null,
            'name' => empty($guardianId) ? $name : null,
        ]);

        return back()->with('status', "{$sponsor->displayName()} added as a sponsor of \"{$house->name}\".");
    }

    public function removeSponsor(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $house = House::findOrFail($request->route('houseId'));
        $sponsor = HouseSponsor::where('house_id', $house->id)->findOrFail($request->route('sponsorId'));
        $name = $sponsor->displayName();
        $sponsor->delete();

        return back()->with('status', "{$name} removed as a sponsor of \"{$house->name}\".");
    }

    /**
     * Moves a staff member into (or out of, if house_id is blank) a
     * house. Not house scoped in the route, one endpoint handles both
     * "add to this house" and "move to a different house" from the same
     * inline picker (user directive 2026-07-12: "Members... grouped into
     * ...Teachers").
     */
    public function assignStaff(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $staffMember = Staff::findOrFail($request->route('staffId'));
        $data = $request->validate(['house_id' => ['nullable', 'exists:tenant.houses,id']]);

        $staffMember->update(['house_id' => $data['house_id'] ?? null]);

        return back()->with('status', "{$staffMember->fullName()}'s house updated.");
    }

    /** Same as assignStaff(), for one parent (student_guardian) record. */
    public function assignParent(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $guardian = StudentGuardian::findOrFail($request->route('guardianId'));
        $data = $request->validate(['house_id' => ['nullable', 'exists:tenant.houses,id']]);

        $guardian->update(['house_id' => $data['house_id'] ?? null]);

        return back()->with('status', "{$guardian->name}'s house updated.");
    }

    /**
     * Distribute every student, staff member, and parent not currently in
     * a house evenly across the school's active houses (user directive
     * 2026-07-12: "Share all staff and parents into the houses under
     * each school", then "Distribute staff and parents, add student to
     * it too"), the same one-click spirit as applyDefaults() for houses
     * themselves. Already-assigned members are left untouched, so this
     * is safe to run again after new staff/students join without
     * shuffling anyone already placed.
     */
    public function distributeMembers(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');

        $houseIds = House::active()->orderBy('name')->pluck('id');
        if ($houseIds->isEmpty()) {
            return back()->with('error', 'Add at least one active house before distributing members into it.');
        }

        $studentCount = 0;
        $k = 0;
        foreach (Student::active()->whereNull('house_id')->orderBy('id')->cursor() as $student) {
            $student->update(['house_id' => $houseIds[$k % $houseIds->count()]]);
            $k++;
            $studentCount++;
        }

        $staffCount = 0;
        $i = 0;
        foreach (Staff::whereNull('house_id')->orderBy('id')->cursor() as $staffMember) {
            $staffMember->update(['house_id' => $houseIds[$i % $houseIds->count()]]);
            $i++;
            $staffCount++;
        }

        $parentCount = 0;
        $j = 0;
        foreach (StudentGuardian::whereNull('house_id')->orderBy('id')->cursor() as $guardian) {
            $guardian->update(['house_id' => $houseIds[$j % $houseIds->count()]]);
            $j++;
            $parentCount++;
        }

        return back()->with('status', "{$studentCount} student(s), {$staffCount} staff member(s), and {$parentCount} parent(s) distributed across your houses.");
    }

    /** Rename a house or change its colour. */
    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $house = House::findOrFail($request->route('houseId'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('tenant.houses', 'name')->ignore($house->id)],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $house->update($data);

        return back()->with('status', "\"{$house->name}\" updated.");
    }

    /**
     * Bulk reactivate, retire, or delete a checked set of houses at once
     * (user request 2026-07-11, after reporting the houses reactivate bug:
     * "add a bulk option to do the reactivate, delete, retire at once with
     * a check box... this is the way I want every of my builds to look
     * like"). One shared action so a mixed selection just works: retire
     * only actually changes rows that were active, reactivate only rows
     * that were retired, both report how many actually changed rather
     * than how many were ticked.
     */
    public function bulkAction(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $data = $request->validate([
            'house_ids' => ['required', 'array', 'min:1'],
            'house_ids.*' => ['exists:tenant.houses,id'],
            'action' => ['required', 'in:retire,reactivate,delete'],
        ]);

        $houses = House::whereIn('id', $data['house_ids'])->get();

        if ($data['action'] === 'delete') {
            $count = $houses->count();
            House::whereIn('id', $data['house_ids'])->delete(); // Student.house_id is nullOnDelete.

            return back()->with('status', "{$count} house(s) removed. Their members are now unassigned, not deleted.");
        }

        $targetState = $data['action'] === 'reactivate';
        $changed = 0;
        foreach ($houses as $house) {
            if ($house->is_active !== $targetState) {
                $house->update(['is_active' => $targetState]);
                $changed++;
            }
        }

        $verb = $targetState ? 'reactivated' : 'retired';

        return back()->with('status', $changed > 0 ? "{$changed} house(s) {$verb}." : 'No changes to make, the selected houses were already in that state.');
    }

    /** Retire (hide from assignment) or reactivate a house without deleting it. */
    public function toggleActive(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $house = House::findOrFail($request->route('houseId'));
        $house->update(['is_active' => ! $house->is_active]);

        return back()->with('status', $house->is_active
            ? "\"{$house->name}\" is active again and can be assigned."
            : "\"{$house->name}\" is retired. Its members keep their assignment, but it can no longer be assigned to anyone new.");
    }

    /** CSV of every student currently in this house. */
    public function downloadMembers(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request, 'students.view');
        $house = House::findOrFail($request->route('houseId'));

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['admission_number', 'first_name', 'last_name', 'class']);

        Student::where('house_id', $house->id)->with('currentClassArm.schoolClass', 'currentClassArm.arm')
            ->orderBy('last_name')->each(function (Student $student) use ($out) {
                fputcsv($out, [
                    $student->admission_number, $student->first_name, $student->last_name,
                    $student->currentClassArm?->label() ?? '',
                ]);
            });

        rewind($out);
        $csv = (string) stream_get_contents($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.\Illuminate\Support\Str::slug($house->name).'-members.csv"',
        ]);
    }

    /**
     * A "show only unassigned" filter (user request 2026-07-10) so an
     * admin can page through exactly the students who still need a
     * house, without wading through everyone who is already sorted.
     */
    public function assignForm(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $school = $this->school($request);

        $search = trim((string) $request->query('q'));
        $unassignedOnly = $request->boolean('unassigned');

        $students = Student::active()
            ->with('house', 'currentClassArm.schoolClass', 'currentClassArm.arm')
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('admission_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            }))
            ->when($unassignedOnly, fn ($q) => $q->whereNull('house_id'))
            ->orderBy('last_name')
            ->paginate(30)
            ->withQueryString();

        return view('school.houses.assign', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'students' => $students,
            'houses' => House::active()->orderBy('name')->get(),
            'search' => $search,
            'unassignedOnly' => $unassignedOnly,
        ]);
    }

    /**
     * One save for every student on the current page (user request
     * 2026-07-10: "not reload to save after every student", pick many,
     * save once), matching the same array-of-inputs-plus-one-submit
     * pattern already used by the Results entry screen. Only students
     * whose house actually changed are written, to keep the audit trail
     * meaningful rather than logging a "change" for every unchanged row.
     */
    public function assignBulk(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $school = $this->school($request);

        $data = $request->validate([
            'assignments' => ['array'],
            'assignments.*' => ['nullable', 'exists:tenant.houses,id'],
        ]);

        $studentIds = array_keys($data['assignments'] ?? []);
        $students = Student::whereIn('id', $studentIds)->get()->keyBy('id');

        $updated = 0;
        foreach ($data['assignments'] ?? [] as $studentId => $houseId) {
            $student = $students->get((int) $studentId);
            if (! $student) {
                continue;
            }
            // Laravel's ConvertEmptyStringsToNull middleware turns "" into
            // null before this ever runs, so both must be checked, not
            // just ''. (int) null casts to 0, not null, which used to
            // trip the house_id foreign key (0 matches no house row).
            $houseId = ($houseId === '' || $houseId === null) ? null : (int) $houseId;
            if ($student->house_id !== $houseId) {
                $student->update(['house_id' => $houseId]);
                $updated++;
            }
        }

        return redirect()->route('school.houses.assign-form', array_filter([
            'school' => $school->slug,
            'page' => $request->query('page'),
            'q' => $request->query('q'),
            'unassigned' => $request->boolean('unassigned') ? 1 : null,
        ]))->with('status', $updated > 0 ? "{$updated} student(s) updated." : 'No changes to save.');
    }

    /**
     * A CSV pre-filled with every active student's admission number,
     * current class, and current house (blank if unassigned), so the
     * admin only has to fill in or change the house column and
     * re-upload — not a blank template like the other importers.
     */
    public function bulkTemplate(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request, 'students.view');

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['admission_number', 'class', 'house']);

        Student::active()->with('house', 'currentClassArm.schoolClass', 'currentClassArm.arm')
            ->orderBy('last_name')->each(function (Student $student) use ($out) {
                fputcsv($out, [
                    $student->admission_number,
                    $student->currentClassArm?->label() ?? '',
                    $student->house?->name ?? '',
                ]);
            });

        rewind($out);
        $csv = (string) stream_get_contents($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="house-assignment.csv"',
        ]);
    }

    /**
     * Re-upload of the template above. Only the house column is acted
     * on; "class" is a read-only reference, not re-parsed. A blank house
     * cell leaves that student's current house untouched (this is a
     * "fill in what changed" round trip, not a full re-import), so a
     * partially filled-in sheet never wipes assignments no one meant to
     * touch.
     */
    public function bulkAssign(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $csv = file_get_contents($request->file('file')->getRealPath());

        try {
            $rows = app(CsvReader::class)->rows($csv);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $housesByName = House::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim($name)) => $id])->all();

        $updated = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $admissionNumber = trim((string) ($row['admission_number'] ?? ''));
            $houseName = trim((string) ($row['house'] ?? ''));

            if ($admissionNumber === '') {
                $errors[] = "Row {$rowNumber}: missing admission number.";

                continue;
            }
            if ($houseName === '') {
                continue; // Nothing to change for this student.
            }

            $student = Student::where('admission_number', $admissionNumber)->first();
            if (! $student) {
                $errors[] = "Row {$rowNumber}: no student with admission number \"{$admissionNumber}\".";

                continue;
            }

            $houseId = $housesByName[mb_strtolower($houseName)] ?? null;
            if (! $houseId) {
                $errors[] = "Row {$rowNumber}: house \"{$houseName}\" was not found.";

                continue;
            }

            $student->update(['house_id' => $houseId]);
            $updated++;
        }

        $status = "{$updated} student(s) updated.";
        if ($errors) {
            $status .= ' '.count($errors).' row(s) had problems: '.implode(' ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' ...' : '');

            return back()->with('error', $status);
        }

        return back()->with('status', $status);
    }

    /**
     * Same CSV round trip as bulkTemplate()/bulkAssign(), for staff
     * instead of students (user directive 2026-07-12: "add the import
     * and export feature under the house section too"). Keyed on
     * staff_id, the same external identifier the Staff module already
     * uses everywhere else.
     */
    public function staffBulkTemplate(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request, 'staff.view');

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['staff_id', 'name', 'house']);

        Staff::active()->with('house')->orderBy('last_name')->each(function (Staff $staffMember) use ($out) {
            fputcsv($out, [$staffMember->staff_id, $staffMember->fullName(), $staffMember->house?->name ?? '']);
        });

        rewind($out);
        $csv = (string) stream_get_contents($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="house-assignment-staff.csv"',
        ]);
    }

    /** Re-upload of staffBulkTemplate()'s CSV. A blank house cell leaves that staff member's current house untouched. */
    public function staffBulkAssign(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $csv = file_get_contents($request->file('file')->getRealPath());

        try {
            $rows = app(CsvReader::class)->rows($csv);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $housesByName = House::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim($name)) => $id])->all();

        $updated = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $staffId = trim((string) ($row['staff_id'] ?? ''));
            $houseName = trim((string) ($row['house'] ?? ''));

            if ($staffId === '') {
                $errors[] = "Row {$rowNumber}: missing staff id.";

                continue;
            }
            if ($houseName === '') {
                continue;
            }

            $staffMember = Staff::where('staff_id', $staffId)->first();
            if (! $staffMember) {
                $errors[] = "Row {$rowNumber}: no staff member with id \"{$staffId}\".";

                continue;
            }

            $houseId = $housesByName[mb_strtolower($houseName)] ?? null;
            if (! $houseId) {
                $errors[] = "Row {$rowNumber}: house \"{$houseName}\" was not found.";

                continue;
            }

            $staffMember->update(['house_id' => $houseId]);
            $updated++;
        }

        $status = "{$updated} staff member(s) updated.";
        if ($errors) {
            $status .= ' '.count($errors).' row(s) had problems: '.implode(' ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' ...' : '');

            return back()->with('error', $status);
        }

        return back()->with('status', $status);
    }

    /**
     * Same CSV round trip, for parents. A guardian record is per student
     * (there is no standalone deduplicated Guardian entity), so a row is
     * keyed on the pair of the child's admission number and the parent's
     * name, matching one export row exactly rather than inventing a new
     * external id.
     */
    public function parentsBulkTemplate(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request, 'students.view');

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['student_admission_number', 'parent_name', 'house']);

        StudentGuardian::with(['student', 'house'])->orderBy('name')->each(function (StudentGuardian $guardian) use ($out) {
            fputcsv($out, [$guardian->student?->admission_number ?? '', $guardian->name, $guardian->house?->name ?? '']);
        });

        rewind($out);
        $csv = (string) stream_get_contents($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="house-assignment-parents.csv"',
        ]);
    }

    /** Re-upload of parentsBulkTemplate()'s CSV. A blank house cell leaves that parent's current house untouched. */
    public function parentsBulkAssign(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $csv = file_get_contents($request->file('file')->getRealPath());

        try {
            $rows = app(CsvReader::class)->rows($csv);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $housesByName = House::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim($name)) => $id])->all();

        $updated = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $admissionNumber = trim((string) ($row['student_admission_number'] ?? ''));
            $parentName = trim((string) ($row['parent_name'] ?? ''));
            $houseName = trim((string) ($row['house'] ?? ''));

            if ($admissionNumber === '' || $parentName === '') {
                $errors[] = "Row {$rowNumber}: missing student admission number or parent name.";

                continue;
            }
            if ($houseName === '') {
                continue;
            }

            $guardian = StudentGuardian::whereHas('student', fn ($q) => $q->where('admission_number', $admissionNumber))
                ->where('name', $parentName)
                ->first();
            if (! $guardian) {
                $errors[] = "Row {$rowNumber}: no parent \"{$parentName}\" found for student \"{$admissionNumber}\".";

                continue;
            }

            $houseId = $housesByName[mb_strtolower($houseName)] ?? null;
            if (! $houseId) {
                $errors[] = "Row {$rowNumber}: house \"{$houseName}\" was not found.";

                continue;
            }

            $guardian->update(['house_id' => $houseId]);
            $updated++;
        }

        $status = "{$updated} parent(s) updated.";
        if ($errors) {
            $status .= ' '.count($errors).' row(s) had problems: '.implode(' ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' ...' : '');

            return back()->with('error', $status);
        }

        return back()->with('status', $status);
    }
}
