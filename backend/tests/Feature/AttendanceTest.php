<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\Tenant\Arm;
use App\Models\Tenant\Attendance;
use App\Models\Tenant\CalendarEvent;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Services\Attendance\AttendanceService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected ClassArm $classArm;

    protected array $students;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'attendance']));
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Basic 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $this->classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);

        $this->students = [];
        foreach (['ADM/1', 'ADM/2', 'ADM/3'] as $i => $adm) {
            $this->students[] = Student::create([
                'admission_number' => $adm, 'first_name' => 'S'.$i, 'last_name' => 'Test',
                'current_class_arm_id' => $this->classArm->id,
            ]);
        }
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    public function test_marking_a_class_records_one_row_per_student(): void
    {
        $service = app(AttendanceService::class);
        [$a, $b, $c] = $this->students;

        $result = $service->markClass($this->classArm->id, '2026-01-15', [
            $a->id => 'present',
            $b->id => 'absent',
            $c->id => 'late',
        ]);

        $this->assertSame(3, $result['marked']);
        $this->assertSame(3, Attendance::students()->forDate('2026-01-15')->count());
        $this->assertEqualsCanonicalizing(
            ['present' => 1, 'absent' => 1, 'late' => 1],
            $service->classSummary($this->classArm->id, '2026-01-15')
        );
    }

    public function test_marking_is_idempotent_and_corrects_in_place(): void
    {
        $service = app(AttendanceService::class);
        $a = $this->students[0];

        $service->markClass($this->classArm->id, '2026-01-15', [$a->id => 'absent']);
        $service->markClass($this->classArm->id, '2026-01-15', [$a->id => 'present']); // correction

        $this->assertSame(1, Attendance::where('person_id', $a->id)->forDate('2026-01-15')->count());
        $this->assertSame('present', Attendance::where('person_id', $a->id)->forDate('2026-01-15')->first()->status);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(AttendanceService::class)->markClass($this->classArm->id, '2026-01-15', [
            $this->students[0]->id => 'teleported',
        ]);
    }

    public function test_staff_attendance_is_separate_from_students(): void
    {
        $staff = Staff::create(['staff_id' => 'STF/1', 'first_name' => 'T', 'last_name' => 'Eacher']);
        app(AttendanceService::class)->markStaff($staff->id, '2026-01-15', 'present');

        $this->assertSame(1, Attendance::staff()->count());
        $this->assertSame(0, Attendance::students()->count());
    }

    public function test_class_marking_is_audited(): void
    {
        app(AttendanceService::class)->markClass($this->classArm->id, '2026-01-15', [
            $this->students[0]->id => 'present',
        ]);

        $log = AuditLog::on('tenant')->where('action', 'attendance.class_marked')->first();
        $this->assertNotNull($log);
        $this->assertSame(1, $log->new_values['count']);
    }

    /**
     * "Days school was open" on the report card (user request 2026-07-24:
     * "this is calculated from the term start date and end date.
     * excluding the weekends... and other public holidays, mid term
     * break"), reusing the exact same non school day rule attendance
     * marking already enforces.
     */
    public function test_school_days_between_excludes_weekends_and_a_holiday(): void
    {
        // 2026-01-05 (Monday) to 2026-01-18 (Sunday): two full weeks, 10
        // weekdays, one of which (2026-01-08, a Thursday) is a holiday.
        CalendarEvent::create(['title' => 'Public Holiday', 'category' => 'holiday', 'starts_on' => '2026-01-08', 'ends_on' => '2026-01-08']);

        $days = app(AttendanceService::class)->schoolDaysBetween(Carbon::parse('2026-01-05'), Carbon::parse('2026-01-18'));

        $this->assertSame(9, $days, '10 weekdays across the range minus the 1 holiday');
    }

    public function test_school_days_between_excludes_a_ranged_mid_term_break(): void
    {
        // A whole week, Monday to Friday, all 5 days inside a Mid Term Break range.
        CalendarEvent::create(['title' => 'Mid Term Break', 'category' => 'break', 'starts_on' => '2026-02-16', 'ends_on' => '2026-02-20']);

        $days = app(AttendanceService::class)->schoolDaysBetween(Carbon::parse('2026-02-16'), Carbon::parse('2026-02-20'));

        $this->assertSame(0, $days);
    }
}
