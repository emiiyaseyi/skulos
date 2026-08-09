<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\ReportCardReview;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Score;
use App\Models\Tenant\Section;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * Whole-school bulk score workbook (2026-08-07): one Excel workbook, one
 * sheet per class, admin downloads it, fills scores offline, uploads it
 * back. These tests exercise the real xlsx bytes end to end (a real
 * PhpSpreadsheet write on download, a real PhpSpreadsheet read on
 * upload), not a mock of either library, matching this project's own
 * "real integration, not a stand-in" testing convention.
 */
class BulkScoreWorkbookTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected Rbac $rbac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
        $this->seed(RbacSeeder::class);
        $this->rbac = app(Rbac::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function schoolWithMember(string $roleSlug, string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    protected function seedAcademics(School $school): array
    {
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $class->subjects()->attach($subject->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $term->makeCurrent();

        $ca1 = AssessmentComponent::create(['name' => 'CA 1', 'max_score' => 10, 'sequence' => 1, 'counts_for_midterm' => true, 'counts_for_fullterm' => true]);
        $ca2 = AssessmentComponent::create(['name' => 'CA 2', 'max_score' => 20, 'sequence' => 2, 'counts_for_midterm' => true, 'counts_for_fullterm' => true]);
        $exam = AssessmentComponent::create(['name' => 'Exam', 'max_score' => 70, 'sequence' => 3, 'counts_for_midterm' => false, 'counts_for_fullterm' => true]);

        $ada = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm->id]);
        $bode = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade', 'current_class_arm_id' => $classArm->id]);

        app(TenantManager::class)->forget();

        return compact('classArm', 'term', 'subject', 'ca1', 'ca2', 'exam', 'ada', 'bode');
    }

    protected function downloadWorkbook(User $user, School $school, int $termId, string $reportType): Spreadsheet
    {
        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/examinations/workbook/download?term_id={$termId}&report_type={$reportType}");
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'dl').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $spreadsheet = (new XlsxReader())->load($tmp);
        @unlink($tmp);

        return $spreadsheet;
    }

    protected function buildUploadFile(int $classArmId, string $sheetTitle, array $columnHeaders, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        $sheet = new Worksheet($spreadsheet, $sheetTitle);
        $spreadsheet->addSheet($sheet, 0);
        $sheet->setCellValue('A1', '__class_arm_id__');
        $sheet->setCellValue('B1', $classArmId);

        $col = 1;
        $sheet->setCellValue([$col++, 2], 'Admission Number');
        $sheet->setCellValue([$col++, 2], 'Name');
        foreach ($columnHeaders as $header) {
            $sheet->setCellValue([$col++, 2], $header);
        }

        $row = 3;
        foreach ($rows as $values) {
            $c = 1;
            foreach ($values as $value) {
                $sheet->setCellValue([$c++, $row], $value);
            }
            $row++;
        }
        $spreadsheet->setActiveSheetIndex(0);

        $tmp = tempnam(sys_get_temp_dir(), 'up').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($tmp);
        $file = UploadedFile::fake()->createWithContent('workbook.xlsx', file_get_contents($tmp));
        @unlink($tmp);

        return $file;
    }

    public function test_only_a_school_wide_role_can_reach_the_workbook_screens(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'wbperm');
        $f = $this->seedAcademics($school);

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations/workbook")
            ->assertForbidden();
        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations/workbook/download?term_id={$f['term']->id}&report_type=full_term")
            ->assertForbidden();
    }

    public function test_the_full_term_workbook_includes_every_full_term_component_across_the_only_class(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'wbfull');
        $f = $this->seedAcademics($school);

        $spreadsheet = $this->downloadWorkbook($owner, $school, $f['term']->id, ReportCardReview::REPORT_TYPE_FULL_TERM);

        $this->assertSame(1, $spreadsheet->getSheetCount());
        $sheet = $spreadsheet->getSheet(0);
        $this->assertSame('Mathematics: CA 1 (out of 10)', trim((string) $sheet->getCell([3, 2])->getValue()));
        $this->assertSame('Mathematics: CA 2 (out of 20)', trim((string) $sheet->getCell([4, 2])->getValue()));
        $this->assertSame('Mathematics: Exam (out of 70)', trim((string) $sheet->getCell([5, 2])->getValue()));
        $this->assertSame('A1', $sheet->getCell([1, 3])->getValue());
        $this->assertSame('Ada Obi', $sheet->getCell([2, 3])->getValue());
    }

    public function test_the_mid_term_workbook_excludes_the_exam_component(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'wbmid');
        $f = $this->seedAcademics($school);

        $spreadsheet = $this->downloadWorkbook($owner, $school, $f['term']->id, ReportCardReview::REPORT_TYPE_MID_TERM);

        $sheet = $spreadsheet->getSheet(0);
        $this->assertSame('Mathematics: CA 1 (out of 10)', trim((string) $sheet->getCell([3, 2])->getValue()));
        $this->assertSame('Mathematics: CA 2 (out of 20)', trim((string) $sheet->getCell([4, 2])->getValue()));
        // Only 4 columns total (Admission Number, Name, CA 1, CA 2), no Exam column.
        $this->assertSame('D', $sheet->getHighestColumn());
    }

    public function test_the_download_pre_fills_an_already_entered_score(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'wbprefill');
        $f = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        Score::create([
            'student_id' => $f['ada']->id, 'subject_id' => $f['subject']->id, 'term_id' => $f['term']->id,
            'assessment_component_id' => $f['ca1']->id, 'class_arm_id' => $f['classArm']->id, 'score' => 7,
        ]);
        app(TenantManager::class)->forget();

        $spreadsheet = $this->downloadWorkbook($owner, $school, $f['term']->id, ReportCardReview::REPORT_TYPE_FULL_TERM);
        $sheet = $spreadsheet->getSheet(0);
        $this->assertEquals(7, $sheet->getCell([3, 3])->getValue());
    }

    public function test_uploading_the_workbook_back_saves_valid_scores_and_reports_an_out_of_range_cell_without_losing_the_rest(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'wbupload');
        $f = $this->seedAcademics($school);

        $file = $this->buildUploadFile(
            $f['classArm']->id,
            'JSS 1 A',
            ['Mathematics: CA 1 (out of 10)', 'Mathematics: CA 2 (out of 20)'],
            [
                ['A1', 'Ada Obi', 9, 15],
                // CA 2 out of range (max 20): this one cell must be
                // skipped and reported, but Bode's own valid CA 1 cell
                // still saves, matching ExaminationController::
                // bulkUpload()'s existing partial-save convention.
                ['A2', 'Bode Ade', 6, 999],
            ]
        );

        $response = $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/workbook/upload", [
            'term_id' => $f['term']->id,
            'report_type' => ReportCardReview::REPORT_TYPE_FULL_TERM,
            'file' => $file,
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('error');

        app(TenantManager::class)->use($school);
        $this->assertEquals(9, Score::where('student_id', $f['ada']->id)->where('assessment_component_id', $f['ca1']->id)->first()->score);
        $this->assertEquals(15, Score::where('student_id', $f['ada']->id)->where('assessment_component_id', $f['ca2']->id)->first()->score);
        $this->assertEquals(6, Score::where('student_id', $f['bode']->id)->where('assessment_component_id', $f['ca1']->id)->first()->score);
        $this->assertNull(Score::where('student_id', $f['bode']->id)->where('assessment_component_id', $f['ca2']->id)->first());
        app(TenantManager::class)->forget();
    }

    public function test_a_sheet_with_no_matching_class_arm_id_is_reported_and_skipped(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'wbnomatch');
        $f = $this->seedAcademics($school);

        $file = $this->buildUploadFile(999999, 'Ghost Class', ['Mathematics: CA 1 (out of 10)'], [['A1', 'Ada Obi', 5]]);

        $response = $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/workbook/upload", [
            'term_id' => $f['term']->id,
            'report_type' => ReportCardReview::REPORT_TYPE_FULL_TERM,
            'file' => $file,
        ]);
        $response->assertSessionHas('error');

        app(TenantManager::class)->use($school);
        $this->assertNull(Score::where('student_id', $f['ada']->id)->first());
        app(TenantManager::class)->forget();
    }
}
