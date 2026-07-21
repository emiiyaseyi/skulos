<?php

namespace Tests\Feature;

use App\Models\PlatformBackup;
use App\Models\User;
use App\Services\PlatformBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Backups of the shared platform database itself (2026-07-21, a genuine
 * gap found during a deployment readiness review: every school's own
 * tenant database has had this since 2026-07-09, the platform database
 * never did). See PlatformBackup's migration docblock.
 */
class PlatformBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The test suite runs the platform database as :memory: (see
        // phpunit.xml), which has no real file for the sqlite backup
        // branch to copy — a genuine limitation of that connection, not
        // something a backup service can work around. Point it at a real
        // throwaway file instead, just for these tests.
        $path = storage_path('framework/testing/platform-backup-test-'.getmypid().'.sqlite');
        file_put_contents($path, 'SQLite format 3'.str_repeat("\0", 100));
        config(['database.connections.sqlite.database' => $path]);
    }

    protected function tearDown(): void
    {
        $path = storage_path('framework/testing/platform-backup-test-'.getmypid().'.sqlite');
        if (file_exists($path)) {
            unlink($path);
        }
        parent::tearDown();
    }

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_the_scheduled_command_creates_a_real_backup(): void
    {
        Storage::fake('local');

        $this->artisan('platform:backup')->assertExitCode(0);

        $backup = PlatformBackup::first();
        $this->assertNotNull($backup);
        $this->assertSame(PlatformBackup::TYPE_SCHEDULED, $backup->type);
        $this->assertNull($backup->initiated_by);
        $this->assertTrue($backup->exists());
        $this->assertGreaterThan(0, $backup->size_bytes);
    }

    public function test_admin_can_trigger_a_manual_backup_over_http(): void
    {
        Storage::fake('local');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.platform-backups.store'))
            ->assertRedirect();

        $backup = PlatformBackup::first();
        $this->assertSame(PlatformBackup::TYPE_MANUAL, $backup->type);
        $this->assertSame($admin->id, $backup->initiated_by);
    }

    public function test_admin_can_download_and_delete_a_backup(): void
    {
        Storage::fake('local');
        $admin = $this->superAdmin();
        $backup = app(PlatformBackupService::class)->create(PlatformBackup::TYPE_MANUAL, $admin);

        $this->actingAs($admin)
            ->get(route('admin.platform-backups.download', ['backupId' => $backup->id]))
            ->assertOk();

        $this->actingAs($admin)
            ->delete(route('admin.platform-backups.destroy', ['backupId' => $backup->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('platform_backups', ['id' => $backup->id]);
        Storage::disk('local')->assertMissing($backup->file_path);
    }

    public function test_a_non_super_admin_cannot_manage_platform_backups(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.platform-backups.index'))->assertNotFound();
    }
}
