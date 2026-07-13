<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The full Student and Parent Portal (user request 2026-07-13: "BUILD THE
 * FULL STUDENT AND PARENT PORTAL"): a student's own login, family-scoped
 * data access (a parent sees only their linked children, a student sees
 * only themselves), and the restricted nav both roles now get, matching
 * the same shape TeacherScope already established for class/subject
 * teachers.
 */
class StudentParentPortalTest extends TestCase
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

    protected function schoolWithMember(string $roleSlug, string $slug, array $userAttrs = []): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $user = User::factory()->create($userAttrs);
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    /** An admin can create a student's own login, mirroring the guardian pattern exactly. */
    public function test_admin_can_create_a_students_login(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'portalone');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$studentId}/login", [
            'email' => 'ada.obi@example.com',
        ])->assertRedirect()->assertSessionHas('status');

        app(TenantManager::class)->use($school);
        $updated = Student::find($studentId);
        app(TenantManager::class)->forget();

        $this->assertNotNull($updated->user_id);
        $this->assertSame('ada.obi@example.com', $updated->email);

        $loginUser = User::find($updated->user_id);
        $this->assertTrue($loginUser->isMemberOf($school));
    }

    /** A student with exactly one linked record is sent straight to their own profile, not a list. */
    public function test_a_student_visiting_students_is_redirected_straight_to_their_own_profile(): void
    {
        [$school, $studentUser] = $this->schoolWithMember('student', 'portaltwo');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'user_id' => $studentUser->id]);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($studentUser)->get("http://{$school->slug}.localhost/students")
            ->assertRedirect(route('school.students.show', ['school' => $school->slug, 'studentId' => $studentId]));
    }

    /** A student can view their own report, but a different student's page 404s, not 403s (never confirm it exists). */
    public function test_a_student_can_view_their_own_report_but_not_another_students(): void
    {
        [$school, $studentUser] = $this->schoolWithMember('student', 'portalthree');

        app(TenantManager::class)->use($school);
        $own = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'user_id' => $studentUser->id]);
        $other = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        $ownId = $own->id;
        $otherId = $other->id;
        app(TenantManager::class)->forget();

        $this->actingAs($studentUser)->get("http://{$school->slug}.localhost/students/{$ownId}")
            ->assertOk()->assertSee('Ada Obi');

        $this->actingAs($studentUser)->get("http://{$school->slug}.localhost/students/{$otherId}")
            ->assertNotFound();
    }

    /** A parent sees a list of only their own linked children. */
    public function test_a_parent_sees_only_their_linked_children(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portalfour');

        app(TenantManager::class)->use($school);
        $ownChild = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $otherChild = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        StudentGuardian::create(['student_id' => $ownChild->id, 'name' => 'Mrs. Obi', 'guardian_user_id' => $parentUser->id]);
        $otherChildId = $otherChild->id;
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students")
            ->assertOk()->assertSee('Ada Obi')->assertDontSee('Bode Ade');

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students/{$otherChildId}")
            ->assertNotFound();
    }

    /** A parent with no linked children yet sees a helpful empty state, not an error. */
    public function test_a_parent_with_no_linked_children_sees_a_helpful_message(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portalfive');

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students")
            ->assertOk()->assertSee('No child is linked to your account yet');
    }

    /** A parent/student never sees admin-only nav items, but does see their own portal link. */
    public function test_a_parent_does_not_see_admin_only_nav_items(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portalsix');

        $response = $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk()
            ->assertSee('My Children')
            ->assertDontSee('Staff Records', false)
            ->assertDontSee('Backups', false)
            ->assertDontSee('Landing Pages', false);
    }

    /** A parent/student cannot manage (edit) a student even within their own family scope, students.update was never granted. */
    public function test_a_parent_cannot_edit_their_childs_record(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portalseven');

        app(TenantManager::class)->use($school);
        $child = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Mrs. Obi', 'guardian_user_id' => $parentUser->id]);
        $childId = $child->id;
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students/{$childId}/edit")
            ->assertForbidden();
    }

    public function test_a_teacher_without_students_update_cannot_create_a_student_login(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'portaleight');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/students/{$studentId}/login", [
            'email' => 'ada.obi@example.com',
        ])->assertForbidden();
    }

    /** Resetting/toggling a student's login access, same as the guardian equivalent. */
    public function test_admin_can_reset_and_toggle_a_students_login(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'portalnine');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$studentId}/login", ['email' => 'ada.obi@example.com']);

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$studentId}/login/reset-password")
            ->assertRedirect()->assertSessionHas('status');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$studentId}/login/toggle-access")
            ->assertRedirect()->assertSessionHas('status');

        app(TenantManager::class)->use($school);
        $updated = Student::find($studentId);
        $pivotStatus = $school->users()->whereKey($updated->user_id)->first()->pivot->status;
        app(TenantManager::class)->forget();

        $this->assertSame('suspended', $pivotStatus);
    }
}
