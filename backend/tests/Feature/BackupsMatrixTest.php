<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\School;
use App\Models\User;
use App\Services\Tenancy\BackupService;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Consolidated backups matrix (user request 2026-07-18: "so many features
 * that we have in the school end are missing under the platform owner
 * end"). Previously the platform admin could only see/initiate a backup
 * one school at a time from that school's own detail page.
 */
class BackupsMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
        $this->seed(RbacSeeder::class);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_the_matrix_shows_every_school_with_its_last_backup(): void
    {
        $admin = $this->superAdmin();
        $school = app(TenantManager::class)->provision(School::factory()->create(['name' => 'Backup School', 'backup_frequency' => 'daily', 'backup_time' => '02:00']));
        app(BackupService::class)->create($school, Backup::TYPE_MANUAL, $admin);

        $this->actingAs($admin)->get(route('admin.backups.index'))
            ->assertOk()
            ->assertSee('Backup School')
            ->assertSee('daily')
            ->assertSee('Up to date');
    }

    public function test_a_school_overdue_its_own_schedule_is_flagged(): void
    {
        $admin = $this->superAdmin();
        $school = app(TenantManager::class)->provision(School::factory()->create(['name' => 'Overdue School', 'backup_frequency' => 'daily', 'backup_time' => '02:00']));
        $backup = app(BackupService::class)->create($school, Backup::TYPE_MANUAL, $admin);
        $backup->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->actingAs($admin)->get(route('admin.backups.index'))
            ->assertOk()->assertSee('Overdue');
    }

    public function test_a_school_that_never_had_a_backup_is_flagged_overdue(): void
    {
        $admin = $this->superAdmin();
        app(TenantManager::class)->provision(School::factory()->create(['name' => 'Never Backed Up', 'backup_frequency' => 'weekly', 'backup_time' => '02:00']));

        $this->actingAs($admin)->get(route('admin.backups.index'))
            ->assertOk()->assertSee('Never Backed Up')->assertSee('Never')->assertSee('Overdue');
    }

    public function test_a_school_with_no_schedule_shows_no_schedule_not_overdue(): void
    {
        $admin = $this->superAdmin();
        app(TenantManager::class)->provision(School::factory()->create(['name' => 'No Schedule School', 'backup_frequency' => 'none']));

        $this->actingAs($admin)->get(route('admin.backups.index'))
            ->assertOk()->assertSee('No schedule')->assertDontSee('Overdue');
    }

    public function test_back_up_now_from_the_matrix_creates_a_real_backup(): void
    {
        $admin = $this->superAdmin();
        $school = app(TenantManager::class)->provision(School::factory()->create(['name' => 'On Demand School']));

        $this->assertSame(0, Backup::where('school_id', $school->id)->count());

        $this->actingAs($admin)->post(route('admin.schools.backups.store', ['school' => $school->id]))
            ->assertRedirect();

        $this->assertSame(1, Backup::where('school_id', $school->id)->count());
    }

    public function test_a_non_super_admin_cannot_view_the_backups_matrix(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.backups.index'))->assertNotFound();
    }
}
