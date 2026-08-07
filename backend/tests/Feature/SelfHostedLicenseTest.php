<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Release;
use App\Models\School;
use App\Models\User;
use App\Services\Licensing\LicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Self-hosted licensing (user request 2026-07-18: "self hosted with a
 * disclosed license check", chosen over the hidden/unremovable backdoor
 * account the user first asked for, which was declined). Covers the
 * platform-admin side (issue/regenerate/revoke a key, answer an incoming
 * check-in) and the self-hosted side (App\Services\Licensing\
 * LicenseService::checkSelfHosted() enforcing grace then lockout).
 */
class SelfHostedLicenseTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_a_platform_admin_can_enable_self_hosted_licensing_for_a_school(): void
    {
        $admin = $this->superAdmin();
        $school = School::factory()->create();

        $this->actingAs($admin)->post(route('admin.schools.license.enable', $school))->assertRedirect();

        $school->refresh();
        $this->assertTrue($school->is_self_hosted);
        $this->assertNotNull($school->license_key);
        $this->assertNotNull($school->license_issued_at);
        $this->assertTrue(AuditLog::where('action', 'school.license.enabled')->exists());
    }

    public function test_a_platform_admin_can_regenerate_the_license_key(): void
    {
        $admin = $this->superAdmin();
        $school = School::factory()->create();
        app(LicenseService::class)->issueFor($school);
        $originalKey = $school->fresh()->license_key;

        $this->actingAs($admin)->post(route('admin.schools.license.regenerate', $school))->assertRedirect();

        $this->assertNotEquals($originalKey, $school->fresh()->license_key);
    }

    public function test_regenerating_a_key_for_a_non_self_hosted_school_404s(): void
    {
        $admin = $this->superAdmin();
        $school = School::factory()->create();

        $this->actingAs($admin)->post(route('admin.schools.license.regenerate', $school))->assertNotFound();
    }

    public function test_a_platform_admin_can_revoke_self_hosted_licensing(): void
    {
        $admin = $this->superAdmin();
        $school = School::factory()->create();
        app(LicenseService::class)->issueFor($school);

        $this->actingAs($admin)->post(route('admin.schools.license.revoke', $school))->assertRedirect();

        $school->refresh();
        $this->assertFalse($school->is_self_hosted);
        $this->assertNull($school->license_key);
    }

    public function test_a_non_super_admin_cannot_manage_licensing(): void
    {
        $user = User::factory()->create();
        $school = School::factory()->create();

        $this->actingAs($user)->post(route('admin.schools.license.enable', $school))->assertNotFound();
    }

    public function test_verify_endpoint_returns_valid_for_an_active_self_hosted_school_with_the_right_key(): void
    {
        $school = School::factory()->create(['status' => School::STATUS_ACTIVE]);
        app(LicenseService::class)->issueFor($school);

        $this->postJson(route('license.verify'), ['license_key' => $school->fresh()->license_key])
            ->assertOk()
            ->assertJson(['valid' => true]);

        $this->assertNotNull($school->fresh()->license_last_verified_at);
    }

    public function test_verify_endpoint_returns_invalid_for_an_unknown_key(): void
    {
        $this->postJson(route('license.verify'), ['license_key' => 'SKL-BOGUS-KEY-0000'])
            ->assertOk()
            ->assertJson(['valid' => false, 'reason' => 'unknown_license_key']);
    }

    public function test_verify_endpoint_returns_invalid_for_a_suspended_school(): void
    {
        $school = School::factory()->create(['status' => School::STATUS_SUSPENDED, 'suspension_reason' => School::SUSPENSION_REASON_BILLING]);
        app(LicenseService::class)->issueFor($school);

        $this->postJson(route('license.verify'), ['license_key' => $school->fresh()->license_key])
            ->assertOk()
            ->assertJson(['valid' => false, 'reason' => School::SUSPENSION_REASON_BILLING]);
    }

    public function test_check_self_hosted_does_nothing_unless_this_deployment_is_configured_as_self_hosted(): void
    {
        config(['skulos.self_hosted' => false]);
        $school = School::factory()->create(['status' => School::STATUS_ACTIVE]);
        app(LicenseService::class)->issueFor($school);

        Http::fake(['*' => Http::response(['valid' => false], 200)]);

        app(LicenseService::class)->checkSelfHosted();

        Http::assertNothingSent();
        $this->assertNull($school->fresh()->license_grace_started_at);
    }

    public function test_check_self_hosted_clears_grace_and_reinstates_on_a_valid_response(): void
    {
        config(['skulos.self_hosted' => true]);
        $school = School::factory()->create([
            'status' => School::STATUS_SUSPENDED,
            'suspension_reason' => School::SUSPENSION_REASON_LICENSE_EXPIRED,
        ]);
        app(LicenseService::class)->issueFor($school);
        $school->update(['license_grace_started_at' => now()->subDays(3)]);

        Http::fake(['*' => Http::response(['valid' => true], 200)]);

        app(LicenseService::class)->checkSelfHosted();

        $school->refresh();
        $this->assertNull($school->license_grace_started_at);
        $this->assertEquals(School::STATUS_ACTIVE, $school->status);
        $this->assertNull($school->suspension_reason);
    }

    public function test_check_self_hosted_starts_the_grace_clock_on_an_invalid_response_without_locking_out_immediately(): void
    {
        config(['skulos.self_hosted' => true, 'skulos.self_hosted_license.grace_days' => 14]);
        $school = School::factory()->create(['status' => School::STATUS_ACTIVE]);
        app(LicenseService::class)->issueFor($school);

        Http::fake(['*' => Http::response(['valid' => false, 'reason' => 'inactive'], 200)]);

        app(LicenseService::class)->checkSelfHosted();

        $school->refresh();
        $this->assertNotNull($school->license_grace_started_at);
        $this->assertEquals(School::STATUS_ACTIVE, $school->status);
    }

    public function test_check_self_hosted_treats_an_unreachable_endpoint_the_same_as_invalid(): void
    {
        config(['skulos.self_hosted' => true]);
        $school = School::factory()->create(['status' => School::STATUS_ACTIVE]);
        app(LicenseService::class)->issueFor($school);

        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('unreachable')]);

        app(LicenseService::class)->checkSelfHosted();

        $this->assertNotNull($school->fresh()->license_grace_started_at);
    }

    public function test_check_self_hosted_locks_out_once_the_grace_period_has_elapsed(): void
    {
        config(['skulos.self_hosted' => true, 'skulos.self_hosted_license.grace_days' => 14]);
        $school = School::factory()->create(['status' => School::STATUS_ACTIVE]);
        app(LicenseService::class)->issueFor($school);
        $school->update(['license_grace_started_at' => now()->subDays(15)]);

        Http::fake(['*' => Http::response(['valid' => false], 200)]);

        app(LicenseService::class)->checkSelfHosted();

        $school->refresh();
        $this->assertEquals(School::STATUS_SUSPENDED, $school->status);
        $this->assertEquals(School::SUSPENSION_REASON_LICENSE_EXPIRED, $school->suspension_reason);
    }

    public function test_the_license_settings_page_shows_current_status(): void
    {
        $admin = $this->superAdmin();
        $school = School::factory()->create(['status' => School::STATUS_ACTIVE]);
        app(LicenseService::class)->issueFor($school);

        $this->actingAs($admin)->get(route('admin.settings.self-hosted-license.edit'))
            ->assertOk()
            ->assertSee($school->license_key);
    }

    public function test_saving_a_license_key_on_the_settings_page_updates_the_school(): void
    {
        $admin = $this->superAdmin();
        School::factory()->create();

        $this->actingAs($admin)->post(route('admin.settings.self-hosted-license.update'), [
            'license_key' => 'SKL-MANUAL-0001-XYZ',
        ])->assertRedirect();

        $school = School::orderBy('id')->first();
        $this->assertTrue($school->is_self_hosted);
        $this->assertEquals('SKL-MANUAL-0001-XYZ', $school->license_key);
    }

    /** Release management (2026-08-07): bundled into the routine check-in response rather than a second endpoint. */
    public function test_verify_endpoint_includes_the_latest_published_release_when_one_exists(): void
    {
        $school = School::factory()->create(['status' => School::STATUS_ACTIVE]);
        app(LicenseService::class)->issueFor($school);
        // published_at is deliberately excluded from $fillable (publishing
        // must go through ReleaseService::publish(), never mass
        // assignment) — forceCreate() here, same as Payment::forceCreate()
        // elsewhere in this suite for the same reason.
        Release::forceCreate([
            'version' => '2026.08.07', 'notes' => 'First release', 'file_path' => 'releases/2026.08.07.zip',
            'file_size' => 100, 'checksum' => str_repeat('a', 64), 'published_at' => now(),
        ]);
        Release::create([
            'version' => '2026.09.01', 'notes' => 'Draft, must not be reported', 'file_path' => 'releases/2026.09.01.zip',
            'file_size' => 100, 'checksum' => str_repeat('b', 64),
        ]);

        $this->postJson(route('license.verify'), ['license_key' => $school->fresh()->license_key])
            ->assertOk()
            ->assertJson(['valid' => true, 'latest_version' => '2026.08.07']);
    }

    public function test_verify_endpoint_has_no_release_info_when_nothing_is_published(): void
    {
        $school = School::factory()->create(['status' => School::STATUS_ACTIVE]);
        app(LicenseService::class)->issueFor($school);

        $this->postJson(route('license.verify'), ['license_key' => $school->fresh()->license_key])
            ->assertOk()
            ->assertJson(['valid' => true, 'latest_version' => null]);
    }

    public function test_check_self_hosted_stores_the_latest_available_version_from_a_valid_response(): void
    {
        config(['skulos.self_hosted' => true]);
        $school = School::factory()->create(['status' => School::STATUS_ACTIVE]);
        app(LicenseService::class)->issueFor($school);

        Http::fake(['*' => Http::response(['valid' => true, 'latest_version' => '2026.08.07'], 200)]);

        app(LicenseService::class)->checkSelfHosted();

        $this->assertSame('2026.08.07', $school->fresh()->latest_available_version);
    }

    public function test_the_license_settings_page_shows_an_update_banner_only_when_versions_differ(): void
    {
        $admin = $this->superAdmin();
        $school = School::factory()->create(['status' => School::STATUS_ACTIVE, 'latest_available_version' => '2026.08.07']);
        app(LicenseService::class)->issueFor($school);

        config(['skulos.version' => '2026.07.01']);
        $this->actingAs($admin)->get(route('admin.settings.self-hosted-license.edit'))
            ->assertOk()->assertSee('Update available: 2026.08.07');

        config(['skulos.version' => '2026.08.07']);
        $this->actingAs($admin)->get(route('admin.settings.self-hosted-license.edit'))
            ->assertOk()->assertDontSee('Update available');
    }

    public function test_downloading_an_update_saves_it_once_the_checksum_matches(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $admin = $this->superAdmin();
        $school = School::factory()->create();
        app(LicenseService::class)->issueFor($school);
        $zipBytes = 'fake release zip bytes';
        $checksum = hash('sha256', $zipBytes);

        Http::fake([
            '*/latest-release' => Http::response(['valid' => true, 'latest_version' => '2026.08.07', 'latest_release_checksum' => $checksum], 200),
            '*/download' => Http::response($zipBytes, 200),
        ]);

        $this->actingAs($admin)->post(route('admin.settings.self-hosted-license.download-update'))
            ->assertRedirect()->assertSessionHas('status');

        \Illuminate\Support\Facades\Storage::disk('local')->assertExists('updates/2026.08.07.zip');
        $this->assertSame($zipBytes, \Illuminate\Support\Facades\Storage::disk('local')->get('updates/2026.08.07.zip'));
    }

    public function test_downloading_an_update_discards_it_and_errors_on_a_checksum_mismatch(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $admin = $this->superAdmin();
        $school = School::factory()->create();
        app(LicenseService::class)->issueFor($school);

        Http::fake([
            '*/latest-release' => Http::response(['valid' => true, 'latest_version' => '2026.08.07', 'latest_release_checksum' => str_repeat('0', 64)], 200),
            '*/download' => Http::response('tampered or corrupted bytes', 200),
        ]);

        $this->actingAs($admin)->post(route('admin.settings.self-hosted-license.download-update'))
            ->assertRedirect()->assertSessionHas('error');

        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing('updates/2026.08.07.zip');
    }

    public function test_a_locked_out_school_403s_via_the_normal_tenant_resolution_with_a_clear_message(): void
    {
        config(['app.domain' => 'localhost']);
        $school = School::factory()->create([
            'status' => School::STATUS_SUSPENDED,
            'suspension_reason' => School::SUSPENSION_REASON_LICENSE_EXPIRED,
        ]);
        $school->forceFill(['database' => 'skulos_test_'.uniqid(), 'provisioned_at' => now()])->save();

        $this->get('http://'.$school->slug.'.localhost/login')
            ->assertForbidden()
            ->assertSee('license could not be verified', false);
    }
}
