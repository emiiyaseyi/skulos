<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\House;
use App\Models\Tenant\Student;
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

    /** A plain list of every active student with a house picker per row, auto saved on change. */
    public function assignForm(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $school = $this->school($request);

        $search = trim((string) $request->query('q'));
        $students = Student::active()
            ->with('house', 'currentClassArm.schoolClass', 'currentClassArm.arm')
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('admission_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            }))
            ->orderBy('last_name')
            ->paginate(30)
            ->withQueryString();

        return view('school.houses.assign', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'students' => $students,
            'houses' => House::orderBy('name')->get(),
            'search' => $search,
        ]);
    }

    public function assign(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $student = Student::findOrFail($request->route('studentId'));

        $data = $request->validate([
            'house_id' => ['nullable', 'exists:tenant.houses,id'],
        ]);

        $student->update(['house_id' => $data['house_id'] ?? null]);

        return back()->with('status', "{$student->fullName()}'s house updated.");
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
}
