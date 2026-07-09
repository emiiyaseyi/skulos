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
use App\Models\Tenant\TeachingAssignment;
use App\Models\User;
use App\Services\Dashboard\RoleDashboard;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The dashboard shows different figures depending on who is looking (user
 * request 2026-07-09): school owners/admins get school wide counts,
 * class teachers get their own class (and a class assistant gets the same
 * read only), a subject teacher gets only what they actually teach,
 * parents get their children, students get their own snapshot.
 */
class RoleDashboardTest extends TestCase
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

    public function test_admin_sees_school_wide_figures(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'rdone');
        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'A1', 'first_name' => 'A', 'last_name' => 'B']);
        \App\Models\Tenant\Campus::create(['name' => 'Main', 'is_main' => true]);

        $data = app(RoleDashboard::class)->build($admin, $school);
        app(TenantManager::class)->forget();

        $this->assertSame('admin', $data['kind']);
        $labels = array_column($data['figs'], 'l');
        $this->assertContains('Campuses', $labels);
        $this->assertContains('Classes', $labels);
    }

    public function test_class_teacher_sees_only_their_own_class(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'rdtwo');
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Basic 3', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $staff = Staff::create(['staff_id' => 'STF/1', 'first_name' => 'F', 'last_name' => 'T', 'user_id' => $teacherUser->id]);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id, 'class_teacher_staff_id' => $staff->id]);

        Student::create(['admission_number' => 'S1', 'first_name' => 'Boy', 'last_name' => 'One', 'gender' => 'male', 'current_class_arm_id' => $classArm->id]);
        Student::create(['admission_number' => 'S2', 'first_name' => 'Girl', 'last_name' => 'One', 'gender' => 'female', 'current_class_arm_id' => $classArm->id]);
        Student::create(['admission_number' => 'S3', 'first_name' => 'Elsewhere', 'last_name' => 'Kid', 'gender' => 'male']); // not in this class arm

        $data = app(RoleDashboard::class)->build($teacherUser, $school);
        app(TenantManager::class)->forget();

        $this->assertSame('class_teacher', $data['kind']);
        $this->assertSame('class_overview', $data['panel']['type']);
        $this->assertSame('Basic 3 A', $data['panel']['class_label']);
        $this->assertSame(1, $data['panel']['boys']);
        $this->assertSame(1, $data['panel']['girls']);
        $studentsFig = collect($data['figs'])->firstWhere('l', 'My Students');
        $this->assertSame('2', $studentsFig['n']);
    }

    public function test_subject_teacher_sees_only_their_teaching_assignments(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('subject-teacher', 'rdthree');
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'SS 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $subject = Subject::create(['name' => 'Chemistry']);
        $staff = Staff::create(['staff_id' => 'STF/2', 'first_name' => 'S', 'last_name' => 'T', 'user_id' => $teacherUser->id]);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id]);
        Student::create(['admission_number' => 'S9', 'first_name' => 'One', 'last_name' => 'Kid', 'current_class_arm_id' => $classArm->id]);

        $data = app(RoleDashboard::class)->build($teacherUser, $school);
        app(TenantManager::class)->forget();

        $this->assertSame('subject_teacher', $data['kind']);
        $this->assertSame('Chemistry', $data['panel']['assignments'][0]['subject']);
        $subjectsFig = collect($data['figs'])->firstWhere('l', 'My Subjects');
        $this->assertSame('1', $subjectsFig['n']);
    }

    public function test_parent_sees_their_linked_children(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'rdfour');
        app(TenantManager::class)->use($school);

        $student = Student::create(['admission_number' => 'S5', 'first_name' => 'Linked', 'last_name' => 'Child']);
        StudentGuardian::create([
            'student_id' => $student->id, 'name' => 'Parent Name', 'relationship' => 'Mother',
            'guardian_user_id' => $parentUser->id, 'is_primary' => true,
        ]);

        $data = app(RoleDashboard::class)->build($parentUser, $school);
        app(TenantManager::class)->forget();

        $this->assertSame('parent', $data['kind']);
        $this->assertCount(1, $data['panel']['children']);
        $this->assertSame('Linked Child', $data['panel']['children'][0]['name']);
    }

    public function test_class_teacher_without_a_class_assignment_gets_an_empty_state(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'rdfive');

        app(TenantManager::class)->use($school);
        $data = app(RoleDashboard::class)->build($teacherUser, $school);
        app(TenantManager::class)->forget();

        $this->assertSame('class_teacher', $data['kind']);
        $this->assertSame('no_class', $data['panel']['type']);
    }

    public function test_dashboard_route_renders_role_specific_figures(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'rdsix');
        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Basic 5', 'sequence' => 1]);
        $staff = Staff::create(['staff_id' => 'STF/3', 'first_name' => 'C', 'last_name' => 'T', 'user_id' => $teacherUser->id]);
        ClassArm::create(['school_class_id' => $class->id, 'class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('My Students')->assertSee('Basic 5');
    }
}
