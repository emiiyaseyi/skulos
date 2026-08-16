<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * The school-wide view of subjects (2026-08-16).
 *
 * Subjects could already be created and attached, but only from inside
 * one class at a time, and teachers could only be assigned one class
 * arm at a time. Neither view answered the question an admin actually
 * has at setup: "which subjects does this school teach, and is every
 * class that takes each one covered by a teacher yet?"
 *
 * So this adds the two missing angles: every subject in one list, and
 * a page per subject showing every class arm that offers it with its
 * teacher, where an admin picks one teacher, ticks the arms they take,
 * saves, and repeats until nothing is left uncovered.
 */
class SubjectController extends Controller
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

    /** Every subject the school teaches, with how well covered each one is. */
    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'academics.view');
        $school = $this->school($request);

        $subjects = Subject::orderBy('sequence')->orderBy('name')->get();

        // One query for every assignment rather than one per subject:
        // this page shows every subject in the school, so the per-row
        // lookup would be a textbook N+1.
        $assignedArmsBySubject = TeachingAssignment::select('subject_id', 'class_arm_id')
            ->get()->groupBy('subject_id')->map(fn ($rows) => $rows->pluck('class_arm_id')->unique());

        $armsBySubject = \Illuminate\Support\Facades\DB::connection('tenant')->table('class_arm_subjects')
            ->select('subject_id', 'class_arm_id')->get()
            ->groupBy('subject_id')->map(fn ($rows) => $rows->pluck('class_arm_id')->unique());

        $rows = $subjects->map(function (Subject $subject) use ($armsBySubject, $assignedArmsBySubject) {
            $offeredIn = $armsBySubject[$subject->id] ?? collect();
            $covered = $assignedArmsBySubject[$subject->id] ?? collect();

            return [
                'subject' => $subject,
                'arms' => $offeredIn->count(),
                'covered' => $offeredIn->intersect($covered)->count(),
                'uncovered' => $offeredIn->diff($covered)->count(),
            ];
        });

        return view('school.subjects.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'rows' => $rows,
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'academics.update'),
            // Drives the "add your staff first" warning: assigning a
            // teacher is impossible without any, and the workbook's
            // dropdown would be empty.
            'staffCount' => Staff::count(),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('subjects', 'name')],
            'code' => ['nullable', 'string', 'max:20'],
        ]);

        Subject::create([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'sequence' => (int) Subject::max('sequence') + 1,
        ]);

        return back()->with('status', "\"{$data['name']}\" added.");
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $subject = Subject::findOrFail($request->route('subjectId'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('subjects', 'name')->ignore($subject->id)],
            'code' => ['nullable', 'string', 'max:20'],
        ]);

        $subject->update($data);

        return back()->with('status', "\"{$subject->name}\" updated.");
    }

    /**
     * One subject, every class arm that offers it, and who teaches it.
     * The uncovered arms are listed first and counted, because that is
     * the whole reason to be on this page.
     */
    public function show(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'academics.view');
        $school = $this->school($request);
        $subject = Subject::findOrFail($request->route('subjectId'));

        $armIds = \Illuminate\Support\Facades\DB::connection('tenant')->table('class_arm_subjects')
            ->where('subject_id', $subject->id)->pluck('class_arm_id');

        $arms = ClassArm::whereIn('id', $armIds)
            ->with('schoolClass.section', 'arm')
            ->get()
            ->sortBy(fn ($a) => [$a->schoolClass?->section?->sequence, $a->schoolClass?->sequence, $a->name]);

        $teacherByArm = TeachingAssignment::where('subject_id', $subject->id)
            ->with('staff')->get()->keyBy('class_arm_id');

        return view('school.subjects.show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'subject' => $subject,
            'arms' => $arms,
            'teacherByArm' => $teacherByArm,
            'staff' => Staff::orderBy('last_name')->get(),
            'uncovered' => $arms->filter(fn ($a) => ! $teacherByArm->has($a->id)),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'academics.update'),
        ]);
    }

    /**
     * Assign ONE teacher to the class arms ticked for them. Repeatable
     * by design: an admin assigns whoever covers the first few arms,
     * saves, and the page comes back showing what is still uncovered,
     * until nothing is.
     */
    public function assignTeacher(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $subject = Subject::findOrFail($request->route('subjectId'));

        $data = $request->validate([
            'staff_id' => ['required', 'integer', 'exists:tenant.staff,id'],
            'class_arm_ids' => ['required', 'array', 'min:1'],
            'class_arm_ids.*' => ['integer'],
        ]);

        foreach ($data['class_arm_ids'] as $armId) {
            // updateOrCreate keyed on the arm+subject, so re-assigning an
            // arm moves it to the new teacher instead of leaving two
            // teachers silently owning the same class.
            TeachingAssignment::updateOrCreate(
                ['subject_id' => $subject->id, 'class_arm_id' => $armId],
                ['staff_id' => $data['staff_id']],
            );
        }

        $remaining = $this->uncoveredCount($subject);

        return back()->with('status', count($data['class_arm_ids']).' class(es) assigned. '
            .($remaining ? "{$remaining} still without a teacher for this subject." : 'Every class taking this subject now has a teacher.'));
    }

    public function unassignTeacher(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $subject = Subject::findOrFail($request->route('subjectId'));

        TeachingAssignment::where('subject_id', $subject->id)
            ->where('class_arm_id', $request->integer('class_arm_id'))->delete();

        return back()->with('status', 'Teacher removed from that class.');
    }

    protected function uncoveredCount(Subject $subject): int
    {
        $armIds = \Illuminate\Support\Facades\DB::connection('tenant')->table('class_arm_subjects')
            ->where('subject_id', $subject->id)->pluck('class_arm_id');
        $covered = TeachingAssignment::where('subject_id', $subject->id)->pluck('class_arm_id');

        return $armIds->diff($covered)->count();
    }

    /**
     * A real Excel workbook, not a CSV: the teacher column is a genuine
     * dropdown of this school's staff, which plain CSV cannot express
     * (a spreadsheet has no way to carry validation rules in that
     * format). One row per class arm and subject, pre-filled with
     * whoever currently teaches it.
     */
    public function workbook(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request, 'academics.view');

        $staff = Staff::orderBy('last_name')->get();
        abort_if($staff->isEmpty(), 422, 'Add your staff before downloading this, or the teacher column has nothing to offer.');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Assignments');
        $sheet->fromArray(['Class', 'Subject', 'Teacher'], null, 'A1');
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);

        // Staff names live on their own sheet and the dropdown points at
        // that range: a long inline list breaks Excel's own limit on
        // formula length, and a separate sheet also lets a school see
        // exactly which names are valid.
        $lookup = $spreadsheet->createSheet();
        $lookup->setTitle('Staff');
        $names = [];
        foreach ($staff as $i => $member) {
            $name = trim($member->first_name.' '.$member->last_name);
            $lookup->setCellValue('A'.($i + 1), $name);
            $names[] = $name;
        }

        $assignments = TeachingAssignment::with('staff')->get()
            ->keyBy(fn ($a) => $a->class_arm_id.':'.$a->subject_id);

        $row = 2;
        $arms = ClassArm::with('schoolClass.section', 'arm')->get();
        $subjectsByArm = \Illuminate\Support\Facades\DB::connection('tenant')->table('class_arm_subjects')->get()->groupBy('class_arm_id');
        $subjects = Subject::get()->keyBy('id');

        foreach ($arms as $arm) {
            foreach ($subjectsByArm[$arm->id] ?? [] as $link) {
                $subject = $subjects[$link->subject_id] ?? null;
                if (! $subject) {
                    continue;
                }
                $current = $assignments[$arm->id.':'.$subject->id] ?? null;
                $sheet->setCellValue('A'.$row, $arm->label());
                $sheet->setCellValue('B'.$row, $subject->name);
                $sheet->setCellValue('C'.$row, $current?->staff
                    ? trim($current->staff->first_name.' '.$current->staff->last_name)
                    : '');

                $validation = $sheet->getCell('C'.$row)->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setErrorStyle(DataValidation::STYLE_STOP);
                $validation->setAllowBlank(true);
                $validation->setShowDropDown(true);
                $validation->setShowErrorMessage(true);
                $validation->setErrorTitle('Not one of your staff');
                $validation->setError('Pick a name from the list.');
                $validation->setFormula1('Staff!$A$1:$A$'.count($names));

                $row++;
            }
        }

        foreach (['A', 'B', 'C'] as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $spreadsheet->setActiveSheetIndex(0);

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        ob_start();
        $writer->save('php://output');
        $contents = (string) ob_get_clean();

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="subject-teachers.xlsx"',
        ]);
    }

    /** Re-upload of the workbook above. Blank teacher cells are left alone. */
    public function uploadWorkbook(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120']]);

        $sheet = IOFactory::load($request->file('file')->getRealPath())->getSheetByName('Assignments');
        abort_if(! $sheet, 422, 'That file has no "Assignments" sheet. Download a fresh copy and fill that in.');

        $staffByName = Staff::get()->keyBy(fn ($s) => mb_strtolower(trim($s->first_name.' '.$s->last_name)));
        $subjectsByName = Subject::get()->keyBy(fn ($s) => mb_strtolower($s->name));
        $armsByLabel = ClassArm::with('schoolClass', 'arm')->get()->keyBy(fn ($a) => mb_strtolower($a->label()));

        $applied = 0;
        $problems = [];
        foreach ($sheet->getRowIterator(2) as $row) {
            $line = $row->getRowIndex();
            $armLabel = trim((string) $sheet->getCell('A'.$line)->getValue());
            $subjectName = trim((string) $sheet->getCell('B'.$line)->getValue());
            $teacherName = trim((string) $sheet->getCell('C'.$line)->getValue());

            if ($armLabel === '' || $subjectName === '' || $teacherName === '') {
                continue;
            }

            $arm = $armsByLabel->get(mb_strtolower($armLabel));
            $subject = $subjectsByName->get(mb_strtolower($subjectName));
            $teacher = $staffByName->get(mb_strtolower($teacherName));

            if (! $arm || ! $subject || ! $teacher) {
                $problems[] = "Row {$line}: ".(! $arm ? "unknown class \"{$armLabel}\"" : (! $subject ? "unknown subject \"{$subjectName}\"" : "unknown teacher \"{$teacherName}\""));

                continue;
            }

            TeachingAssignment::updateOrCreate(
                ['subject_id' => $subject->id, 'class_arm_id' => $arm->id],
                ['staff_id' => $teacher->id],
            );
            $applied++;
        }

        $message = "{$applied} assignment(s) applied.";
        if ($problems) {
            // Partial save, the same convention every other import here
            // follows: good rows land, bad ones are named by line.
            $message .= ' '.count($problems).' row(s) skipped: '.implode('; ', array_slice($problems, 0, 5));
        }

        return back()->with('status', $message);
    }
}
