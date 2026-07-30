<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Role;
use App\Models\School;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Services\Tenancy\BackupService;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Backup and restore per school (user request 2026-07-09): the school
 * admin or the platform admin can trigger a backup, download it, or
 * restore from it, plus a default schedule.
 */
class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
        Storage::fake('local');
        $this->seed(RbacSeeder::class);
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
        $ownerRole = app(\App\Services\Rbac\Rbac::class)->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        return [$school, $owner];
    }

    public function test_a_backup_can_be_taken_and_downloaded(): void
    {
        [$school, $owner] = $this->schoolWithOwner('backupone');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/backups", [])->assertRedirect();

        $backup = Backup::where('school_id', $school->id)->first();
        $this->assertNotNull($backup);
        $this->assertSame(Backup::TYPE_MANUAL, $backup->type);
        $this->assertTrue($backup->size_bytes > 0);
        $this->assertTrue($backup->exists());

        $this->actingAs($owner)
            ->get("http://{$school->slug}.localhost/backups/{$backup->id}/download")
            ->assertOk();
    }

    public function test_restoring_a_backup_brings_back_data_created_before_it_and_drops_data_created_after(): void
    {
        [$school, $owner] = $this->schoolWithOwner('backuprestore');

        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'BEFORE/1', 'first_name' => 'Before', 'last_name' => 'Backup']);
        app(TenantManager::class)->forget();

        $backup = app(BackupService::class)->create($school);

        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'AFTER/1', 'first_name' => 'After', 'last_name' => 'Backup']);
        $countBeforeRestore = Student::count();
        app(TenantManager::class)->forget();
        $this->assertSame(2, $countBeforeRestore);

        $this->actingAs($owner)
            ->post("http://{$school->slug}.localhost/backups/{$backup->id}/restore")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $admissionNumbers = Student::pluck('admission_number')->all();
        app(TenantManager::class)->forget();

        $this->assertSame(['BEFORE/1'], $admissionNumbers);
        $this->assertNotNull($backup->fresh()->restored_at);
    }

    /**
     * Regression test for a real hazard found in a 2026-07-30 code audit:
     * the old restore() dropped/overwrote the live tenant database before
     * confirming the backup was even valid, so a corrupt backup file left
     * the school with an empty database and no way back. The new version
     * writes to a throwaway location, verifies it, and only swaps it in
     * if verification passes.
     */
    public function test_restoring_a_corrupt_backup_file_leaves_the_live_database_untouched(): void
    {
        [$school, $owner] = $this->schoolWithOwner('corruptrestore');

        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'SAFE/1', 'first_name' => 'Still', 'last_name' => 'Here']);
        app(TenantManager::class)->forget();

        $backup = Backup::create([
            'school_id' => $school->id,
            'disk' => 'local',
            'file_path' => "backups/{$school->id}/corrupt.sqlite",
            'size_bytes' => 20,
            'type' => Backup::TYPE_MANUAL,
        ]);
        Storage::disk('local')->put($backup->file_path, 'this is not a real sqlite database file');

        $response = $this->actingAs($owner)
            ->post("http://{$school->slug}.localhost/backups/{$backup->id}/restore");

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertNull($backup->fresh()->restored_at);

        app(TenantManager::class)->use($school);
        $admissionNumbers = Student::pluck('admission_number')->all();
        app(TenantManager::class)->forget();

        $this->assertSame(['SAFE/1'], $admissionNumbers, 'the live database must be untouched after a failed restore');
    }

    public function test_platform_admin_can_also_trigger_and_restore_a_backup(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        [$school] = $this->schoolWithOwner('adminbackup');

        $this->actingAs($admin)
            ->post(route('admin.schools.backups.store', $school->id))
            ->assertRedirect();

        $backup = Backup::where('school_id', $school->id)->first();
        $this->assertNotNull($backup);

        $this->actingAs($admin)
            ->post(route('admin.schools.backups.restore', ['school' => $school->id, 'backupId' => $backup->id]))
            ->assertRedirect();

        $this->assertNotNull($backup->fresh()->restored_at);
    }

    public function test_school_admin_can_set_a_backup_schedule(): void
    {
        [$school, $owner] = $this->schoolWithOwner('scheduleone');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/backups/schedule", [
            'backup_frequency' => 'daily',
            'backup_time' => '03:30',
        ])->assertRedirect();

        $school->refresh();
        $this->assertSame('daily', $school->backup_frequency);
        $this->assertSame('03:30', $school->backup_time);
    }

    public function test_platform_admin_can_also_set_a_backup_schedule(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        [$school] = $this->schoolWithOwner('adminschedule');

        $this->actingAs($admin)->post(route('admin.schools.backups.schedule', $school->id), [
            'backup_frequency' => 'weekly',
            'backup_time' => '04:15',
        ])->assertRedirect();

        $school->refresh();
        $this->assertSame('weekly', $school->backup_frequency);
        $this->assertSame('04:15', $school->backup_time);
    }

    /**
     * Regression test for the 2026-07-10 fix: the scheduled command used
     * to run hourly (only at "HH:00"), so a schedule set to any other
     * minute, like the 03:30 used in the test above, could never actually
     * fire. This proves a non "HH:00" time is now picked up.
     */
    public function test_a_backup_due_at_a_non_zero_minute_actually_runs(): void
    {
        [$school] = $this->schoolWithOwner('duebackup');
        $school->update(['backup_frequency' => 'daily', 'backup_time' => '03:30']);

        $this->travelTo(now()->setTime(3, 30));
        $this->artisan('backups:run-scheduled');

        $this->assertSame(1, Backup::where('school_id', $school->id)->count());

        $this->travelBack();
    }

    public function test_a_non_admin_member_cannot_manage_backups(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'nobackup']));
        $teacherRole = app(\App\Services\Rbac\Rbac::class)->cloneTemplateRole($school, 'subject-teacher');
        $teacher = User::factory()->create();
        $school->users()->attach($teacher->id, ['role_id' => $teacherRole->id, 'status' => 'active']);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/backups", [])->assertForbidden();
    }
}
