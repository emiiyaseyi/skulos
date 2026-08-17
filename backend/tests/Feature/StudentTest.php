<?php

namespace Tests\Feature;

use App\Models\Tenant\Enrollment;
use App\Models\Tenant\House;
use App\Models\Tenant\Setting;
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
        // Separate parent1_*/parent2_* columns replaced the single
        // parent_mobile-only shape (2026-08-16, direct instruction: the
        // sheet needed a real name/email/phone/relationship per
        // guardian, not just a phone number).
        $template = (new StudentImporter)->template();
        $this->assertSame(
            "admission_number,first_name,last_name,other_names,gender,date_of_birth,email,parent_mobile,parent1_name,parent1_email,parent1_phone,parent1_relationship,parent2_name,parent2_email,parent2_phone,parent2_relationship,class,house,state_of_origin,lga,address\n",
            $template
        );
    }

    public function test_import_resolves_class_and_house_by_name(): void
    {
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Basic 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $house = House::create(['name' => 'Red House']);

        $rows = [[
            'admission_number' => 'ADM/300', 'first_name' => 'Ada', 'last_name' => 'Obi',
            'class' => 'Basic 1 A', 'house' => 'red house', // case-insensitive match
            'state_of_origin' => 'Lagos', 'lga' => 'Ikeja', 'address' => '1 School Road',
        ]];

        $result = (new StudentImporter)->import($rows, Importer::MODE_STRICT);
        $this->assertSame(1, $result->imported);

        $student = Student::where('admission_number', 'ADM/300')->first();
        $this->assertSame($classArm->id, $student->current_class_arm_id);
        $this->assertSame($house->id, $student->house_id);
        $this->assertSame('Lagos', $student->state_of_origin);
        $this->assertSame('Ikeja', $student->lga);
        $this->assertSame('1 School Road', $student->address);
    }

    public function test_import_rejects_an_unknown_class_or_house_name(): void
    {
        $rows = [[
            'admission_number' => 'ADM/301', 'first_name' => 'Ada', 'last_name' => 'Obi',
            'class' => 'Nonexistent Class',
        ]];

        $result = (new StudentImporter)->import($rows, Importer::MODE_STRICT);

        $this->assertSame(0, $result->imported);
        $this->assertSame(1, $result->failed());
        $this->assertFalse(Student::where('admission_number', 'ADM/301')->exists());
    }

    public function test_admin_configured_required_fields_are_enforced_on_import(): void
    {
        Setting::put('student_required_fields', ['class', 'state_of_origin']);

        $rows = [[
            'admission_number' => 'ADM/302', 'first_name' => 'Ada', 'last_name' => 'Obi',
            // class and state_of_origin deliberately omitted
        ]];

        $result = (new StudentImporter)->import($rows, Importer::MODE_STRICT);

        $this->assertSame(0, $result->imported);
        $errors = $result->errors[0]['errors'];
        $this->assertArrayHasKey('class', $errors);
        $this->assertArrayHasKey('state_of_origin', $errors);
    }

    public function test_email_can_never_be_marked_required(): void
    {
        // Even if someone stored "email" in the setting directly (bypassing the
        // admin UI's validated field list), the importer must not honor it.
        Setting::put('student_required_fields', ['email']);

        $rows = [[
            'admission_number' => 'ADM/303', 'first_name' => 'Ada', 'last_name' => 'Obi',
        ]];

        $result = (new StudentImporter)->import($rows, Importer::MODE_STRICT);

        $this->assertSame(1, $result->imported, 'email must stay optional regardless of stored settings');
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
