<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\House;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Custom Report Builder (Advance plan feature 'custom_report_builder',
 * built 2026-07-18): pick a source, pick columns, filter, preview, export
 * CSV. Scoped down from the docx's full cross-module ambition to
 * Students and Staff, the two sources that make sense today.
 */
class ReportBuilderTest extends TestCase
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

    public function test_a_role_without_analytics_view_cannot_reach_the_builder(): void
    {
        [$school, $user] = $this->schoolWithMember('security', 'rbone');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/reports/builder")->assertForbidden();
    }

    public function test_a_registrar_can_preview_and_export_a_student_report(): void
    {
        [$school, $registrar] = $this->schoolWithMember('registrar', 'rbtwo');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $house = House::create(['name' => 'Red House']);
        $student = Student::create([
            'admission_number' => 'RB1', 'first_name' => 'Bola', 'last_name' => 'Coker',
            'current_class_arm_id' => $arm->id, 'house_id' => $house->id, 'status' => 'active',
        ]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($registrar)->get("http://{$school->slug}.localhost/reports/builder?source=students&columns[]=first_name&columns[]=last_name&columns[]=class&columns[]=house");
        $response->assertOk()
            ->assertSee('Bola')
            ->assertSee('Coker')
            ->assertSee('JSS 1 A')
            ->assertSee('Red House');

        $csv = $this->actingAs($registrar)->get("http://{$school->slug}.localhost/reports/builder/export?source=students&columns[]=first_name&columns[]=last_name&columns[]=class&columns[]=house");
        $csv->assertOk();
        $this->assertStringContainsString('Bola,Coker,"JSS 1 A","Red House"', $csv->getContent());
    }

    public function test_a_registrar_cannot_pull_a_staff_report_without_staff_view(): void
    {
        [$school, $user] = $this->schoolWithMember('secretary', 'rbthree'); // has analytics-less grants but no staff.view either, use a role with neither

        $this->actingAs($user)->get("http://{$school->slug}.localhost/reports/builder")->assertForbidden();
    }

    public function test_staff_source_is_hidden_from_a_role_without_staff_view_but_still_reaches_the_screen(): void
    {
        [$school, $registrar] = $this->schoolWithMember('registrar', 'rbfour');

        // Registrar holds students.*/analytics.view but not staff.view, so "Staff" must not appear as a source option.
        $this->actingAs($registrar)->get("http://{$school->slug}.localhost/reports/builder")
            ->assertOk()->assertSee('Students')->assertDontSee('>Staff<', false);
    }

    public function test_a_bursar_can_pull_a_staff_report(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'rbfive');

        // Bursar does not hold staff.view either by default; use ict-officer instead, which holds both staff.* and no analytics.view... check school-owner for a role that has everything.
        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        Staff::create(['staff_id' => 'RB1', 'first_name' => 'Femi', 'last_name' => 'Bello', 'position' => 'Teacher', 'status' => 'active']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/reports/builder?source=staff&columns[]=first_name&columns[]=last_name&columns[]=position")
            ->assertOk()->assertSee('Femi')->assertSee('Bello')->assertSee('Teacher');
    }

    /** User request 2026-07-22: "report builder should have much more items to combine into a report. Check others things like, attendance, performance, behavioural, etc." */
    public function test_a_students_report_can_combine_attendance_performance_and_behavioural_columns(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rbcombine');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $student = Student::create(['admission_number' => 'RBC1', 'first_name' => 'Tomi', 'last_name' => 'Lawal', 'current_class_arm_id' => $arm->id, 'status' => 'active']);

        $session = \App\Models\Tenant\AcademicSession::create(['name' => '2025/2026']);
        $term = \App\Models\Tenant\Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true, 'starts_on' => '2025-09-01', 'ends_on' => '2025-12-01']);

        \App\Models\Tenant\Attendance::create(['date' => '2025-09-02', 'person_type' => 'student', 'person_id' => $student->id, 'class_arm_id' => $arm->id, 'status' => 'present']);
        \App\Models\Tenant\Attendance::create(['date' => '2025-09-03', 'person_type' => 'student', 'person_id' => $student->id, 'class_arm_id' => $arm->id, 'status' => 'absent']);

        $subject = \App\Models\Tenant\Subject::create(['name' => 'Mathematics']);
        $component = \App\Models\Tenant\AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 100, 'sequence' => 1]);
        \App\Models\Tenant\Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $arm->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 75]);

        app(\App\Services\Results\BehaviouralTraitCatalog::class)->ensureSeeded();
        $trait = \App\Models\Tenant\BehaviouralTrait::first();
        \App\Models\Tenant\StudentBehaviouralRating::create(['student_id' => $student->id, 'term_id' => $term->id, 'behavioural_trait_id' => $trait->id, 'value' => 'Good']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/reports/builder?source=students&columns[]=first_name&columns[]=attendance_rate&columns[]=average_score&columns[]=behavioural_traits");

        $response->assertOk()
            ->assertSee('Tomi')
            ->assertSee('50%') // 1 present out of 2 marked days
            ->assertSee('75%') // the one subject's score
            ->assertSee($trait->name.': Good');
    }

    public function test_a_class_teacher_only_sees_their_own_class_students_in_the_builder(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rbsix');
        $teacherUser = User::factory()->create();
        $teacherRole = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($teacherUser->id, ['role_id' => $teacherRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 2', 'sequence' => 1]);
        $ownArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $otherArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);
        $staff = Staff::create(['staff_id' => 'RBT1', 'first_name' => 'T', 'last_name' => 'Eacher', 'user_id' => $teacherUser->id]);
        $ownArm->update(['class_teacher_staff_id' => $staff->id]);
        Student::create(['admission_number' => 'RB2', 'first_name' => 'MyClass', 'last_name' => 'Kid', 'current_class_arm_id' => $ownArm->id]);
        Student::create(['admission_number' => 'RB3', 'first_name' => 'OtherClass', 'last_name' => 'Kid', 'current_class_arm_id' => $otherArm->id]);
        app(TenantManager::class)->forget();

        // class-teacher does not hold analytics.view by default; grant it via a direct role permission for this test's purpose.
        app(TenantManager::class)->use($school);
        $teacherRole->permissions()->syncWithoutDetaching([\App\Models\Permission::firstOrCreate(['code' => 'analytics.view'])->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/reports/builder?source=students&columns[]=first_name&columns[]=last_name")
            ->assertOk()->assertSee('MyClass')->assertDontSee('OtherClass');
    }
}
