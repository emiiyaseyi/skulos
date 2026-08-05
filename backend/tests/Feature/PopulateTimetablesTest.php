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

    /**
     * User directive 2026-08-05: "no class should have a free period
     * except for SSS 2 and 3... max of free period of 1 per day...
     * tagged as library... twice a week... no clash since we have just
     * one library." Also verifies no cross-class staff double-booking,
     * the other real gap the original naive cycling had.
     */
    public function test_only_sss2_and_sss3_get_library_periods_twice_a_week_with_no_clash_and_no_staff_conflicts(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'poplibrary']));
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $jss1 = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $sss2 = SchoolClass::create(['section_id' => $section->id, 'name' => 'SSS 2', 'sequence' => 2]);

        $jss1Arm = ClassArm::create(['school_class_id' => $jss1->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $sss2ArmA = ClassArm::create(['school_class_id' => $sss2->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);
        $sss2ArmB = ClassArm::create(['school_class_id' => $sss2->id, 'arm_id' => Arm::create(['name' => 'C'])->id]);

        $maths = Subject::create(['name' => 'Mathematics']);
        $english = Subject::create(['name' => 'English']);
        $teacherShared = Staff::create(['staff_id' => 'T1', 'first_name' => 'Shared', 'last_name' => 'Teacher']);
        $teacherOther = Staff::create(['staff_id' => 'T2', 'first_name' => 'Other', 'last_name' => 'Teacher']);

        // The SAME teacher assigned to BOTH SSS2 arms, deliberately, to
        // force the conflict-avoidance path — without it, this teacher
        // would be scheduled into two classes at once.
        TeachingAssignment::create(['staff_id' => $teacherShared->id, 'subject_id' => $maths->id, 'class_arm_id' => $sss2ArmA->id]);
        TeachingAssignment::create(['staff_id' => $teacherShared->id, 'subject_id' => $maths->id, 'class_arm_id' => $sss2ArmB->id]);
        TeachingAssignment::create(['staff_id' => $teacherOther->id, 'subject_id' => $english->id, 'class_arm_id' => $jss1Arm->id]);

        foreach (['Period 1', 'Period 2', 'Period 3', 'Period 4', 'Period 5'] as $i => $name) {
            TimetablePeriod::create(['name' => $name, 'sequence' => $i + 1, 'starts_at' => '08:00', 'ends_at' => '08:40', 'is_teaching_period' => true]);
        }

        app(TenantManager::class)->forget();

        $this->artisan('timetable:populate', ['school' => 'poplibrary'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $library = Subject::where('name', 'Library')->first();
        $this->assertNotNull($library, 'a Library subject should have been created');

        $jss1Library = TimetableEntry::where('class_arm_id', $jss1Arm->id)->where('subject_id', $library->id)->count();
        $this->assertSame(0, $jss1Library, 'JSS 1 must never get a library period');

        foreach ([$sss2ArmA, $sss2ArmB] as $arm) {
            $libraryEntries = TimetableEntry::where('class_arm_id', $arm->id)->where('subject_id', $library->id)->get();
            $this->assertCount(2, $libraryEntries, 'each SSS2 arm should get exactly 2 library periods a week');
            $this->assertSame($libraryEntries->count(), $libraryEntries->pluck('day_of_week')->unique()->count(), 'max 1 library period per day');
        }

        // No clash: the two SSS2 arms' library slots must never share a
        // (day, period) — only one physical library exists.
        $slotsA = TimetableEntry::where('class_arm_id', $sss2ArmA->id)->where('subject_id', $library->id)
            ->get()->map(fn ($e) => $e->day_of_week.'|'.$e->timetable_period_id);
        $slotsB = TimetableEntry::where('class_arm_id', $sss2ArmB->id)->where('subject_id', $library->id)
            ->get()->map(fn ($e) => $e->day_of_week.'|'.$e->timetable_period_id);
        $this->assertEmpty($slotsA->intersect($slotsB), 'the two SSS2 arms must never book the library at the same time');

        // No staff double-booking anywhere in the whole populated week.
        $allEntries = TimetableEntry::whereNotNull('staff_id')->get();
        $byStaffSlot = $allEntries->groupBy(fn ($e) => $e->staff_id.'|'.$e->day_of_week.'|'.$e->timetable_period_id);
        foreach ($byStaffSlot as $key => $entries) {
            $this->assertLessThanOrEqual(1, $entries->pluck('class_arm_id')->unique()->count(), "staff conflict at {$key}");
        }
        app(TenantManager::class)->forget();
    }
}
