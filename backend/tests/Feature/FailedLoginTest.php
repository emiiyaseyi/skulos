<?php

namespace Tests\Feature;

use App\Models\FailedLogin;
use App\Models\School;
use App\Models\User;
use App\Services\Dashboard\RoleDashboard;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Failed Logins (Dashboard Design Cross-Check, ICT Officer: "Active
 * Users, Failed Logins, Storage, Backups" — Failed Logins was explicitly
 * "skipped, none of that is tracked yet"). Passive tracking only (scope
 * decision 2026-07-15): log every failed attempt, surface a count and a
 * recent list on the ICT Officer dashboard, no account lockout policy.
 */
class FailedLoginTest extends TestCase
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

    public function test_a_wrong_password_is_logged_with_the_bad_password_reason_and_never_stores_the_password(): void
    {
        $school = $this->provisionSchool('faillogone');
        $user = User::factory()->create(['email' => 'real@example.com', 'password' => bcrypt('correct-password')]);
        $roleId = $this->rbac->cloneTemplateRole($school, 'school-owner')->id;
        $school->users()->attach($user->id, ['role_id' => $roleId, 'status' => 'active']);

        $this->post('http://faillogone.localhost/login', [
            'email' => 'real@example.com', 'password' => 'totally-wrong-password',
        ])->assertSessionHasErrors('email');

        $record = FailedLogin::first();
        $this->assertNotNull($record);
        $this->assertSame(FailedLogin::REASON_BAD_PASSWORD, $record->reason);
        $this->assertSame('real@example.com', $record->identifier);
        $this->assertSame($school->id, $record->school_id);
        $this->assertArrayNotHasKey('password', $record->toArray(), 'the model must never even have a password attribute to leak');
    }

    public function test_an_unknown_identifier_is_logged_with_the_not_found_reason(): void
    {
        $school = $this->provisionSchool('faillogtwo');

        $this->post('http://faillogtwo.localhost/login', [
            'email' => 'nobody-here@example.com', 'password' => 'whatever123',
        ])->assertSessionHasErrors('email');

        $record = FailedLogin::first();
        $this->assertSame(FailedLogin::REASON_NOT_FOUND, $record->reason);
    }

    public function test_a_real_user_who_is_not_a_member_of_this_school_is_logged_with_the_not_member_reason(): void
    {
        $schoolA = $this->provisionSchool('faillogthreea');
        $schoolB = $this->provisionSchool('faillogthreeb');
        $user = User::factory()->create(['email' => 'outsider@example.com', 'password' => bcrypt('correct-password')]);
        $roleId = $this->rbac->cloneTemplateRole($schoolA, 'school-owner')->id;
        $schoolA->users()->attach($user->id, ['role_id' => $roleId, 'status' => 'active']);

        $this->post('http://faillogthreeb.localhost/login', [
            'email' => 'outsider@example.com', 'password' => 'correct-password',
        ])->assertSessionHasErrors('email');

        $record = FailedLogin::first();
        $this->assertSame(FailedLogin::REASON_NOT_MEMBER, $record->reason);
        $this->assertSame($schoolB->id, $record->school_id);
    }

    public function test_a_correct_login_logs_nothing(): void
    {
        $school = $this->provisionSchool('faillogfour');
        $user = User::factory()->create(['email' => 'good@example.com', 'password' => bcrypt('correct-password')]);
        $roleId = $this->rbac->cloneTemplateRole($school, 'school-owner')->id;
        $school->users()->attach($user->id, ['role_id' => $roleId, 'status' => 'active']);

        $this->post('http://faillogfour.localhost/login', [
            'email' => 'good@example.com', 'password' => 'correct-password',
        ]);

        $this->assertSame(0, FailedLogin::count());
    }

    public function test_ict_officer_dashboard_shows_the_real_failed_login_count_and_list_scoped_to_their_own_school(): void
    {
        $school = $this->provisionSchool('faillogfive');
        $otherSchool = $this->provisionSchool('faillogsix');
        $officerUser = User::factory()->create();
        $officerRole = $this->rbac->cloneTemplateRole($school, 'ict-officer');
        $school->users()->attach($officerUser->id, ['role_id' => $officerRole->id, 'status' => 'active']);

        FailedLogin::create(['school_id' => $school->id, 'identifier' => 'a@example.com', 'reason' => FailedLogin::REASON_BAD_PASSWORD, 'ip_address' => '10.0.0.1']);
        FailedLogin::create(['school_id' => $school->id, 'identifier' => 'b@example.com', 'reason' => FailedLogin::REASON_NOT_FOUND, 'ip_address' => '10.0.0.2']);
        FailedLogin::create(['school_id' => $otherSchool->id, 'identifier' => 'c@example.com', 'reason' => FailedLogin::REASON_BAD_PASSWORD, 'ip_address' => '10.0.0.3']);
        // Outside the 7 day window, must not be counted.
        $old = FailedLogin::create(['school_id' => $school->id, 'identifier' => 'old@example.com', 'reason' => FailedLogin::REASON_BAD_PASSWORD]);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $data = app(RoleDashboard::class)->build($officerUser, $school);

        $this->assertSame('ict_officer', $data['kind']);
        $failedFig = collect($data['figs'])->firstWhere('l', 'Failed Logins (7d)');
        $this->assertSame('2', $failedFig['n'], 'must count only this school\'s attempts within 7 days, not the other school\'s or the old one');
        $ipsFig = collect($data['figs'])->firstWhere('l', 'Unique IPs (7d)');
        $this->assertSame('2', $ipsFig['n']);
        $identifiers = collect($data['panel']['recentFailedLogins'])->pluck('identifier');
        $this->assertTrue($identifiers->contains('a@example.com'));
        $this->assertTrue($identifiers->contains('b@example.com'));
        $this->assertFalse($identifiers->contains('c@example.com'), 'must not leak another school\'s failed login attempts');
    }

    public function test_retention_cleanup_purges_failed_logins_older_than_90_days(): void
    {
        $school = $this->provisionSchool('faillogseven');
        $recent = FailedLogin::create(['school_id' => $school->id, 'identifier' => 'recent@example.com', 'reason' => FailedLogin::REASON_BAD_PASSWORD]);
        $old = FailedLogin::create(['school_id' => $school->id, 'identifier' => 'old@example.com', 'reason' => FailedLogin::REASON_BAD_PASSWORD]);
        $old->forceFill(['created_at' => now()->subDays(100)])->save();

        $this->artisan('skulos:retention-cleanup')->assertSuccessful();

        $this->assertNotNull(FailedLogin::find($recent->id));
        $this->assertNull(FailedLogin::find($old->id));
    }
}
