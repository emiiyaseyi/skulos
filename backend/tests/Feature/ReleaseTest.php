<?php

namespace Tests\Feature;

use App\Models\Release;
use App\Models\School;
use App\Models\User;
use App\Services\Licensing\LicenseService;
use App\Services\Licensing\ReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Self-hosted licensing: release management (2026-08-07, user directive:
 * distribute updates through the license mechanism instead of raw git/
 * GitHub access). ReleaseService::build() shells out to a real `git
 * archive` against this repo's own working copy — these tests exercise
 * the real command, not a mock, the same integration-style convention
 * the rest of this suite already follows for tenant provisioning etc.
 */
class ReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // build() writes a real zip via a real `git archive` shell-out —
        // faking the disk redirects that real write into a throwaway
        // test-storage directory Laravel cleans up itself, rather than
        // leaving test-built releases in real dev storage.
        Storage::fake('local');
    }

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_building_a_release_produces_a_real_zip_with_a_matching_checksum(): void
    {
        $release = app(ReleaseService::class)->build('2026.08.07-test1', 'Test release notes');

        $this->assertTrue(Storage::disk('local')->exists($release->file_path));
        $this->assertGreaterThan(0, $release->file_size);
        $this->assertSame(64, strlen($release->checksum));
        $this->assertSame(
            hash_file('sha256', Storage::disk('local')->path($release->file_path)),
            $release->checksum
        );
        $this->assertFalse($release->isPublished());
    }

    public function test_only_a_super_admin_can_build_publish_or_download_a_release(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.releases.index'))->assertNotFound();
        $this->actingAs($user)->post(route('admin.releases.store'), ['version' => 'x'])->assertNotFound();
    }

    public function test_an_admin_can_build_and_publish_a_release_through_the_real_screen(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.releases.store'), [
            'version' => '2026.08.07-test2', 'notes' => 'Real HTTP build',
        ])->assertRedirect();

        $release = Release::where('version', '2026.08.07-test2')->first();
        $this->assertNotNull($release);
        $this->assertFalse($release->isPublished());

        $this->actingAs($admin)->post(route('admin.releases.publish', $release))->assertRedirect();
        $this->assertTrue($release->fresh()->isPublished());

        $this->actingAs($admin)->post(route('admin.releases.unpublish', $release))->assertRedirect();
        $this->assertFalse($release->fresh()->isPublished());
    }

    public function test_an_unpublished_release_is_never_returned_as_latest(): void
    {
        app(ReleaseService::class)->build('2026.08.07-draft', null);

        $this->assertNull(app(ReleaseService::class)->latestPublished());
    }

    public function test_the_license_gated_download_endpoint_refuses_an_unknown_or_revoked_key(): void
    {
        $release = app(ReleaseService::class)->build('2026.08.07-test3', null);
        app(ReleaseService::class)->publish($release);

        $this->postJson(route('license.download'), ['license_key' => 'SKL-BOGUS-0000'])
            ->assertStatus(403)->assertJson(['valid' => false]);

        $school = School::factory()->create(['status' => School::STATUS_ACTIVE]);
        app(LicenseService::class)->issueFor($school);
        app(LicenseService::class)->revoke($school);

        $this->postJson(route('license.download'), ['license_key' => $school->fresh()->license_key ?? 'none'])
            ->assertStatus(403);
    }

    public function test_the_license_gated_download_endpoint_streams_the_latest_published_release_for_a_valid_key(): void
    {
        $release = app(ReleaseService::class)->build('2026.08.07-test4', null);
        app(ReleaseService::class)->publish($release);

        $school = School::factory()->create(['status' => School::STATUS_ACTIVE]);
        app(LicenseService::class)->issueFor($school);

        $response = $this->postJson(route('license.download'), ['license_key' => $school->fresh()->license_key]);
        $response->assertOk();
        $this->assertStringContainsString('2026.08.07-test4.zip', (string) $response->headers->get('content-disposition'));
    }

    public function test_the_latest_release_endpoint_reports_version_notes_and_checksum(): void
    {
        $release = app(ReleaseService::class)->build('2026.08.07-test5', 'Notes here');
        app(ReleaseService::class)->publish($release);

        $school = School::factory()->create(['status' => School::STATUS_ACTIVE]);
        app(LicenseService::class)->issueFor($school);

        $this->postJson(route('license.latest-release'), ['license_key' => $school->fresh()->license_key])
            ->assertOk()
            ->assertJson([
                'valid' => true,
                'latest_version' => '2026.08.07-test5',
                'latest_release_notes' => 'Notes here',
                'latest_release_checksum' => $release->checksum,
            ]);
    }
}
