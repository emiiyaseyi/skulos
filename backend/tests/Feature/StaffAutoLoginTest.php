<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Staff;
use App\Models\Tenant\StudentGuardian;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Staff joined the auto-provisioned-login pattern Student/Parent already
 * had (user request 2026-09-11: "For staff account, parent and student
 * created - their login details should be auto created"). Always starts
 * on the plain "Teacher" role, since which real role fits is a decision
 * no auto-provisioning step can safely guess -- RoleDashboard shows an
 * honest "nothing assigned yet" state for that role until an admin
 * upgrades it. Also covers the backfill command for accounts created
 * before this change existed.
 */
class StaffAutoLoginTest extends TestCase
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

    protected function schoolWithOwner(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $owner = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $owner];
    }

    public function test_adding_a_staff_member_auto_provisions_a_teacher_login(): void
    {
        [$school, $owner] = $this->schoolWithOwner('autologone');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/create", [
            'first_name' => 'Bisi', 'last_name' => 'Okafor',
            'email' => 'bisi.okafor@example.com', 'position' => 'Mathematics Teacher',
            'status' => Staff::STATUS_ACTIVE,
        ])->assertRedirect();

        $staffMember = Staff::where('email', 'bisi.okafor@example.com')->firstOrFail();
        $this->assertNotNull($staffMember->user_id);

        $user = User::findOrFail($staffMember->user_id);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password123', $user->password));
        $this->assertTrue($user->must_change_password);

        app(TenantManager::class)->use($school);
        $role = $user->roleIn($school);
        $this->assertSame('teacher', $role->slug);
        app(TenantManager::class)->forget();
    }

    public function test_a_bare_teacher_login_sees_the_unassigned_state_on_their_dashboard(): void
    {
        [$school, $owner] = $this->schoolWithOwner('autologtwo');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/create", [
            'first_name' => 'Femi', 'last_name' => 'Ade',
            'email' => 'femi.ade@example.com', 'position' => 'Class Teacher',
            'status' => Staff::STATUS_ACTIVE,
        ])->assertRedirect();

        $staffMember = Staff::where('email', 'femi.ade@example.com')->firstOrFail();
        $user = User::findOrFail($staffMember->user_id);
        $user->forceFill(['must_change_password' => false])->save();

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Nothing assigned yet')
            ->assertSee('Kindly contact your school administrator', false);
    }

    public function test_a_school_configurable_default_staff_password_is_used(): void
    {
        [$school, $owner] = $this->schoolWithOwner('autologthree');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/settings", [
            'required_fields' => [],
            'parent_identifier' => 'email',
            'staff_identifier' => 'email',
            'guardian_visibility' => 'class_teacher_only',
            'default_student_password' => 'password123',
            'default_parent_password' => 'password123',
            'default_staff_password' => 'StaffCustom99',
        ])->assertRedirect();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/create", [
            'first_name' => 'Kunle', 'last_name' => 'Bello',
            'email' => 'kunle.bello@example.com', 'position' => 'Teacher',
            'status' => Staff::STATUS_ACTIVE,
        ])->assertRedirect();

        $staffMember = Staff::where('email', 'kunle.bello@example.com')->firstOrFail();
        $user = User::findOrFail($staffMember->user_id);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('StaffCustom99', $user->password));
    }

    public function test_backfill_command_provisions_logins_for_accounts_created_before_this_change(): void
    {
        [$school, ] = $this->schoolWithOwner('autologfour');

        app(TenantManager::class)->use($school);
        $staffMember = Staff::create([
            'staff_id' => 'STF/0099', 'first_name' => 'Old', 'last_name' => 'Hire',
            'email' => 'old.hire@example.com', 'position' => 'Teacher', 'status' => Staff::STATUS_ACTIVE,
        ]);
        $student = Student::create([
            'admission_number' => 'ADM/0099', 'first_name' => 'Old', 'last_name' => 'Student',
            'status' => Student::STATUS_ACTIVE,
        ]);
        $guardian = StudentGuardian::create([
            'student_id' => $student->id, 'name' => 'Old Guardian', 'email' => 'old.guardian@example.com',
            'is_primary' => true,
        ]);
        app(TenantManager::class)->forget();

        $this->assertNull($staffMember->user_id);
        $this->assertNull($student->user_id);
        $this->assertNull($guardian->guardian_user_id);

        $this->artisan('accounts:backfill-logins', ['--school' => 'autologfour'])->assertSuccessful();

        $staffMember->refresh();
        $student->refresh();
        $guardian->refresh();
        $this->assertNotNull($staffMember->user_id);
        $this->assertNotNull($student->user_id);
        $this->assertNotNull($guardian->guardian_user_id);
    }
}
