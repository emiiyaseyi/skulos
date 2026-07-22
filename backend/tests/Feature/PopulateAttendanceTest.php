<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Arm;
use App\Models\Tenant\Attendance;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** User request 2026-07-22: "I want you to populate attendance data for staff and learners for the past one year." */
class PopulateAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    public function test_it_populates_attendance_for_every_school_day_and_never_overwrites_an_existing_mark(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'attpoptest']));
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm->id]);
        $staff = Staff::create(['staff_id' => 'T1', 'first_name' => 'Tade', 'last_name' => 'Teacher', 'status' => 'active']);

        // A real mark already exists for one day; the populate run must
        // never touch it, even though it would otherwise be in range.
        $preMarkedDate = now()->subDays(5);
        while ($preMarkedDate->isWeekend()) {
            $preMarkedDate->subDay();
        }
        Attendance::create(['date' => $preMarkedDate->toDateString(), 'person_type' => 'student', 'person_id' => $student->id, 'class_arm_id' => $classArm->id, 'status' => 'absent']);
        app(TenantManager::class)->forget();

        $this->artisan('attendance:populate', ['school' => 'attpoptest', '--from' => now()->subDays(10)->toDateString(), '--to' => now()->toDateString()])
            ->assertSuccessful();

        app(TenantManager::class)->use($school);
        $studentRows = Attendance::students()->where('person_id', $student->id)->get();
        $staffRows = Attendance::staff()->where('person_id', $staff->id)->get();
        $preserved = Attendance::students()->where('person_id', $student->id)->where('date', $preMarkedDate->toDateString())->first();
        app(TenantManager::class)->forget();

        $this->assertGreaterThan(1, $studentRows->count());
        $this->assertGreaterThan(1, $staffRows->count());
        $this->assertTrue($studentRows->every(fn (Attendance $a) => in_array($a->status, ['present', 'absent', 'late', 'excused'], true)));
        $this->assertNotNull($preserved);
        $this->assertSame('absent', $preserved->status); // untouched, not overwritten by a random re-roll
    }

    public function test_it_reports_an_error_for_an_unknown_school(): void
    {
        $this->artisan('attendance:populate', ['school' => 'nope'])->assertFailed();
    }
}
