<?php

namespace Tests\Feature;

use App\Models\Tenant\Enrollment;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Services\Bulk\Importer;
use App\Services\Bulk\Importers\StudentImporter;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StudentTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'students']));
        app(TenantManager::class)->use($school);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    public function test_admission_number_is_unique_and_email_is_optional(): void
    {
        Student::create(['admission_number' => 'ADM/001', 'first_name' => 'Ada', 'last_name' => 'Obi']);

        $this->assertDatabaseHas('students', ['admission_number' => 'ADM/001'], 'tenant');
        $this->assertNull(Student::first()->email); // optional

        $this->expectException(\Illuminate\Database\QueryException::class);
        Student::create(['admission_number' => 'ADM/001', 'first_name' => 'Other', 'last_name' => 'Person']);
    }

    public function test_full_name_and_guardians_and_enrollment(): void
    {
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Basic 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $session = AcademicSession::create(['name' => '2025/2026']);

        $student = Student::create([
            'admission_number' => 'ADM/010',
            'first_name' => 'Ada', 'other_names' => 'Grace', 'last_name' => 'Obi',
            'current_class_arm_id' => $classArm->id,
        ]);
        $student->guardians()->create([
            'name' => 'Mr Obi', 'relationship' => 'Father', 'email' => 'obi@example.com',
            'phone' => '08000000000', 'is_primary' => true,
        ]);
        Enrollment::create([
            'student_id' => $student->id, 'class_arm_id' => $classArm->id,
            'academic_session_id' => $session->id, 'status' => Enrollment::STATUS_ACTIVE,
        ]);

        $this->assertSame('Ada Grace Obi', $student->fullName());
        $this->assertSame('Basic 1 A', $student->currentClassArm->label());
        $this->assertTrue($student->guardians->first()->is_primary);
        $this->assertCount(1, $student->enrollments);
    }

    public function test_importer_template_matches_columns(): void
    {
        $template = (new StudentImporter)->template();
        $this->assertSame("admission_number,first_name,last_name,other_names,gender,date_of_birth,email,parent_mobile\n", $template);
    }

    public function test_bulk_import_valid_and_invalid_rows(): void
    {
        // Pre-existing student to trigger a duplicate-in-DB error.
        Student::create(['admission_number' => 'ADM/EXIST', 'first_name' => 'X', 'last_name' => 'Y']);

        $rows = [
            ['admission_number' => 'ADM/100', 'first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@example.com'],
            ['admission_number' => 'ADM/EXIST', 'first_name' => 'Dup', 'last_name' => 'Licate'], // exists in DB
            ['admission_number' => '', 'first_name' => 'No', 'last_name' => 'Number'],            // required
            ['admission_number' => 'ADM/101', 'first_name' => 'Bode', 'last_name' => 'Ade', 'email' => 'not-an-email'],
        ];

        $result = (new StudentImporter)->import($rows, Importer::MODE_PARTIAL);

        $this->assertSame(1, $result->imported); // only ADM/100
        $this->assertSame(3, $result->failed());
        $this->assertTrue(Student::where('admission_number', 'ADM/100')->exists());
        $this->assertFalse(Student::where('admission_number', 'ADM/101')->exists());

        // Rows are reported with spreadsheet row numbers (header is row 1).
        $rowsWithErrors = array_column($result->errors, 'row');
        $this->assertContains(3, $rowsWithErrors); // ADM/EXIST duplicate
        $this->assertContains(4, $rowsWithErrors); // missing admission number
        $this->assertContains(5, $rowsWithErrors); // bad email
    }

    public function test_strict_import_writes_nothing_on_any_error(): void
    {
        $rows = [
            ['admission_number' => 'ADM/200', 'first_name' => 'Ok', 'last_name' => 'Row'],
            ['admission_number' => '', 'first_name' => 'Bad', 'last_name' => 'Row'],
        ];

        $result = (new StudentImporter)->import($rows, Importer::MODE_STRICT);

        $this->assertSame(0, $result->imported);
        $this->assertSame(0, Student::count());
    }
}
