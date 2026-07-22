<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Arm;
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
 * Staff Portal and Parent Portal (user directive 2026-07-16), each
 * browsed Section -> Class -> details, the same shape Student Portal
 * already uses. Parent Portal was previously wrongly aliased to the same
 * route as Student Portal ("WHY IS PARENT PORTAL HAVING SAME LINK AS
 * STUDENT PORTAL"), now its own real screen.
 */
class StaffParentDirectoryTest extends TestCase
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

    /** One section, one class with two arms, a class teacher/assistant, a subject teacher, and one student with a guardian. */
    protected function seedClassStructure(School $school): array
    {
        app(TenantManager::class)->use($school);

        $classTeacher = Staff::create(['staff_id' => 'STF/001', 'first_name' => 'Chidi', 'last_name' => 'Okoro', 'status' => 'active']);
        $assistant = Staff::create(['staff_id' => 'STF/002', 'first_name' => 'Ada', 'last_name' => 'Nwosu', 'status' => 'active']);
        $subjectTeacher = Staff::create(['staff_id' => 'STF/003', 'first_name' => 'Bola', 'last_name' => 'Adeyemi', 'status' => 'active']);
        $unassignedStaff = Staff::create(['staff_id' => 'STF/004', 'first_name' => 'Femi', 'last_name' => 'Bello', 'position' => 'Bursar', 'status' => 'active']);

        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create([
            'school_class_id' => $class->id, 'arm_id' => $arm->id,
            'class_teacher_staff_id' => $classTeacher->id, 'assistant_teacher_staff_id' => $assistant->id,
        ]);
        $subject = Subject::create(['name' => 'Mathematics']);
        TeachingAssignment::create(['staff_id' => $subjectTeacher->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id]);

        $student = Student::create(['admission_number' => 'ADM/1', 'first_name' => 'Kemi', 'last_name' => 'Ade', 'current_class_arm_id' => $classArm->id]);
        $student->guardians()->create(['name' => 'Mrs Ade', 'relationship' => 'Mother', 'phone' => '08000000000', 'is_primary' => true]);

        app(TenantManager::class)->forget();

        return [$class, $classArm, $classTeacher, $subjectTeacher, $unassignedStaff, $student];
    }

    public function test_staff_portal_index_lists_classes_and_their_class_teachers(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'spone');
        [$class, , $classTeacher] = $this->seedClassStructure($school);

        $response = $this->actingAs($owner)->get(route('school.staff.directory', ['school' => $school->slug]));

        $response->assertOk()->assertSee('Primary 1')->assertSee('Chidi Okoro');
    }

    public function test_staff_portal_lists_non_teaching_staff_separately(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'sptwo');
        [, , , , $unassignedStaff] = $this->seedClassStructure($school);

        $response = $this->actingAs($owner)->get(route('school.staff.directory', ['school' => $school->slug]));

        $response->assertOk()->assertSee('Non-teaching staff')->assertSee('Femi Bello');
    }

    public function test_staff_portal_class_page_shows_class_teacher_assistant_and_subject_teacher_with_links(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'spthree');
        [$class, , $classTeacher, $subjectTeacher] = $this->seedClassStructure($school);

        $response = $this->actingAs($owner)->get(route('school.staff.directory-class', ['school' => $school->slug, 'classId' => $class->id]));

        $response->assertOk()
            ->assertSee('Chidi Okoro')->assertSee('Ada Nwosu')->assertSee('Bola Adeyemi')->assertSee('Mathematics')
            ->assertSee(route('school.staff.show', ['school' => $school->slug, 'staffId' => $classTeacher->id]), false);
    }

    /** User directive 2026-07-22: "the class stream should be separated here under staff portal and parent portal too." */
    protected function seedTwoStreamClass(School $school): array
    {
        app(TenantManager::class)->use($school);

        $goldTeacher = Staff::create(['staff_id' => 'STF/010', 'first_name' => 'Gold', 'last_name' => 'Teacher', 'status' => 'active']);
        $silverTeacher = Staff::create(['staff_id' => 'STF/011', 'first_name' => 'Silver', 'last_name' => 'Teacher', 'status' => 'active']);

        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $gold = Arm::create(['name' => 'Gold']);
        $silver = Arm::create(['name' => 'Silver']);
        $goldArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $gold->id, 'class_teacher_staff_id' => $goldTeacher->id]);
        $silverArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $silver->id, 'class_teacher_staff_id' => $silverTeacher->id]);

        Student::create(['admission_number' => 'ADM/G1', 'first_name' => 'Goldie', 'last_name' => 'One', 'current_class_arm_id' => $goldArm->id]);
        Student::create(['admission_number' => 'ADM/S1', 'first_name' => 'Silva', 'last_name' => 'One', 'current_class_arm_id' => $silverArm->id]);

        app(TenantManager::class)->forget();

        return [$class, $goldArm, $silverArm];
    }

    public function test_staff_portal_index_shows_each_stream_as_its_own_row(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'spstream');
        $this->seedTwoStreamClass($school);

        $response = $this->actingAs($owner)->get(route('school.staff.directory', ['school' => $school->slug]));

        $response->assertOk()
            ->assertSee('Primary 1 Gold')->assertSee('Primary 1 Silver')
            ->assertSee('Gold Teacher')->assertSee('Silver Teacher');
    }

    public function test_parent_portal_index_shows_each_stream_as_its_own_row_with_its_own_count(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ppstream');
        $this->seedTwoStreamClass($school);

        $response = $this->actingAs($owner)->get(route('school.parents.directory', ['school' => $school->slug]));

        $response->assertOk()->assertSee('Primary 1 Gold')->assertSee('Primary 1 Silver');
    }

    public function test_parent_portal_index_lists_classes_with_student_counts(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ppone');
        [$class] = $this->seedClassStructure($school);

        $response = $this->actingAs($owner)->get(route('school.parents.directory', ['school' => $school->slug]));

        $response->assertOk()->assertSee('Primary 1')->assertSee('1'); // 1 student
    }

    public function test_parent_portal_class_page_shows_student_and_guardian_details(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'pptwo');
        [$class, , , , , $student] = $this->seedClassStructure($school);

        $response = $this->actingAs($owner)->get(route('school.parents.directory-class', ['school' => $school->slug, 'classId' => $class->id]));

        $response->assertOk()->assertSee('Kemi Ade')->assertSee('Mrs Ade')->assertSee('Mother')->assertSee('08000000000');
    }

    /** The existing guardian_visibility setting must still gate a scoped teacher's view here too, unchanged, not a second permission system. */
    public function test_parent_portal_respects_the_existing_guardian_visibility_setting(): void
    {
        [$school, $subjectTeacherUser] = $this->schoolWithMember('subject-teacher', 'ppthree');
        [$class] = $this->seedClassStructure($school);
        // A subject teacher holds no parents.* grant by default, give the
        // same extra-access override the real Staff Extra Access screen
        // would grant, so this test reaches the guardian_visibility gate
        // itself, not the earlier parents.view permission check.
        $this->rbac->grantModuleOverride($school, $subjectTeacherUser, 'parents');

        $response = $this->actingAs($subjectTeacherUser)->get(route('school.parents.directory-class', ['school' => $school->slug, 'classId' => $class->id]));

        $response->assertOk()->assertSee('Restricted')->assertDontSee('Mrs Ade');
    }

    public function test_parent_portal_and_student_portal_no_longer_share_the_same_link(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ppfour');

        $this->assertNotSame(
            route('school.parents.directory', ['school' => $school->slug]),
            route('school.students', ['school' => $school->slug]),
        );
    }

    /** User report 2026-07-21: "parent portal not showing the all parent list like we have under the staff portal page for admin." */
    public function test_parent_portal_links_to_a_full_parent_list(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ppfive');
        $this->seedClassStructure($school);

        $response = $this->actingAs($owner)->get(route('school.parents.directory', ['school' => $school->slug]));

        $response->assertOk()->assertSee(route('school.parents.all', ['school' => $school->slug]), false);
    }

    public function test_full_parent_list_shows_every_guardian_and_supports_search(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ppsix');
        [, , , , , $student] = $this->seedClassStructure($school);

        $response = $this->actingAs($owner)->get(route('school.parents.all', ['school' => $school->slug]));
        $response->assertOk()->assertSee('Mrs Ade')->assertSee('Mother')
            ->assertSee(route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id]), false);

        $noMatch = $this->actingAs($owner)->get(route('school.parents.all', ['school' => $school->slug, 'q' => 'Nobody']));
        $noMatch->assertOk()->assertDontSee('Mrs Ade');
    }

    /** User report 2026-07-21: "Staff and parent list does not have the sort and filter option." */
    public function test_full_parent_list_can_be_filtered_by_class(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ppeight');
        [, $classArm] = $this->seedClassStructure($school);

        app(TenantManager::class)->use($school);
        $otherSection = Section::create(['name' => 'Secondary', 'sequence' => 2]);
        $otherClass = SchoolClass::create(['section_id' => $otherSection->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $otherArm = Arm::create(['name' => 'B']);
        $otherClassArm = ClassArm::create(['school_class_id' => $otherClass->id, 'arm_id' => $otherArm->id]);
        $otherStudent = Student::create(['admission_number' => 'ADM/2', 'first_name' => 'Tolu', 'last_name' => 'Bala', 'current_class_arm_id' => $otherClassArm->id]);
        $otherStudent->guardians()->create(['name' => 'Mr Bala', 'relationship' => 'Father', 'phone' => '08099999999']);
        app(TenantManager::class)->forget();

        $filtered = $this->actingAs($owner)->get(route('school.parents.all', ['school' => $school->slug, 'class_arm_id' => $classArm->id]));

        $filtered->assertOk()->assertSee('Mrs Ade')->assertDontSee('Mr Bala');
    }

    public function test_full_parent_list_respects_the_existing_guardian_visibility_setting(): void
    {
        [$school, $subjectTeacherUser] = $this->schoolWithMember('subject-teacher', 'ppseven');
        $this->seedClassStructure($school);
        $this->rbac->grantModuleOverride($school, $subjectTeacherUser, 'parents');

        $response = $this->actingAs($subjectTeacherUser)->get(route('school.parents.all', ['school' => $school->slug]));

        $response->assertOk()->assertSee('restricted for your role')->assertDontSee('Mrs Ade');
    }
}
