<?php
namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Services\Bulk\Importers\StudentImporter;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Guardian columns on the student import (2026-08-16). The sheet used
 * to carry a bare "parent mobile" that created no guardian record at
 * all, so an imported school had students with nobody attached and no
 * parent who could sign in to see them.
 */
class StudentGuardianImportTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function school(): School
    {
        return app(TenantManager::class)->provision(School::factory()->create(['slug' => 'guardimp']));
    }

    public function test_both_guardians_are_created_and_the_first_is_the_primary_contact(): void
    {
        $school = $this->school();

        app(TenantManager::class)->connectTo($school, function () {
            (new StudentImporter)->import([[
                'admission_number' => 'G/001', 'first_name' => 'Ada', 'last_name' => 'Okoro',
                'parent1_name' => 'Ngozi Okoro', 'parent1_email' => 'ngozi@example.com',
                'parent1_phone' => '08030000001', 'parent1_relationship' => 'Mother',
                'parent2_name' => 'Emeka Okoro', 'parent2_email' => 'emeka@example.com',
                'parent2_phone' => '08030000002', 'parent2_relationship' => 'Father',
            ]], 'partial');

            $student = Student::where('admission_number', 'G/001')->firstOrFail();
            $guardians = StudentGuardian::where('student_id', $student->id)->orderBy('id')->get();

            $this->assertCount(2, $guardians, 'Both guardians should have been created.');
            $this->assertSame('Ngozi Okoro', $guardians[0]->name);
            $this->assertSame('ngozi@example.com', $guardians[0]->email);
            $this->assertTrue((bool) $guardians[0]->is_primary);
            $this->assertSame('Emeka Okoro', $guardians[1]->name);
            $this->assertFalse((bool) $guardians[1]->is_primary);
        });
    }

    public function test_an_older_sheet_with_only_parent_mobile_still_creates_a_guardian(): void
    {
        $school = $this->school();

        app(TenantManager::class)->connectTo($school, function () {
            (new StudentImporter)->import([[
                'admission_number' => 'G/002', 'first_name' => 'Bola', 'last_name' => 'Ade',
                'parent_mobile' => '08031111111',
            ]], 'partial');

            $student = Student::where('admission_number', 'G/002')->firstOrFail();
            $guardian = StudentGuardian::where('student_id', $student->id)->first();

            // The legacy column keeps working rather than silently
            // producing a student with no contact at all.
            $this->assertNotNull($guardian);
            $this->assertSame('08031111111', $guardian->phone);
        });
    }

    public function test_a_student_with_no_parent_details_creates_no_empty_guardian(): void
    {
        $school = $this->school();

        app(TenantManager::class)->connectTo($school, function () {
            (new StudentImporter)->import([[
                'admission_number' => 'G/003', 'first_name' => 'Chidi', 'last_name' => 'Eze',
            ]], 'partial');

            $student = Student::where('admission_number', 'G/003')->firstOrFail();
            $this->assertSame(0, StudentGuardian::where('student_id', $student->id)->count());
        });
    }
}
