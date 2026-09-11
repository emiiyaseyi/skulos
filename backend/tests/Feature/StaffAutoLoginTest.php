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

    /**
     * Real production report 2026-09-11: several staff members were left
     * with no login at all after this shipped, root-caused to the
     * welcome email being sent synchronously and unguarded -- one SMTP
     * hiccup killed the whole provisioning call (and, in the backfill
     * command, every record still to come after it in that school's
     * loop). The account itself must be created and usable regardless of
     * whether the notification email actually sends.
     */
    public function test_a_failed_welcome_email_does_not_block_the_account_from_being_created(): void
    {
        [$school, $owner] = $this->schoolWithOwner('mailfailone');

        \Illuminate\Support\Facades\Mail::shouldReceive('to')->andThrow(new \Exception('Connection could not be established with host.'));

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/create", [
            'first_name' => 'Tola', 'last_name' => 'Ade',
            'email' => 'tola.ade@example.com', 'position' => 'Teacher',
            'status' => Staff::STATUS_ACTIVE,
        ])->assertRedirect();

        $staffMember = Staff::where('email', 'tola.ade@example.com')->firstOrFail();
        $this->assertNotNull($staffMember->user_id, 'the account must still be created even though the welcome email failed to send');
    }

    /**
     * User report 2026-09-11: "staff with only phone number can sign in
     * with the phone number and when they add their email address, it
     * can be used too" -- a phone-only staff member's login never
     * learned about an email added to their profile later.
     */
    public function test_editing_a_phone_only_staff_members_profile_to_add_an_email_syncs_it_to_their_login(): void
    {
        [$school, $owner] = $this->schoolWithOwner('syncone');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/settings", [
            'required_fields' => [], 'parent_identifier' => 'email', 'staff_identifier' => 'phone',
            'guardian_visibility' => 'class_teacher_only',
            'default_student_password' => 'password123', 'default_parent_password' => 'password123', 'default_staff_password' => 'password123',
        ])->assertRedirect();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/create", [
            'first_name' => 'Wale', 'last_name' => 'Osho',
            'phone' => '08099998888', 'position' => 'Teacher', 'status' => Staff::STATUS_ACTIVE,
        ])->assertRedirect();

        $staffMember = Staff::where('phone', '08099998888')->firstOrFail();
        $user = User::findOrFail($staffMember->user_id);
        $this->assertStringEndsWith('.skulos.local', $user->email, 'starts on the synthetic placeholder since no real email was given yet');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffMember->id}/edit", [
            'first_name' => 'Wale', 'last_name' => 'Osho',
            'phone' => '08099998888', 'email' => 'wale.osho@example.com',
            'position' => 'Teacher', 'status' => Staff::STATUS_ACTIVE,
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame('wale.osho@example.com', $user->email);
    }

    /** The Staff profile's Login card must never show the synthetic placeholder the platform generates for a phone-only account. */
    public function test_the_staff_profile_never_shows_the_synthetic_placeholder_email(): void
    {
        [$school, $owner] = $this->schoolWithOwner('nosynthone');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/settings", [
            'required_fields' => [], 'parent_identifier' => 'email', 'staff_identifier' => 'phone',
            'guardian_visibility' => 'class_teacher_only',
            'default_student_password' => 'password123', 'default_parent_password' => 'password123', 'default_staff_password' => 'password123',
        ])->assertRedirect();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/create", [
            'first_name' => 'Chika', 'last_name' => 'Nwosu',
            'phone' => '08077776666', 'position' => 'Teacher', 'status' => Staff::STATUS_ACTIVE,
        ])->assertRedirect();

        $staffMember = Staff::where('phone', '08077776666')->firstOrFail();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/staff/{$staffMember->id}")
            ->assertOk()
            ->assertDontSee('.skulos.local')
            ->assertSee('08077776666')
            ->assertSee('Still using the default password');
    }

    /** User request 2026-09-11: "if it has been changed, put password last change date and time." */
    public function test_the_staff_profile_shows_when_the_password_was_actually_changed(): void
    {
        [$school, $owner] = $this->schoolWithOwner('pwchangedone');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/create", [
            'first_name' => 'Ngozi', 'last_name' => 'Eze',
            'email' => 'ngozi.eze@example.com', 'position' => 'Teacher', 'status' => Staff::STATUS_ACTIVE,
        ])->assertRedirect();

        $staffMember = Staff::where('email', 'ngozi.eze@example.com')->firstOrFail();
        $login = User::findOrFail($staffMember->user_id);

        $this->actingAs($login)->post("http://{$school->slug}.localhost/password/change", [
            'current_password' => 'password123',
            'password' => 'BrandNewPassword1', 'password_confirmation' => 'BrandNewPassword1',
        ])->assertRedirect();

        $login->refresh();
        $this->assertNotNull($login->password_changed_at);
        $this->assertFalse($login->must_change_password);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/staff/{$staffMember->id}")
            ->assertOk()
            ->assertDontSee('Still using the default password')
            ->assertSee('Password changed');
    }
}
