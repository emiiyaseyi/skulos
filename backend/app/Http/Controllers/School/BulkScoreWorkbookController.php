<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\ReportCardReview;
use App\Models\Tenant\Score;
use App\Models\Tenant\Student;
use App\Models\Tenant\Term;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Whole-school bulk score workbook (user request 2026-08-07: "let's give
 * access to the admin to download all class subjects, assessment
 * structure etc, it comes with each class having its own sheets in the
 * workbook... admin selects if its for mid term or full term, then can
 * enter all the scores offline and then upload it back"). One workbook,
 * one sheet per class arm, generalizing ExaminationController::
 * bulkTemplate()/bulkUpload()'s existing single-class CSV round trip
 * (same header shape, "Subject: Component", same per-cell max-score
 * validation) across every class arm in the school at once, gated on
 * the same school-wide `examinations.approve_results` tier
 * AssessmentController's own structure screen uses, not the plain
 * per-teacher `examinations.create` the single-class version uses —
 * this is an institution-wide export/import, not a "what I teach" one.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class BulkScoreWorkbookController extends Controller
{
    /** Cell A1/B1 on every sheet: a hidden-in-plain-sight metadata pair so an upload can match a sheet back to its class arm even if the visible sheet title (truncated to Excel's 31 char limit) is no longer unique or was edited. */
    protected const META_LABEL = '__class_arm_id__';

    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), 'examinations.approve_results'),
            403,
            'You do not have permission for this.'
        );
    }

    protected function resolveTerm(Request $request): Term
    {
        $termId = $request->input('term_id');
        if ($termId && $term = Term::find($termId)) {
            return $term;
        }
        $term = Term::current()->first();
        abort_unless($term, 404, 'There is no current term set. Set one under Settings > Sessions & Terms first.');

        return $term;
    }

    protected function resolveReportType(Request $request): string
    {
        $reportType = $request->input('report_type', ReportCardReview::REPORT_TYPE_FULL_TERM);

        return in_array($reportType, [ReportCardReview::REPORT_TYPE_MID_TERM, ReportCardReview::REPORT_TYPE_FULL_TERM], true)
            ? $reportType
            : ReportCardReview::REPORT_TYPE_FULL_TERM;
    }

    public function form(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        return view('school.examinations.bulk-workbook', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'terms' => Term::with('session')->orderByDesc('starts_on')->get(),
            'term' => $this->resolveTerm($request),
            'reportType' => $this->resolveReportType($request),
        ]);
    }

    protected function sheetTitle(ClassArm $classArm, array $usedTitles): string
    {
        $base = Str::of($classArm->label())->replaceMatches('/[\\\\\/\?\*\[\]\:]/', '-')->limit(28, '')->toString();
        $title = $base ?: 'Class';
        $suffix = 2;
        while (in_array(strtolower($title), $usedTitles, true)) {
            $title = Str::limit($base, 28 - strlen((string) $suffix) - 1, '').'-'.$suffix;
            $suffix++;
        }

        return $title;
    }

    /**
     * Every class arm with at least one active student, skipping a class
     * that is Narrative Evaluation for this term (no scores to enter,
     * same exclusion ExaminationController::allowedPairs() already
     * applies for the single-class version).
     */
    protected function eligibleClassArms(Term $term): \Illuminate\Support\Collection
    {
        return ClassArm::with(['schoolClass.section', 'schoolClass.subjects', 'schoolClass.narrativeTerms', 'subjects', 'arm'])
            ->get()
            ->reject(fn (ClassArm $arm) => $arm->schoolClass?->isNarrativeEvaluationForTerm($term->id))
            ->filter(fn (ClassArm $arm) => Student::active()->where('current_class_arm_id', $arm->id)->exists())
            ->sortBy(fn (ClassArm $arm) => $arm->orderKey())
            ->values();
    }

    public function download(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request);
        $term = $this->resolveTerm($request);
        $reportType = $this->resolveReportType($request);

        $components = AssessmentComponent::forReportType($term, $reportType);
        abort_if($components->isEmpty(), 422, 'No assessment component counts toward this report type yet, set that up under Assessment Structure first.');

        $classArms = $this->eligibleClassArms($term);
        abort_if($classArms->isEmpty(), 422, 'No class has any active students yet.');

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $usedTitles = [];
        foreach ($classArms as $index => $classArm) {
            $subjects = $classArm->offeredSubjects();
            if ($subjects->isEmpty()) {
                continue;
            }

            $title = $this->sheetTitle($classArm, $usedTitles);
            $usedTitles[] = strtolower($title);

            $sheet = new Worksheet($spreadsheet, $title);
            $spreadsheet->addSheet($sheet, $index);
            $this->buildSheet($sheet, $classArm, $subjects, $components, $term);
        }

        abort_if($spreadsheet->getSheetCount() === 0, 422, 'No class has any subjects offered yet.');
        $spreadsheet->setActiveSheetIndex(0);

        $filename = 'scores-'.Str::slug($this->school($request)->name).'-'.Str::slug(ReportCardReview::reportTypeLabel($reportType)).'-'.Str::slug($term->name).'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    protected function buildSheet(Worksheet $sheet, ClassArm $classArm, \Illuminate\Support\Collection $subjects, \Illuminate\Support\Collection $components, Term $term): void
    {
        $sheet->setCellValue('A1', self::META_LABEL);
        $sheet->setCellValue('B1', $classArm->id);
        $sheet->getRowDimension(1)->setVisible(false);

        $col = 1;
        $sheet->setCellValue([$col++, 2], 'Admission Number');
        $sheet->setCellValue([$col++, 2], 'Name');

        $columns = [];
        foreach ($subjects as $subject) {
            foreach ($components as $component) {
                $letter = Coordinate::stringFromColumnIndex($col);
                $maxLabel = rtrim(rtrim(number_format((float) $component->max_score, 2), '0'), '.');
                $sheet->setCellValue([$col, 2], "{$subject->name}: {$component->name} (out of {$maxLabel})");
                $columns[] = ['col' => $col, 'letter' => $letter, 'subject_id' => $subject->id, 'component_id' => $component->id, 'max' => (float) $component->max_score];
                $col++;
            }
        }
        $lastCol = $col - 1;
        $sheet->getStyle(Coordinate::stringFromColumnIndex(1).'2:'.Coordinate::stringFromColumnIndex($lastCol).'2')->getFont()->setBold(true);

        $students = Student::active()->where('current_class_arm_id', $classArm->id)->orderBy('first_name')->get();
        $existing = Score::where('class_arm_id', $classArm->id)
            ->where('term_id', $term->id)
            ->get()
            ->keyBy(fn (Score $s) => $s->student_id.':'.$s->subject_id.':'.$s->assessment_component_id);

        $row = 3;
        foreach ($students as $student) {
            $sheet->setCellValue([1, $row], $student->admission_number);
            $sheet->setCellValue([2, $row], $student->fullName());
            foreach ($columns as $c) {
                $score = $existing->get($student->id.':'.$c['subject_id'].':'.$c['component_id'])?->score;
                $sheet->setCellValue([$c['col'], $row], $score !== null ? (float) $score : null);
            }
            $row++;
        }
        $lastRow = max($row - 1, 3);

        // Flags a cell in Excel itself if the entered value is out of
        // range, before it is ever uploaded back (user directive: "each
        // cell should flag entered scores if it exceeds the expected max
        // score for each column/cell") — belt and suspenders alongside
        // the same hard server-side check applied again on upload().
        foreach ($columns as $c) {
            $range = "{$c['letter']}3:{$c['letter']}{$lastRow}";
            $tooHigh = new Conditional();
            $tooHigh->setConditionType(Conditional::CONDITION_CELLIS);
            $tooHigh->setOperatorType(Conditional::OPERATOR_GREATERTHAN);
            $tooHigh->addCondition((string) $c['max']);
            $tooHigh->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFC7CE');
            $tooHigh->getStyle()->getFont()->getColor()->setRGB('9C0006');

            $tooLow = new Conditional();
            $tooLow->setConditionType(Conditional::CONDITION_CELLIS);
            $tooLow->setOperatorType(Conditional::OPERATOR_LESSTHAN);
            $tooLow->addCondition('0');
            $tooLow->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFC7CE');
            $tooLow->getStyle()->getFont()->getColor()->setRGB('9C0006');

            $sheet->getStyle($range)->setConditionalStyles([$tooHigh, $tooLow]);
        }

        foreach (range(1, $lastCol) as $colIndex) {
            $sheet->getColumnDimensionByColumn($colIndex)->setWidth($colIndex <= 2 ? 22 : 16);
        }
        $sheet->freezePane('C3');
    }

    public function upload(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $term = $this->resolveTerm($request);
        $reportType = $this->resolveReportType($request);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);

        $components = AssessmentComponent::forReportType($term, $reportType)->keyBy('name');
        abort_if($components->isEmpty(), 422, 'No assessment component counts toward this report type yet, set that up under Assessment Structure first.');

        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($data['file']->getRealPath());

        $saved = 0;
        $errors = [];
        $sheetsProcessed = 0;
        $lockedSkipped = 0;

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $classArmId = (int) ($sheet->getCell('B1')->getValue() ?: 0);
            $classArm = $classArmId ? ClassArm::find($classArmId) : null;
            if (! $classArm) {
                $errors[] = "Sheet \"{$sheet->getTitle()}\": could not match this sheet back to a class, it may have been renamed or its hidden id row removed.";

                continue;
            }
            $sheetsProcessed++;

            $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
            $highestRow = $sheet->getHighestDataRow();

            // Column map keyed by "Subject Name: Component Name" (the
            // "(out of N)" suffix ignored on match, only ever there for
            // the person filling the sheet, never required to survive a
            // round trip byte for byte).
            $columnMap = [];
            for ($col = 3; $col <= $highestColumn; $col++) {
                $header = (string) $sheet->getCell([$col, 2])->getValue();
                if (! preg_match('/^(.*):\s*(.*?)(?:\s*\(out of[^)]*\))?$/', trim($header), $m)) {
                    continue;
                }
                $subjectName = trim($m[1]);
                $componentName = trim($m[2]);
                $component = $components->get($componentName);
                if (! $component) {
                    continue;
                }
                $subject = \App\Models\Tenant\Subject::where('name', $subjectName)->first();
                if (! $subject) {
                    continue;
                }
                $columnMap[$col] = [
                    'subject_id' => $subject->id,
                    'component_id' => $component->id,
                    'max' => (float) $component->max_score,
                    'label' => "{$subjectName}: {$componentName}",
                ];
            }

            $students = Student::active()->where('current_class_arm_id', $classArm->id)->get()->keyBy('admission_number');
            $lockedStudentIds = ReportCardReview::where('term_id', $term->id)
                ->whereIn('student_id', $students->pluck('id'))
                ->whereIn('status', ReportCardReview::LOCKED_FOR_EDITING)
                ->pluck('student_id')->all();

            for ($row = 3; $row <= $highestRow; $row++) {
                $admissionNumber = trim((string) $sheet->getCell([1, $row])->getValue());
                if ($admissionNumber === '') {
                    continue;
                }
                $student = $students->get($admissionNumber);
                if (! $student) {
                    $errors[] = "Sheet \"{$sheet->getTitle()}\" row {$row}: no active student with admission number \"{$admissionNumber}\" in this class.";

                    continue;
                }
                if (in_array($student->id, $lockedStudentIds, true)) {
                    $lockedSkipped++;

                    continue;
                }

                foreach ($columnMap as $col => $meta) {
                    $value = $sheet->getCell([$col, $row])->getValue();
                    if ($value === null || $value === '') {
                        continue;
                    }
                    if (! is_numeric($value) || (float) $value < 0 || (float) $value > $meta['max']) {
                        $errors[] = "Sheet \"{$sheet->getTitle()}\" row {$row} ({$admissionNumber}): \"{$meta['label']}\" must be between 0 and {$meta['max']}.";

                        continue;
                    }

                    Score::updateOrCreate(
                        [
                            'student_id' => $student->id,
                            'subject_id' => $meta['subject_id'],
                            'term_id' => $term->id,
                            'assessment_component_id' => $meta['component_id'],
                        ],
                        [
                            'class_arm_id' => $classArm->id,
                            'score' => (float) $value,
                        ]
                    );
                    $saved++;
                }
            }
        }

        $message = "{$sheetsProcessed} class(es) processed, {$saved} score(s) saved.";
        if ($lockedSkipped > 0) {
            $message .= " {$lockedSkipped} student(s) skipped because their report card is locked for editing.";
        }
        if ($errors) {
            $shown = array_slice($errors, 0, 20);
            $message .= ' '.count($errors).' issue(s): '.implode(' | ', $shown);
            if (count($errors) > count($shown)) {
                $message .= ' ...and '.(count($errors) - count($shown)).' more.';
            }
        }

        return redirect()->route('school.examinations.workbook', ['school' => $school->slug, 'term_id' => $term->id, 'report_type' => $reportType])
            ->with($errors ? 'error' : 'status', $message);
    }
}
