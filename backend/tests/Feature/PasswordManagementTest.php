<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\PasswordResetRequest;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Services\Accounts\AccountProvisioningService;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Self-service and forced password changes, and admin-approved
 * forgot-password (user directives 2026-07-13: students can log in with
 * their admission number too; "let's add a new feature to all users
 * profile, where they can change their own password"; "all first time
 * users must change the default password when they login in for the
 * first time"; "a forgot password option... which the school admin will
 * have to approve").
 */
class PasswordManagementTest extends TestCase
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

    /** A student can sign in with their admission number when "as=student" is picked. */
    public function test_a_student_can_sign_in_with_their_admission_number(): void
    {
        [$school, $studentUser] = $this->schoolWithMember('student', 'pwone', ['password' => bcrypt('studentpass1')]);

        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'ADM/001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'user_id' => $studentUser->id]);
        app(TenantManager::class)->forget();

        $this->post("http://{$school->slug}.localhost/login", [
            'email' => 'ADM/001',
            'password' => 'studentpass1',
            'as' => 'student',
        ])->assertRedirect();
        $this->assertAuthenticated();
    }

    /** Without "as=student", an admission-number-shaped identifier is not tried against the Student table at all. */
    public function test_an_admission_number_does_not_match_without_selecting_student(): void
    {
        [$school, $studentUser] = $this->schoolWithMember('student', 'pwtwo', ['password' => bcrypt('studentpass1')]);

        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'ADM/002', 'first_name' => 'Ada', 'last_name' => 'Obi', 'user_id' => $studentUser->id]);
        app(TenantManager::class)->forget();

        $this->post("http://{$school->slug}.localhost/login", [
            'email' => 'ADM/002',
            'password' => 'studentpass1',
        ])->assertSessionHasErrors('email');
    }

    /** A login provisioned via AccountProvisioningService must change its password on first sign in. */
    public function test_a_newly_provisioned_login_must_change_its_password_on_first_sign_in(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'pwthree');

        $result = app(AccountProvisioningService::class)->provision($school, 'Tolu Bello', 'tolu@example.com', 'subject-teacher');
        $this->assertTrue($result['user']->must_change_password);

        $this->post("http://{$school->slug}.localhost/login", [
            'email' => 'tolu@example.com',
            'password' => $result['password'],
        ])->assertRedirect(route('school.password.change', ['school' => $school->slug]));
    }

    /** The mandatory gate blocks every other page until the password is changed. */
    public function test_require_password_change_blocks_other_pages_until_changed(): void
    {
        [$school, $user] = $this->schoolWithMember('school-owner', 'pwfour');
        $user->forceFill(['must_change_password' => true])->save();

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertRedirect(route('school.password.change', ['school' => $school->slug]));

        // The change-password page itself is not blocked.
        $this->actingAs($user)->get("http://{$school->slug}.localhost/password/change")->assertOk();
    }

    public function test_changing_password_clears_the_must_change_flag_and_applies_immediately(): void
    {
        [$school, $user] = $this->schoolWithMember('school-owner', 'pwfive', ['password' => Hash::make('oldpass123')]);
        $user->forceFill(['must_change_password' => true])->save();

        $this->actingAs($user)->post("http://{$school->slug}.localhost/password/change", [
            'current_password' => 'oldpass123',
            'password' => 'brandnewpass1',
            'password_confirmation' => 'brandnewpass1',
        ])->assertRedirect();

        $fresh = $user->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertTrue(Hash::check('brandnewpass1', $fresh->password));
    }

    public function test_changing_password_with_the_wrong_current_password_fails(): void
    {
        [$school, $user] = $this->schoolWithMember('school-owner', 'pwsix', ['password' => Hash::make('oldpass123')]);

        $this->actingAs($user)->post("http://{$school->slug}.localhost/password/change", [
            'current_password' => 'totally-wrong',
            'password' => 'brandnewpass1',
            'password_confirmation' => 'brandnewpass1',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('oldpass123', $user->fresh()->password));
    }

    /** Forgot password holds a pending request, the old password keeps working until an admin approves it. */
    public function test_forgot_password_holds_the_request_and_does_not_change_the_password_yet(): void
    {
        [$school, $user] = $this->schoolWithMember('subject-teacher', 'pwseven', ['password' => Hash::make('oldpass123')]);

        $this->post("http://{$school->slug}.localhost/password/forgot", [
            'identifier' => $user->email,
            'password' => 'mynewpass1',
            'password_confirmation' => 'mynewpass1',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('oldpass123', $user->fresh()->password), 'the old password must keep working until approved');

        app(TenantManager::class)->use($school);
        $pending = PasswordResetRequest::where('user_id', $user->id)->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($pending);
        $this->assertSame(PasswordResetRequest::STATUS_PENDING, $pending->status);
    }

    public function test_admin_can_approve_a_password_reset_and_it_applies(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'pweight');
        $teacher = User::factory()->create(['password' => Hash::make('oldpass123')]);
        $school->users()->attach($teacher->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'subject-teacher')->id, 'status' => 'active']);

        $this->post("http://{$school->slug}.localhost/password/forgot", [
            'identifier' => $teacher->email,
            'password' => 'mynewpass1',
            'password_confirmation' => 'mynewpass1',
        ]);

        app(TenantManager::class)->use($school);
        $requestId = PasswordResetRequest::where('user_id', $teacher->id)->first()->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/profile/approvals/reset/{$requestId}/approve")
            ->assertRedirect()->assertSessionHas('status');

        $fresh = $teacher->fresh();
        $this->assertTrue(Hash::check('mynewpass1', $fresh->password));
        $this->assertFalse($fresh->must_change_password);
    }

    public function test_admin_can_reject_a_password_reset_with_a_reason(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'pwnine');
        $teacher = User::factory()->create(['password' => Hash::make('oldpass123')]);
        $school->users()->attach($teacher->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'subject-teacher')->id, 'status' => 'active']);

        $this->post("http://{$school->slug}.localhost/password/forgot", [
            'identifier' => $teacher->email,
            'password' => 'mynewpass1',
            'password_confirmation' => 'mynewpass1',
        ]);

        app(TenantManager::class)->use($school);
        $requestId = PasswordResetRequest::where('user_id', $teacher->id)->first()->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/profile/approvals/reset/{$requestId}/reject", [
            'notes' => 'Please call the office to verify identity first.',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('oldpass123', $teacher->fresh()->password));

        app(TenantManager::class)->use($school);
        $rejected = PasswordResetRequest::find($requestId);
        app(TenantManager::class)->forget();
        $this->assertSame(PasswordResetRequest::STATUS_REJECTED, $rejected->status);
    }

    public function test_forgot_password_for_an_unknown_identifier_gives_the_same_generic_message(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'pwten');

        $known = $this->post("http://{$school->slug}.localhost/password/forgot", [
            'identifier' => 'nobody@example.com',
            'password' => 'mynewpass1',
            'password_confirmation' => 'mynewpass1',
        ]);

        $known->assertSessionHas('status', fn ($m) => str_contains($m, 'If that matches an account'));
    }

    /**
     * The second forgot-password option (user directive 2026-07-13:
     * "the user make use of password reset link and get it as a mail").
     * Applies immediately once the link is used, no admin approval.
     */
    public function test_the_email_reset_link_applies_immediately_once_used(): void
    {
        Mail::fake();
        [$school, $user] = $this->schoolWithMember('subject-teacher', 'pweleven', ['password' => Hash::make('oldpass123')]);

        $this->post("http://{$school->slug}.localhost/password/email-link", ['email' => $user->email])
            ->assertRedirect();

        Mail::assertSent(\App\Mail\PasswordResetLinkMail::class);

        $record = DB::table('password_reset_tokens')->where('email', Str::lower($user->email))->first();
        $this->assertNotNull($record, 'a token row should be stored');

        // The real token is only ever inside the emailed link, not
        // recoverable from the hashed DB row, so the test drives the
        // controller's own token-issuing path via the mail payload
        // instead of guessing a token blind.
        Mail::assertSent(\App\Mail\PasswordResetLinkMail::class, function ($mail) use ($school, $user) {
            $token = null;
            parse_str(parse_url($mail->resetUrl, PHP_URL_QUERY), $query);
            $token = $query['token'] ?? null;

            $this->post("http://{$school->slug}.localhost/password/reset", [
                'token' => $token,
                'email' => $user->email,
                'password' => 'freshpass123',
                'password_confirmation' => 'freshpass123',
            ])->assertRedirect(route('school.login', ['school' => $school->slug]));

            return true;
        });

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('freshpass123', $fresh->password));
        $this->assertFalse($fresh->must_change_password);
        $this->assertNull(DB::table('password_reset_tokens')->where('email', Str::lower($user->email))->first(), 'the token must be consumed after use');
    }

    public function test_an_expired_or_wrong_reset_token_is_rejected(): void
    {
        [$school, $user] = $this->schoolWithMember('subject-teacher', 'pwtwelve', ['password' => Hash::make('oldpass123')]);

        DB::table('password_reset_tokens')->insert([
            'email' => Str::lower($user->email),
            'token' => Hash::make('the-real-token'),
            'created_at' => now(),
        ]);

        $this->post("http://{$school->slug}.localhost/password/reset", [
            'token' => 'a-wrong-token',
            'email' => $user->email,
            'password' => 'freshpass123',
            'password_confirmation' => 'freshpass123',
        ])->assertSessionHasErrors('token');

        $this->assertTrue(Hash::check('oldpass123', $user->fresh()->password));
    }

    /** The user is emailed once their own chosen forgot-password request is approved. */
    public function test_approving_a_password_reset_emails_the_user(): void
    {
        Mail::fake();
        [$school, $admin] = $this->schoolWithMember('school-owner', 'pwthirteen');
        $teacher = User::factory()->create(['password' => Hash::make('oldpass123')]);
        $school->users()->attach($teacher->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'subject-teacher')->id, 'status' => 'active']);

        $this->post("http://{$school->slug}.localhost/password/forgot", [
            'identifier' => $teacher->email,
            'password' => 'mynewpass1',
            'password_confirmation' => 'mynewpass1',
        ]);

        app(TenantManager::class)->use($school);
        $requestId = PasswordResetRequest::where('user_id', $teacher->id)->first()->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/profile/approvals/reset/{$requestId}/approve");

        Mail::assertSent(\App\Mail\PasswordResetApprovedMail::class, fn ($mail) => $mail->user->id === $teacher->id);
    }
}
