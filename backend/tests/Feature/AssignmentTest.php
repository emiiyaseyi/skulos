<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Arm;
use App\Models\Tenant\Assignment;
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
 * Assignments (Dashboard Design Cross-Check, scope decision 2026-07-15:
 * "Skip Behaviour entirely for now, build Assignments only"). A teacher
 * posts homework for one subject/class arm; students and parents see it
 * on their own dashboards, scoped to their own class/family only.
 */
class AssignmentTest extends TestCase
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

    public function test_an_owner_can_post_an_assignment(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'asgone');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 4', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $subject = Subject::create(['name' => 'Mathematics', 'code' => 'MTH']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.assignments.store', ['school' => $school->slug]), [
            'title' => 'Fractions worksheet', 'subject_id' => $subject->id, 'class_arm_id' => $arm->id,
            'due_on' => now()->addDays(7)->toDateString(), 'description' => 'Complete exercises 1 to 10.',
        ])->assertRedirect(route('school.assignments.index', ['school' => $school->slug]));

        app(TenantManager::class)->use($school);
        $assignment = Assignment::where('title', 'Fractions worksheet')->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($assignment);
        $this->assertSame($subject->id, $assignment->subject_id);
        $this->assertSame($arm->id, $assignment->class_arm_id);
    }

    public function test_a_subject_teacher_cannot_post_to_a_class_they_do_not_teach(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'asgtwo');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $ownArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $otherArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);
        $subject = Subject::create(['name' => 'Basic Science', 'code' => 'BSC']);
        $staff = Staff::create(['staff_id' => 'STF/1', 'first_name' => 'S', 'last_name' => 'T', 'user_id' => $teacher->id]);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $subject->id, 'class_arm_id' => $ownArm->id]);
        app(TenantManager::class)->forget();

        // Their own class: allowed.
        $this->actingAs($teacher)->post(route('school.assignments.store', ['school' => $school->slug]), [
            'title' => 'Mine', 'subject_id' => $subject->id, 'class_arm_id' => $ownArm->id,
        ])->assertRedirect();

        // A class they do not teach: 404 (not 403, matching the scoping
        // pattern that never leaks whether the row exists).
        $this->actingAs($teacher)->post(route('school.assignments.store', ['school' => $school->slug]), [
            'title' => 'Not mine', 'subject_id' => $subject->id, 'class_arm_id' => $otherArm->id,
        ])->assertNotFound();

        app(TenantManager::class)->use($school);
        $this->assertSame(1, Assignment::count());
        app(TenantManager::class)->forget();
    }

    public function test_a_student_only_sees_assignments_for_their_own_class_arm(): void
    {
        [$school, $studentUser] = $this->schoolWithMember('student', 'asgthree');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 5', 'sequence' => 1]);
        $myArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $otherArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);
        $subject = Subject::create(['name' => 'English', 'code' => 'ENG']);
        Student::create(['admission_number' => 'ST1', 'first_name' => 'My', 'last_name' => 'Student', 'current_class_arm_id' => $myArm->id, 'user_id' => $studentUser->id]);

        Assignment::create(['title' => 'My homework', 'subject_id' => $subject->id, 'class_arm_id' => $myArm->id, 'due_on' => now()->addDays(3)]);
        Assignment::create(['title' => 'Other class homework', 'subject_id' => $subject->id, 'class_arm_id' => $otherArm->id, 'due_on' => now()->addDays(3)]);
        app(TenantManager::class)->forget();

        $data = app(RoleDashboard::class)->build($studentUser, $school);
        $titles = collect($data['panel']['upcomingAssignments'])->pluck('title');

        $this->assertTrue($titles->contains('My homework'));
        $this->assertFalse($titles->contains('Other class homework'), 'a student must not see another class arm\'s assignment');

        // The index screen enforces the same scoping, not just the dashboard.
        $this->actingAs($studentUser)->get(route('school.assignments.index', ['school' => $school->slug]))
            ->assertOk()
            ->assertSee('My homework')
            ->assertDontSee('Other class homework');
    }

    public function test_a_parent_sees_upcoming_assignments_for_their_children(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'asgfour');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 6', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $subject = Subject::create(['name' => 'Maths', 'code' => 'MTH']);
        $child = Student::create(['admission_number' => 'CH1', 'first_name' => 'Child', 'last_name' => 'One', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parentUser->id, 'is_primary' => true]);
        Assignment::create(['title' => 'Reading log', 'subject_id' => $subject->id, 'class_arm_id' => $arm->id, 'due_on' => now()->addDays(2)]);
        app(TenantManager::class)->forget();

        $data = app(RoleDashboard::class)->build($parentUser, $school);
        $titles = collect($data['panel']['upcomingAssignments'])->pluck('title');
        $this->assertTrue($titles->contains('Reading log'));
    }

    public function test_a_student_cannot_reach_the_create_form(): void
    {
        [$school, $studentUser] = $this->schoolWithMember('student', 'asgfive');

        $this->actingAs($studentUser)->get(route('school.assignments.create', ['school' => $school->slug]))->assertForbidden();
    }
}
