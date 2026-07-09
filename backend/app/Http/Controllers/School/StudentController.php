<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Student;
use App\Services\Bulk\CsvReader;
use App\Services\Bulk\Importer;
use App\Services\Bulk\Importers\StudentImporter;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Students list + bulk CSV import for a school. Tenant-scoped (the tenant
 * middleware points the connection at the active school). Every action is
 * permission checked via RBAC.
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
            'result' => $result,
        ]);
    }
}
