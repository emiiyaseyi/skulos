<?php

namespace Tests\Feature;

use App\Mail\AccountCreatedMail;
use App\Models\School;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Parent Portal (user request 2026-07-12: "lets move to staff and parent
 * portal"). RoleDashboard::parent() has rendered a real dashboard since
 * 2026-07-09 once a guardian is linked via StudentGuardian.guardian_user_id,
 * but nothing anywhere could actually create that link, only the demo
 * seeder's synthetic guardians existed. This is that missing screen.
 */
class ParentPortalTest extends TestCase
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

    protected function seedGuardian(School $school): int
    {
        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $guardian = StudentGuardian::create(['student_id' => $student->id, 'name' => 'Mama Obi', 'relationship' => 'Mother']);
        $id = $guardian->id;
        app(TenantManager::class)->forget();

        return $id;
    }

    /**
     * The student profile page renders StudentGuardian::user() (a cross
     * connection belongsTo from a tenant model to the platform User
     * model). Same real bug as Staff::user(), found live 2026-07-12: a
     * plain belongsTo() here throws "no such table: users" because
     * Eloquent inherits the tenant connection onto User. This test opens
     * the real page with a guardian login already created so it cannot
     * regress silently again.
     */
    public function test_the_student_profile_page_renders_with_a_guardian_login_created(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'parfive');
        $guardianId = $this->seedGuardian($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/guardians/{$guardianId}/login", [
            'email' => 'mama.obi@example.com',
        ]);

        app(TenantManager::class)->use($school);
        $studentId = StudentGuardian::find($guardianId)->student_id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/students/{$studentId}")
            ->assertOk()
            ->assertSee('mama.obi@example.com');
    }

    public function test_owner_can_create_a_login_for_a_guardian_and_an_email_is_sent(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'parone');
        $guardianId = $this->seedGuardian($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/guardians/{$guardianId}/login", [
            'email' => 'mama.obi@example.com',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $guardian = StudentGuardian::find($guardianId);
        app(TenantManager::class)->forget();

        $this->assertNotNull($guardian->guardian_user_id);
        $loginUser = User::find($guardian->guardian_user_id);
        $this->assertSame('mama.obi@example.com', $loginUser->email);
        $this->assertTrue($school->users()->whereKey($loginUser->id)->exists());

        $role = $loginUser->roleIn($school);
        $this->assertSame('parent', $role->slug);
        Mail::assertSent(AccountCreatedMail::class, fn ($mail) => $mail->hasTo('mama.obi@example.com'));
    }

    public function test_the_new_parent_login_sees_their_real_dashboard(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'partwo');
        $guardianId = $this->seedGuardian($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/guardians/{$guardianId}/login", [
            'email' => 'mama.obi@example.com',
        ]);

        app(TenantManager::class)->use($school);
        $guardian = StudentGuardian::find($guardianId);
        app(TenantManager::class)->forget();
        $parentUser = User::find($guardian->guardian_user_id);

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('Ada Obi');
    }

    public function test_owner_can_reset_and_revoke_a_guardian_login(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'parthree');
        $guardianId = $this->seedGuardian($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/guardians/{$guardianId}/login", [
            'email' => 'mama.obi@example.com',
        ]);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/guardians/{$guardianId}/login/reset-password")
            ->assertRedirect();
        Mail::assertSent(\App\Mail\PasswordResetByAdminMail::class);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/guardians/{$guardianId}/login/toggle-access")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $guardian = StudentGuardian::find($guardianId);
        app(TenantManager::class)->forget();
        $status = $school->users()->whereKey($guardian->guardian_user_id)->first()->pivot->status;
        $this->assertSame('suspended', $status);
    }

    public function test_a_teacher_without_students_update_cannot_create_a_guardian_login(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'parfour');
        $guardianId = $this->seedGuardian($school);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/students/guardians/{$guardianId}/login", [
            'email' => 'x@example.com',
        ])->assertForbidden();
    }
}
