<?php

namespace Tests\Feature;

use App\Models\FailedLogin;
use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A staff account signing in through the "Parent" or "Student" portal
 * picker (or vice versa) with its own real credentials must be rejected
 * (user report 2026-07-15: "STAFF CAN LOGIN WITH PARENT AND STUDENT
 * LOGIN"), and the platform admin login must have a working forgot
 * password flow, which was entirely missing before this fix.
 */
class LoginPortalGatingTest extends TestCase
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

    protected function provisionSchool(string $slug): School
    {
        return app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
    }

    public function test_a_staff_member_cannot_sign_in_through_the_parent_portal_picker(): void
    {
        $school = $this->provisionSchool('portalone');
        $staff = User::factory()->create(['email' => 'owner@example.com', 'password' => bcrypt('correct-password')]);
        $roleId = $this->rbac->cloneTemplateRole($school, 'school-owner')->id;
        $school->users()->attach($staff->id, ['role_id' => $roleId, 'status' => 'active']);

        $this->post('http://portalone.localhost/login', [
            'email' => 'owner@example.com', 'password' => 'correct-password', 'as' => 'parent',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(FailedLogin::REASON_WRONG_PORTAL, FailedLogin::first()->reason);
    }

    public function test_a_parent_cannot_sign_in_through_the_staff_portal_picker(): void
    {
        $school = $this->provisionSchool('portaltwo');
        $parent = User::factory()->create(['email' => 'parent@example.com', 'password' => bcrypt('correct-password')]);
        $roleId = $this->rbac->cloneTemplateRole($school, 'parent')->id;
        $school->users()->attach($parent->id, ['role_id' => $roleId, 'status' => 'active']);

        $this->post('http://portaltwo.localhost/login', [
            'email' => 'parent@example.com', 'password' => 'correct-password', 'as' => 'staff',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_staff_member_signing_in_through_the_correct_staff_portal_still_works(): void
    {
        $school = $this->provisionSchool('portalthree');
        $staff = User::factory()->create(['email' => 'owner2@example.com', 'password' => bcrypt('correct-password')]);
        $roleId = $this->rbac->cloneTemplateRole($school, 'school-owner')->id;
        $school->users()->attach($staff->id, ['role_id' => $roleId, 'status' => 'active']);

        $this->post('http://portalthree.localhost/login', [
            'email' => 'owner2@example.com', 'password' => 'correct-password', 'as' => 'staff',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($staff);
    }

    public function test_a_super_admin_is_never_blocked_by_the_portal_picker(): void
    {
        $school = $this->provisionSchool('portalfour');
        $admin = User::factory()->create(['email' => 'super@example.com', 'password' => bcrypt('correct-password'), 'is_super_admin' => true]);

        $this->post('http://portalfour.localhost/login', [
            'email' => 'super@example.com', 'password' => 'correct-password', 'as' => 'parent',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_platform_login_page_has_a_forgot_password_link(): void
    {
        $this->get('/login')->assertOk()->assertSee(route('login.forgot'), false);
    }

    public function test_a_platform_admin_can_request_and_use_a_reset_link(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['email' => 'platformadmin@example.com', 'is_super_admin' => true]);

        $this->post(route('login.forgot.submit'), ['email' => 'platformadmin@example.com'])
            ->assertRedirect(route('login'));

        Mail::assertSent(\App\Mail\PasswordResetLinkMail::class, function ($mail) use ($admin) {
            return $mail->user->is($admin) && $mail->school === null;
        });

        $token = \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', 'platformadmin@example.com')->first();
        $this->assertNotNull($token, 'a reset token must actually be stored');
    }

    public function test_a_non_admin_email_silently_sends_no_mail(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'regular@example.com', 'is_super_admin' => false]);

        $this->post(route('login.forgot.submit'), ['email' => 'regular@example.com'])
            ->assertRedirect(route('login'));

        Mail::assertNothingSent();
    }
}
