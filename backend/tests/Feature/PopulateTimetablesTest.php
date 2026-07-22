<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\ExamPaper;
use App\Models\Tenant\ExamTimetableEntry;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Models\Tenant\TimetableEntry;
use App\Models\Tenant\TimetablePeriod;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** User request 2026-07-21: "THEN POPULATE THE TIMETABLES FOR CLASSES AND EXAM FOR ALL CLASSES IN Greenfield Academy." */
class PopulateTimetablesTest extends TestCase
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

    public function test_it_populates_the_weekly_and_examination_timetables_with_real_subjects_and_staff(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'populatetest']));
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $class->subjects()->attach($subject->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $staff = Staff::create(['staff_id' => 'T1', 'first_name' => 'Ada', 'last_name' => 'Teacher']);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id]);
        TimetablePeriod::create(['name' => 'Period 1', 'sequence' => 1, 'starts_at' => '08:00', 'ends_at' => '08:40', 'is_teaching_period' => true]);

        app(TenantManager::class)->forget();

        $this->artisan('timetable:populate', ['school' => 'populatetest'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $entries = TimetableEntry::where('class_arm_id', $classArm->id)->get();
        $papers = ExamPaper::count();
        $examEntries = ExamTimetableEntry::where('scope_type', 'section')->where('scope_id', $section->id)->count();
        app(TenantManager::class)->forget();

        $this->assertGreaterThan(0, $entries->count());
        $this->assertTrue($entries->every(fn (TimetableEntry $e) => $e->subject_id === $subject->id && $e->staff_id === $staff->id));
        $this->assertGreaterThan(0, $papers);
        $this->assertGreaterThan(0, $examEntries);
    }

    public function test_it_reports_an_error_for_an_unknown_school(): void
    {
        $this->artisan('timetable:populate', ['school' => 'nope'])->assertFailed();
    }
}
