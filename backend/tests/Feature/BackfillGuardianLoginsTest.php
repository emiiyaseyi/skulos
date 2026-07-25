<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * php artisan schools:backfill-guardian-logins (found live on greenfield
 * 2026-07-25: a parent broadcast reached exactly one real account because
 * 319 of 320 guardians had no Parent Portal login at all,
 * schools:seed-demo-data's own guardian provisioning only ever applies to
 * guardians created by a run of the command, and every already-seeded
 * school was already at its target headcount so those rows were never
 * revisited).
 */
class BackfillGuardianLoginsTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    public function test_it_provisions_a_login_for_every_guardian_missing_one(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'backfillone']));
        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'BF1', 'first_name' => 'A', 'last_name' => 'One']);
        $guardian = StudentGuardian::create(['student_id' => $student->id, 'name' => 'Parent One', 'email' => 'parentone@example.com', 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $this->artisan('schools:backfill-guardian-logins', ['school' => 'backfillone'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $guardian->refresh();
        app(TenantManager::class)->forget();

        $this->assertNotNull($guardian->guardian_user_id);
        $user = \App\Models\User::find($guardian->guardian_user_id);
        $this->assertSame('parentone@example.com', $user->email);
        $this->assertTrue($school->users()->wherePivot('status', 'active')->whereKey($user->id)->exists());
    }

    public function test_siblings_sharing_contact_info_share_one_login_not_two(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'backfilltwo']));
        app(TenantManager::class)->use($school);
        $a = Student::create(['admission_number' => 'BF2', 'first_name' => 'A', 'last_name' => 'Sib']);
        $b = Student::create(['admission_number' => 'BF3', 'first_name' => 'B', 'last_name' => 'Sib']);
        StudentGuardian::create(['student_id' => $a->id, 'name' => 'Shared Parent', 'email' => 'shared@example.com', 'is_primary' => true]);
        StudentGuardian::create(['student_id' => $b->id, 'name' => 'Shared Parent', 'email' => 'shared@example.com', 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $this->artisan('schools:backfill-guardian-logins', ['school' => 'backfilltwo'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $userIds = StudentGuardian::pluck('guardian_user_id')->unique();
        app(TenantManager::class)->forget();

        $this->assertCount(1, $userIds);
    }

    public function test_it_is_safe_to_run_again_and_does_not_touch_existing_logins(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'backfillthree']));
        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'BF4', 'first_name' => 'A', 'last_name' => 'One']);
        $guardian = StudentGuardian::create(['student_id' => $student->id, 'name' => 'Parent One', 'phone' => '08010000000', 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $this->artisan('schools:backfill-guardian-logins', ['school' => 'backfillthree'])->assertSuccessful();
        app(TenantManager::class)->use($school);
        $firstUserId = $guardian->fresh()->guardian_user_id;
        app(TenantManager::class)->forget();

        $this->artisan('schools:backfill-guardian-logins', ['school' => 'backfillthree'])->assertSuccessful();
        app(TenantManager::class)->use($school);
        $secondUserId = $guardian->fresh()->guardian_user_id;
        app(TenantManager::class)->forget();

        $this->assertSame($firstUserId, $secondUserId);
    }

    public function test_a_guardian_that_already_has_a_login_is_left_alone(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'backfillfour']));
        app(TenantManager::class)->use($school);
        $existingUser = \App\Models\User::create(['name' => 'Already Has Login', 'email' => 'already@example.com', 'password' => bcrypt('secret')]);
        $student = Student::create(['admission_number' => 'BF5', 'first_name' => 'A', 'last_name' => 'One']);
        $guardian = StudentGuardian::create(['student_id' => $student->id, 'name' => 'Already Has Login', 'email' => 'already@example.com', 'is_primary' => true, 'guardian_user_id' => $existingUser->id]);
        app(TenantManager::class)->forget();

        $this->artisan('schools:backfill-guardian-logins', ['school' => 'backfillfour'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $guardian->refresh();
        app(TenantManager::class)->forget();

        $this->assertSame($existingUser->id, $guardian->guardian_user_id);
    }
}
