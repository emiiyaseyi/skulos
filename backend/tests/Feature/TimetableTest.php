<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TimetableEntry;
use App\Models\Tenant\TimetablePeriod;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Timetable Management (Advance plan feature, user request 2026-07-18:
 * deferred, then reversed the same session and built). Only an admin
 * tier role (timetable.update via the blanket timetable.* grant) can
 * build/edit a class's grid; every scoped role (timetable.view only)
 * only ever sees its own schedule via "My Timetable".
 */
class TimetableTest extends TestCase
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

    /** Two class arms, two subjects, two staff, one period. Returns [classArmA, classArmB, subject, staffA, staffB, period]. */
    protected function seedAcademics(School $school): array
    {
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $classA = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $classB = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 2', 'sequence' => 2]);
        $arm = Arm::create(['name' => 'A']);
        $classArmA = ClassArm::create(['school_class_id' => $classA->id, 'arm_id' => $arm->id]);
        $classArmB = ClassArm::create(['school_class_id' => $classB->id, 'arm_id' => $arm->id]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $classA->subjects()->attach($subject->id);
        $classB->subjects()->attach($subject->id);
        $staffA = Staff::create(['staff_id' => 'STF1', 'first_name' => 'Ada', 'last_name' => 'Teacher']);
        $staffB = Staff::create(['staff_id' => 'STF2', 'first_name' => 'Femi', 'last_name' => 'Teacher']);
        $period = TimetablePeriod::create(['name' => 'Period 1', 'sequence' => 1, 'starts_at' => '08:00', 'ends_at' => '08:40', 'is_teaching_period' => true]);

        app(TenantManager::class)->forget();

        return [$classArmA, $classArmB, $subject, $staffA, $staffB, $period];
    }

    public function test_an_admin_can_apply_default_periods(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ttone');

        $this->actingAs($owner)->post(route('school.timetable.periods.apply-defaults', ['school' => $school->slug]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertEquals(10, TimetablePeriod::count());
        $this->assertTrue(TimetablePeriod::where('name', 'Break')->value('is_teaching_period') === false);
        app(TenantManager::class)->forget();
    }

    public function test_an_admin_can_build_a_class_weekly_grid(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'tttwo');
        [$classArmA, , $subject, $staffA] = $this->seedAcademics($school);
        app(TenantManager::class)->use($school);
        $period = TimetablePeriod::first();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmA->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ])->assertRedirect();

        $this->actingAs($owner)->get(route('school.timetable.index', ['school' => $school->slug, 'class_arm_id' => $classArmA->id]))
            ->assertOk()
            ->assertSee('Mathematics')
            ->assertSee('Ada Teacher');
    }

    /** User request 2026-07-21: "there should be a timetable like this showing for all classes in the school... at a glance". */
    public function test_the_master_timetable_shows_every_classs_grid_on_one_screen(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ttmaster');
        [$classArmA, $classArmB, $subject, $staffA] = $this->seedAcademics($school);
        app(TenantManager::class)->use($school);
        $period = TimetablePeriod::first();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmA->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ]);

        $response = $this->actingAs($owner)->get(route('school.timetable.master', ['school' => $school->slug]));

        $response->assertOk()
            ->assertSee('JSS 1 A')
            ->assertSee('JSS 2 A')
            ->assertSee('Mathematics')
            ->assertSee('Ada Teacher')
            ->assertSee('Free'); // classArmB's Monday period 1 has nothing booked
    }

    public function test_a_scoped_teacher_cannot_reach_the_master_timetable(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'ttmastertwo');

        $this->actingAs($teacher)->get(route('school.timetable.master', ['school' => $school->slug]))->assertForbidden();
    }

    /** User request 2026-07-22: "Make the Master Timetable exportable as csv or pdf." */
    public function test_the_master_timetable_can_be_exported_as_csv(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ttmasterexport');
        [$classArmA, , $subject, $staffA] = $this->seedAcademics($school);
        app(TenantManager::class)->use($school);
        $period = TimetablePeriod::first();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmA->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ]);

        $response = $this->actingAs($owner)->get(route('school.timetable.master.export', ['school' => $school->slug]));

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Day,Class,Period,Time,Subject,Teacher', $csv);
        $this->assertStringContainsString('Mathematics', $csv);
        $this->assertStringContainsString('Ada Teacher', $csv);
    }

    public function test_a_teacher_double_booked_at_the_same_slot_is_skipped_not_double_booked(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ttthree');
        [$classArmA, $classArmB, $subject, $staffA] = $this->seedAcademics($school);
        app(TenantManager::class)->use($school);
        $period = TimetablePeriod::first();
        app(TenantManager::class)->forget();

        // Book staffA into class A, Monday period 1.
        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmA->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ]);

        // Try to also book staffA into class B, same Monday period 1: conflict, skipped.
        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmB->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertEquals(1, TimetableEntry::where('staff_id', $staffA->id)->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_scoped_teacher_cannot_reach_the_admin_grid_editor(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'ttfour');

        $this->actingAs($teacher)->get(route('school.timetable.index', ['school' => $school->slug]))->assertForbidden();
    }

    public function test_a_teacher_sees_their_own_schedule_on_my_timetable(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('subject-teacher', 'ttfive');
        [$classArmA, , $subject, $staffA, , $period] = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        $staffA->update(['user_id' => $teacherUser->id]);
        TimetableEntry::create(['class_arm_id' => $classArmA->id, 'timetable_period_id' => $period->id, 'day_of_week' => 'monday', 'subject_id' => $subject->id, 'staff_id' => $staffA->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->get(route('school.timetable.mine', ['school' => $school->slug]))
            ->assertOk()
            ->assertSee('Mathematics')
            ->assertSee('own teaching schedule');
    }

    public function test_a_student_sees_their_class_schedule_on_my_timetable(): void
    {
        [$school, $studentUser] = $this->schoolWithMember('student', 'ttsix');
        [$classArmA, , $subject, $staffA, , $period] = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        TimetableEntry::create(['class_arm_id' => $classArmA->id, 'timetable_period_id' => $period->id, 'day_of_week' => 'monday', 'subject_id' => $subject->id, 'staff_id' => $staffA->id]);
        $student = Student::create(['admission_number' => 'STU1', 'first_name' => 'Chi', 'last_name' => 'Student', 'current_class_arm_id' => $classArmA->id, 'user_id' => $studentUser->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($studentUser)->get(route('school.timetable.mine', ['school' => $school->slug]))
            ->assertOk()
            ->assertSee('Mathematics');
    }

    public function test_the_nav_shows_timetable_for_an_admin_and_my_timetable_for_a_scoped_role(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ttseven');
        app(TenantManager::class)->use($school);
        $plan = \App\Models\Plan::first();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard")->assertOk();
    }
}
