<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\PlatformBackup;
use App\Models\School;
use App\Models\User;
use App\Services\Maintenance\BackupRetentionService;
use App\Services\Maintenance\MaintenanceActionsService;
use App\Services\PlatformBackupService;
use App\Services\Tenancy\BackupService;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Automatic backup retention/pruning (2026-08-12): the single item that
 * grew unbounded with no cleanup at all, per school and the platform's
 * own alike, until now. A backup is kept if it satisfies EITHER
 * keep_count (among the most recent N) OR keep_days (newer than X
 * days) — only deleted once it fails both, per direct user choice.
 * Whether a manually-triggered backup is exempt or pruned the same as
 * a scheduled one is itself admin-editable (prune_manual), also per
 * direct user choice ("make it both, admin can decide").
 */
class BackupRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
        $this->seed(RbacSeeder::class);
        Storage::fake('local');

        // The test suite runs the platform database as :memory: (see
        // phpunit.xml), which has no real file for PlatformBackupService's
        // sqlite backup branch to copy — point it at a real throwaway
        // file instead, same workaround PlatformBackupTest already
        // established, just for the platform-backup-pruning tests below.
        $platformDbPath = storage_path('framework/testing/backup-retention-platform-'.getmypid().'.sqlite');
        file_put_contents($platformDbPath, 'SQLite format 3'.str_repeat("\0", 100));
        config(['database.connections.sqlite.database' => $platformDbPath]);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        $platformDbPath = storage_path('framework/testing/backup-retention-platform-'.getmypid().'.sqlite');
        if (file_exists($platformDbPath)) {
            unlink($platformDbPath);
        }
        parent::tearDown();
    }

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    /** Creates $count real scheduled backups for $school, oldest first, spaced $daysApart days, and returns them newest-last. */
    protected function makeAgedBackups(School $school, int $count, int $daysApart = 10): \Illuminate\Support\Collection
    {
        $backups = collect();
        for ($i = $count - 1; $i >= 0; $i--) {
            $backup = app(BackupService::class)->create($school, Backup::TYPE_SCHEDULED);
            $backup->forceFill(['created_at' => now()->subDays($i * $daysApart)])->save();
            $backups->push($backup);
        }

        return $backups;
    }

    public function test_a_backup_beyond_both_keep_count_and_keep_days_is_pruned(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'brt1']));
        $backups = $this->makeAgedBackups($school, 3, daysApart: 40);
        // 3 backups at day 0, 40, 80 ago. keep_count=2 keeps the two most
        // recent (day-0, day-40); keep_days=10 alone would keep only
        // day-0. Combined (OR), day-40 survives via keep_count even
        // though it fails keep_days; only day-80 fails both.
        app(BackupRetentionService::class)->savePlatformSettings(['keep_count' => 2, 'keep_days' => 10, 'prune_manual' => false]);

        $result = app(MaintenanceActionsService::class)->pruneBackups($school, app(BackupRetentionService::class));

        $this->assertSame(1, $result['deleted']);
        $this->assertSame(2, Backup::where('school_id', $school->id)->count());
        $this->assertNull(Backup::find($backups->first()->id), 'the oldest backup row should be deleted');
    }

    public function test_a_backup_kept_by_either_rule_survives(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'brt2']));
        // 3 backups, 0/15/40 days ago. keep_count=1 alone would prune the
        // middle and oldest; keep_days=20 alone would prune only the
        // oldest. Combined (OR), only the day-40 one fails both.
        $backups = collect([40, 15, 0])->map(function ($days) use ($school) {
            $b = app(BackupService::class)->create($school, Backup::TYPE_SCHEDULED);
            $b->forceFill(['created_at' => now()->subDays($days)])->save();

            return $b;
        });
        app(BackupRetentionService::class)->savePlatformSettings(['keep_count' => 1, 'keep_days' => 20, 'prune_manual' => false]);

        $result = app(MaintenanceActionsService::class)->pruneBackups($school, app(BackupRetentionService::class));

        $this->assertSame(1, $result['deleted']);
        $this->assertSame(2, Backup::where('school_id', $school->id)->count());
    }

    public function test_manual_backups_are_exempt_by_default(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'brt3']));
        $manual = app(BackupService::class)->create($school, Backup::TYPE_MANUAL);
        $manual->forceFill(['created_at' => now()->subDays(200)])->save();
        app(BackupRetentionService::class)->savePlatformSettings(['keep_count' => 0, 'keep_days' => 1, 'prune_manual' => false]);

        $result = app(MaintenanceActionsService::class)->pruneBackups($school, app(BackupRetentionService::class));

        $this->assertSame(0, $result['deleted']);
        $this->assertNotNull(Backup::find($manual->id), 'a manual backup should survive when prune_manual is off, however old');
    }

    public function test_manual_backups_are_pruned_when_the_admin_opts_in(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'brt4']));
        $manual = app(BackupService::class)->create($school, Backup::TYPE_MANUAL);
        $manual->forceFill(['created_at' => now()->subDays(200)])->save();
        app(BackupRetentionService::class)->savePlatformSettings(['keep_count' => 0, 'keep_days' => 1, 'prune_manual' => true]);

        $result = app(MaintenanceActionsService::class)->pruneBackups($school, app(BackupRetentionService::class));

        $this->assertSame(1, $result['deleted']);
        $this->assertNull(Backup::find($manual->id));
    }

    public function test_a_schools_own_override_beats_the_platform_default(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'brt5']));
        $backup = app(BackupService::class)->create($school, Backup::TYPE_SCHEDULED);
        $backup->forceFill(['created_at' => now()->subDays(50)])->save();

        // Platform default would keep it (generous), the school's own
        // override is strict enough to prune it.
        app(BackupRetentionService::class)->savePlatformSettings(['keep_count' => 10, 'keep_days' => 365, 'prune_manual' => false]);

        app(TenantManager::class)->use($school);
        app(BackupRetentionService::class)->saveSchoolSettings(['keep_count' => 0, 'keep_days' => 1, 'prune_manual' => false]);
        $result = app(MaintenanceActionsService::class)->pruneBackups($school, app(BackupRetentionService::class));
        app(TenantManager::class)->forget();

        $this->assertSame(1, $result['deleted']);
    }

    public function test_platform_backups_are_pruned_the_same_way(): void
    {
        // 3 backups at day 0, 40, 80 ago, same reasoning as the per-school
        // test above: keep_count=2 keeps day-0 and day-40, only day-80
        // fails both rules.
        for ($i = 0; $i < 3; $i++) {
            $backup = app(PlatformBackupService::class)->create(PlatformBackup::TYPE_SCHEDULED);
            $backup->forceFill(['created_at' => now()->subDays($i * 40)])->save();
        }
        app(BackupRetentionService::class)->savePlatformSettings(['keep_count' => 2, 'keep_days' => 10, 'prune_manual' => false]);

        $result = app(PlatformBackupService::class)->prune(app(BackupRetentionService::class));

        $this->assertSame(1, $result['deleted']);
        $this->assertSame(2, PlatformBackup::count());
    }

    public function test_the_weekly_retention_cleanup_command_prunes_backups_across_every_school(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'brt6']));
        $backup = app(BackupService::class)->create($school, Backup::TYPE_SCHEDULED);
        $backup->forceFill(['created_at' => now()->subDays(200)])->save();
        app(BackupRetentionService::class)->savePlatformSettings(['keep_count' => 0, 'keep_days' => 1, 'prune_manual' => false]);
        app(TenantManager::class)->forget();

        $this->artisan('skulos:retention-cleanup')->assertSuccessful();

        $this->assertNull(Backup::find($backup->id));
    }

    public function test_the_school_can_set_its_own_backup_retention_from_settings(): void
    {
        $rbac = app(\App\Services\Rbac\Rbac::class);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'brt7']));
        $owner = User::factory()->create();
        $role = $rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/retention/backups", [
            'use_own_backup' => '1', 'keep_count' => 5, 'keep_days' => 45, 'prune_manual' => '1',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $saved = app(BackupRetentionService::class)->schoolSettings();
        app(TenantManager::class)->forget();

        $this->assertSame(5, $saved['keep_count']);
        $this->assertSame(45, $saved['keep_days']);
        $this->assertTrue($saved['prune_manual']);
    }

    public function test_admin_can_prune_all_backups_across_every_school_and_the_platform_at_once(): void
    {
        $admin = $this->superAdmin();
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'brt8']));
        $schoolBackup = app(BackupService::class)->create($school, Backup::TYPE_SCHEDULED);
        $schoolBackup->forceFill(['created_at' => now()->subDays(200)])->save();
        $platformBackup = app(PlatformBackupService::class)->create(PlatformBackup::TYPE_SCHEDULED);
        $platformBackup->forceFill(['created_at' => now()->subDays(200)])->save();
        app(BackupRetentionService::class)->savePlatformSettings(['keep_count' => 0, 'keep_days' => 1, 'prune_manual' => false]);

        $this->actingAs($admin)->post(route('admin.backups.prune-all'))->assertRedirect();

        $this->assertNull(Backup::find($schoolBackup->id));
        $this->assertNull(PlatformBackup::find($platformBackup->id));
    }
}
