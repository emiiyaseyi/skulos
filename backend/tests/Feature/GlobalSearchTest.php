<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
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
 * Global Search (backlog item, "To Do List 2" doc: "one search bar, search
 * anything"). Covers what the product actually has records of: Students,
 * Staff, and Parents/Guardians. Each category respects its own view
 * permission.
 */
class GlobalSearchTest extends TestCase
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

    public function test_owner_can_search_students_staff_and_guardians(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'searchone');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'ADM/900', 'first_name' => 'Zenith', 'last_name' => 'Okoro']);
        Staff::create(['staff_id' => 'STF/900', 'first_name' => 'Zenith', 'last_name' => 'Balogun', 'position' => 'Teacher']);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Zenith Guardian', 'email' => 'zenith@example.com']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/search?q=Zenith")
            ->assertOk()
            ->assertSee('Zenith Okoro')
            ->assertSee('Zenith Balogun')
            ->assertSee('Zenith Guardian');
    }

    public function test_a_short_query_asks_for_more_characters_rather_than_erroring(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'searchtwo');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/search?q=A")
            ->assertOk()->assertSee('at least 2 characters');
    }

    public function test_a_role_without_staff_view_never_sees_staff_results(): void
    {
        [$school, $registrar] = $this->schoolWithMember('registrar', 'searchthree');

        app(TenantManager::class)->use($school);
        Staff::create(['staff_id' => 'STF/901', 'first_name' => 'Hidden', 'last_name' => 'Person', 'position' => 'Teacher']);
        Student::create(['admission_number' => 'ADM/901', 'first_name' => 'Hidden', 'last_name' => 'Kid']);
        app(TenantManager::class)->forget();

        $this->actingAs($registrar)->get("http://{$school->slug}.localhost/search?q=Hidden")
            ->assertOk()
            ->assertSee('Hidden Kid')
            ->assertDontSee('Hidden Person');
    }

    public function test_search_input_appears_in_the_appbar_for_school_portal_pages(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'searchfour');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('Search students, staff, parents');
    }

    /** Gives $user a real Staff row scoped (via TeachingAssignment) to $armId, the same shape TeacherScope relies on. */
    protected function makeScopedTeacher(School $school, User $user, string $staffId, int $armId, ?int $subjectId = null): void
    {
        $subjectId ??= Subject::create(['name' => 'General Studies'])->id;
        $staff = Staff::create(['staff_id' => $staffId, 'first_name' => 'Teacher', 'last_name' => $staffId, 'user_id' => $user->id]);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $subjectId, 'class_arm_id' => $armId]);
    }

    /** User report 2026-07-22: "when subject teacher use the search for student/staff/parent function, it should blur the parent contact details if the admin has set it." */
    public function test_a_subject_teacher_sees_guardian_names_but_not_contact_details_by_default(): void
    {
        [$school, $subjectTeacher] = $this->schoolWithMember('subject-teacher', 'searchfive');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $student = Student::create(['admission_number' => 'ADM/902', 'first_name' => 'Restricted', 'last_name' => 'Kid', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Restricted Guardian', 'email' => 'restricted-guardian@example.com']);
        app(TenantManager::class)->forget();

        $this->makeScopedTeacher($school, $subjectTeacher, 'STF/902', $arm->id);

        $this->actingAs($subjectTeacher)->get("http://{$school->slug}.localhost/search?q=Restricted")
            ->assertOk()
            ->assertSee('Restricted Guardian')
            ->assertSee('Restricted', false) // the masked contact cell
            ->assertDontSee('restricted-guardian@example.com');
    }

    /** Same scoped subject teacher, but the school has opted every teacher into guardian contact visibility. */
    public function test_a_subject_teacher_sees_real_contact_details_when_the_school_allows_it(): void
    {
        [$school, $subjectTeacher] = $this->schoolWithMember('subject-teacher', 'searchsix');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $student = Student::create(['admission_number' => 'ADM/903', 'first_name' => 'Visible', 'last_name' => 'Kid', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Visible Guardian', 'email' => 'visible-guardian@example.com']);
        Setting::put('guardian_visibility', 'both');
        app(TenantManager::class)->forget();

        $this->makeScopedTeacher($school, $subjectTeacher, 'STF/903', $arm->id);

        $this->actingAs($subjectTeacher)->get("http://{$school->slug}.localhost/search?q=Visible")
            ->assertOk()->assertSee('visible-guardian@example.com');
    }

    /** A class teacher always sees guardian contact details, the setting only ever narrows subject teachers. */
    public function test_a_class_teacher_always_sees_guardian_contact_details(): void
    {
        [$school, $classTeacher] = $this->schoolWithMember('class-teacher', 'searchseven');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $student = Student::create(['admission_number' => 'ADM/904', 'first_name' => 'Classvisible', 'last_name' => 'Kid', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Classvisible Guardian', 'email' => 'classvisible-guardian@example.com']);
        app(TenantManager::class)->forget();

        $this->makeScopedTeacher($school, $classTeacher, 'STF/904', $arm->id);

        $this->actingAs($classTeacher)->get("http://{$school->slug}.localhost/search?q=Classvisible")
            ->assertOk()->assertSee('classvisible-guardian@example.com');
    }
}
