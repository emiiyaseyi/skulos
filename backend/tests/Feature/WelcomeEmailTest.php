<?php

namespace Tests\Feature;

use App\Mail\AccountCreatedMail;
use App\Models\School;
use App\Models\Tenant\Setting;
use App\Models\User;
use App\Services\Accounts\WelcomeEmailService;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Welcome email (user request 2026-07-26): "new user accounts get a
 * welcome email with login details, portal link, and a role specific
 * brief; admin can disable email notifications for a period under email
 * settings." Login details and the portal link already existed on
 * AccountCreatedMail (built 2026-07-12); this covers the new role brief
 * and the pause switch, plus reaching the auto-provisioned paths
 * (student, auto guardian) that never emailed at all before this.
 */
class WelcomeEmailTest extends TestCase
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

    /** Creates a bare Staff record and returns its id, the same precondition StaffPortalTest's own login tests use. */
    protected function createStaffRecord(School $school, string $staffIdCode = 'STF/0001'): int
    {
        app(TenantManager::class)->use($school);
        $staff = \App\Models\Tenant\Staff::create(['staff_id' => $staffIdCode, 'first_name' => 'Bola', 'last_name' => 'Adebayo', 'position' => 'Teacher', 'status' => 'active']);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        return $staffId;
    }

    public function test_the_welcome_email_includes_the_role_specific_brief(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'wetone');
        $staffId = $this->createStaffRecord($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/login", [
            'email' => 'bola.adebayo@example.com', 'role' => 'teacher',
        ])->assertRedirect();

        $expectedBrief = config('skulos.default_role_welcome_briefs.teacher');
        Mail::assertSent(AccountCreatedMail::class, function ($mail) use ($expectedBrief) {
            $rendered = $mail->render();

            return str_contains($rendered, e($expectedBrief));
        });
    }

    public function test_an_admin_can_override_a_roles_brief_and_it_is_used_in_the_next_welcome_email(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'wetwo');
        $staffId = $this->createStaffRecord($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/mail/welcome-emails", [
            'briefs' => ['teacher' => 'Custom teacher brief for this school.'],
        ])->assertRedirect();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/login", [
            'email' => 'bola2@example.com', 'role' => 'teacher',
        ]);

        Mail::assertSent(AccountCreatedMail::class, function ($mail) {
            return str_contains($mail->render(), 'Custom teacher brief for this school.');
        });
    }

    public function test_pausing_welcome_emails_suppresses_the_send(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'wethree');
        $staffId = $this->createStaffRecord($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/mail/welcome-emails", [
            'paused_until' => now()->addDay()->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/login", [
            'email' => 'bola3@example.com', 'role' => 'teacher',
        ]);

        Mail::assertNotSent(AccountCreatedMail::class);
    }

    public function test_resuming_welcome_emails_allows_sending_again(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'wefour');
        $staffId = $this->createStaffRecord($school);

        app(TenantManager::class)->use($school);
        app(WelcomeEmailService::class)->pauseUntil(now()->addDay()->toIso8601String());
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/mail/welcome-emails", [
            'resume_now' => '1',
        ])->assertRedirect();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/login", [
            'email' => 'bola4@example.com', 'role' => 'teacher',
        ]);

        Mail::assertSent(AccountCreatedMail::class);
    }

    public function test_a_guardian_with_a_real_email_gets_a_welcome_email_on_auto_provision(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'wefive');

        app(TenantManager::class)->use($school);
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id]);
        $student = \App\Models\Tenant\Student::create(['admission_number' => 'WE1', 'first_name' => 'Chidi', 'last_name' => 'Eze', 'current_class_arm_id' => $arm->id]);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/{$studentId}/guardians", [
            'name' => 'Ngozi Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '08011112222', 'relationship' => 'Mother',
        ])->assertRedirect();

        $expectedBrief = config('skulos.default_role_welcome_briefs.parent');
        Mail::assertSent(AccountCreatedMail::class, function ($mail) use ($expectedBrief) {
            return $mail->hasTo('ngozi.eze@example.com') && str_contains($mail->render(), e($expectedBrief));
        });
    }

    public function test_a_student_never_gets_a_welcome_email_since_they_have_no_real_email(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'wesix');

        app(TenantManager::class)->use($school);
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id]);
        $classArmId = $arm->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/create", [
            'admission_number' => 'WE2', 'first_name' => 'Amaka', 'last_name' => 'Nnamdi', 'current_class_arm_id' => $classArmId,
        ])->assertRedirect();

        Mail::assertNotSent(AccountCreatedMail::class);
    }

    public function test_a_guardian_added_by_phone_only_does_not_get_a_welcome_email(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'weseven');

        app(TenantManager::class)->use($school);
        Setting::put('parent_identifier', 'phone');
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id]);
        $student = \App\Models\Tenant\Student::create(['admission_number' => 'WE3', 'first_name' => 'Tunde', 'last_name' => 'Bello', 'current_class_arm_id' => $arm->id]);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/{$studentId}/guardians", [
            'name' => 'Bimpe Bello', 'phone' => '08033334444', 'relationship' => 'Mother',
        ])->assertRedirect();

        Mail::assertNotSent(AccountCreatedMail::class);
    }

    public function test_a_teacher_without_permission_cannot_update_welcome_email_settings(): void
    {
        [$school, $teacher] = $this->schoolWithMember('teacher', 'weeight');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/settings/mail/welcome-emails", [
            'briefs' => ['teacher' => 'Should not save.'],
        ])->assertForbidden();
    }

    public function test_the_email_settings_page_shows_every_roles_brief(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'wenine');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/settings/mail")
            ->assertOk()
            ->assertSee('Welcome emails')
            ->assertSee('Teacher')
            ->assertSee('Parent');
    }
}
