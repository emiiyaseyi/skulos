<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A class or subject teacher should only ever see the classes/students
 * actually assigned to them, not the whole school (user directive
 * 2026-07-12, live QA on the subject-teacher role: "should only see the
 * class assigned to him with the students taking the subjects he takes").
 * Enforced by App\Services\Rbac\TeacherScope, applied in StudentController.
 */
class TeacherScopeTest extends TestCase
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

    /** Two classes, two students, one in each. Returns everything a test needs to wire up a teacher against one of them. */
    protected function twoClassesWithStudents(): array
    {
        $sectionA = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $classA = SchoolClass::create(['section_id' => $sectionA->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $armA = ClassArm::create(['school_class_id' => $classA->id, 'arm_id' => null]);
        $studentA = Student::create(['admission_number' => 'A/001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $armA->id]);

        $classB = SchoolClass::create(['section_id' => $sectionA->id, 'name' => 'Primary 2', 'sequence' => 2]);
        $armB = ClassArm::create(['school_class_id' => $classB->id, 'arm_id' => null]);
        $studentB = Student::create(['admission_number' => 'B/001', 'first_name' => 'Bode', 'last_name' => 'Ade', 'current_class_arm_id' => $armB->id]);

        return compact('armA', 'studentA', 'armB', 'studentB');
    }

    public function test_a_class_teacher_only_sees_students_in_their_own_class_arm(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'scopeone');

        app(TenantManager::class)->use($school);
        ['armA' => $armA, 'studentA' => $studentA, 'studentB' => $studentB] = $this->twoClassesWithStudents();
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tee', 'last_name' => 'Cher', 'position' => 'Class Teacher', 'status' => 'active', 'user_id' => $teacherUser->id]);
        $armA->update(['class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/students");
        $response->assertOk()->assertSee('Ada Obi')->assertDontSee('Bode Ade');

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/students/{$studentA->id}")->assertOk();
        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/students/{$studentB->id}")->assertNotFound();
    }

    public function test_a_subject_teacher_only_sees_students_in_classes_they_actually_teach(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('subject-teacher', 'scopetwo');

        app(TenantManager::class)->use($school);
        ['armA' => $armA, 'studentA' => $studentA, 'studentB' => $studentB] = $this->twoClassesWithStudents();
        $subject = Subject::create(['name' => 'Mathematics']);
        $staff = Staff::create(['staff_id' => 'STF/0002', 'first_name' => 'Sub', 'last_name' => 'Jek', 'position' => 'Subject Teacher', 'status' => 'active', 'user_id' => $teacherUser->id]);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $subject->id, 'class_arm_id' => $armA->id]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/students");
        $response->assertOk()->assertSee('Ada Obi')->assertDontSee('Bode Ade');

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/students/{$studentA->id}")->assertOk();
        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/students/{$studentB->id}")->assertNotFound();
    }

    public function test_an_unrestricted_role_still_sees_every_student(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'scopethree');

        app(TenantManager::class)->use($school);
        $this->twoClassesWithStudents();
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/students?q=A");
        $response->assertOk()->assertSee('Ada Obi');
        $response2 = $this->actingAs($owner)->get("http://{$school->slug}.localhost/students?q=Bode");
        $response2->assertOk()->assertSee('Bode Ade');
    }

    /** A staff extra-access grant of the "students" module (built 2026-07-12) lifts the default scope entirely, per "except a new feature was given to them." */
    public function test_a_scoped_teacher_granted_the_students_module_override_sees_everyone(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'scopefour');

        app(TenantManager::class)->use($school);
        ['armA' => $armA] = $this->twoClassesWithStudents();
        $staff = Staff::create(['staff_id' => 'STF/0003', 'first_name' => 'Tee', 'last_name' => 'Cher', 'position' => 'Class Teacher', 'status' => 'active', 'user_id' => $teacherUser->id]);
        $armA->update(['class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        $this->rbac->grantModuleOverride($school, $teacherUser, 'students');

        $response = $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/students?q=Bode");
        $response->assertOk()->assertSee('Bode Ade');
    }

    /** A class teacher holds students.update, but a bulk action must still be silently limited to their own scoped students, an id for another class is never acted on. */
    public function test_a_class_teachers_bulk_action_never_touches_a_student_outside_their_scope(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'scopefive');

        app(TenantManager::class)->use($school);
        ['armA' => $armA, 'studentA' => $studentA, 'studentB' => $studentB] = $this->twoClassesWithStudents();
        $staff = Staff::create(['staff_id' => 'STF/0004', 'first_name' => 'Tee', 'last_name' => 'Cher', 'position' => 'Class Teacher', 'status' => 'active', 'user_id' => $teacherUser->id]);
        $armA->update(['class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->post("http://{$school->slug}.localhost/students/bulk-action", [
            'student_ids' => [$studentA->id, $studentB->id], 'action' => 'suspended',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame('suspended', Student::find($studentA->id)->status);
        $this->assertSame('active', Student::find($studentB->id)->status);
        app(TenantManager::class)->forget();
    }
}
