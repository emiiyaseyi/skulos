<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\School;
use App\Models\Tenant\Setting;
use App\Models\User;
use App\Services\Auditing\IntegrityChecker;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tamper-evident hash chain, backfill, verify, and durable export commands
 * (2026-07-20 audit log hardening). See App\Services\Auditing\IntegrityChain
 * / IntegrityChecker and the three audit-logs:* artisan commands.
 */
class AuditLogIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
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

    public function test_sequential_writes_form_a_verifiable_chain(): void
    {
        School::factory()->count(3)->create();

        $result = app(IntegrityChecker::class)->verify(config('database.default'));

        $this->assertTrue($result['ok']);
        $this->assertGreaterThanOrEqual(3, $result['checked']);
        $this->assertNull($result['broken_at_id']);
    }

    public function test_each_row_chains_to_the_previous_rows_hash(): void
    {
        School::factory()->create();
        School::factory()->create();

        $logs = AuditLog::orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $logs->count());

        for ($i = 1; $i < $logs->count(); $i++) {
            $this->assertSame($logs[$i - 1]->hash, $logs[$i]->previous_hash);
        }
        $this->assertNull($logs->first()->previous_hash);
        $this->assertNotNull($logs->first()->hash);
    }

    public function test_tampering_a_row_outside_the_model_breaks_the_chain(): void
    {
        School::factory()->create(['name' => 'Original Name']);
        School::factory()->create();

        $tampered = AuditLog::orderBy('id')->first();

        // Bypasses AuditLog's immutable-model guard on purpose, simulating
        // exactly the kind of direct-database-access tampering this
        // feature exists to detect (raw SQL UPDATE, not $model->update()).
        DB::table('audit_logs')->where('id', $tampered->id)->update([
            'new_values' => json_encode(['name' => 'Forged Name']),
        ]);

        $result = app(IntegrityChecker::class)->verify(config('database.default'));

        $this->assertFalse($result['ok']);
        $this->assertSame($tampered->id, $result['broken_at_id']);
    }

    public function test_verify_command_exits_nonzero_when_broken(): void
    {
        School::factory()->create();
        $tampered = AuditLog::orderBy('id')->first();
        DB::table('audit_logs')->where('id', $tampered->id)->update(['action' => 'forged']);

        $this->artisan('audit-logs:verify', ['--connection' => 'platform'])
            ->assertExitCode(1);
    }

    public function test_verify_command_exits_zero_when_clean(): void
    {
        School::factory()->create();

        $this->artisan('audit-logs:verify', ['--connection' => 'platform'])
            ->assertExitCode(0);
    }

    public function test_build_chain_backfills_legacy_null_hash_rows(): void
    {
        // Simulates rows written before this feature existed: created via
        // a raw insert (as AuditLogBrowserTest already does for similar
        // reasons), never touching Recorder, so hash/previous_hash are null.
        DB::table('audit_logs')->insert([
            ['action' => 'legacy.one', 'created_at' => now()->subDays(2)],
            ['action' => 'legacy.two', 'created_at' => now()->subDay()],
        ]);

        $this->assertSame(2, AuditLog::whereNull('hash')->count());

        $this->artisan('audit-logs:build-chain', ['--connection' => 'platform'])->assertExitCode(0);

        $this->assertSame(0, AuditLog::whereNull('hash')->count());

        $result = app(IntegrityChecker::class)->verify(config('database.default'));
        $this->assertTrue($result['ok']);
    }

    public function test_build_chain_is_idempotent(): void
    {
        DB::table('audit_logs')->insert(['action' => 'legacy.one', 'created_at' => now()]);

        $this->artisan('audit-logs:build-chain', ['--connection' => 'platform'])->assertExitCode(0);
        $firstHash = AuditLog::first()->hash;

        $this->artisan('audit-logs:build-chain', ['--connection' => 'platform'])->assertExitCode(0);
        $this->assertSame($firstHash, AuditLog::first()->fresh()->hash);
    }

    public function test_each_school_has_its_own_independent_chain(): void
    {
        $tenants = app(TenantManager::class);
        $a = $tenants->provision(School::factory()->create(['slug' => 'chain-a']));
        $b = $tenants->provision(School::factory()->create(['slug' => 'chain-b']));

        $tenants->connectTo($a, function () {
            Setting::put('motto', 'Chain A');
        });
        $tenants->connectTo($b, function () {
            Setting::put('motto', 'Chain B');
        });

        $tenants->connectTo($a, function () {
            $result = app(IntegrityChecker::class)->verify('tenant');
            $this->assertTrue($result['ok']);
        });
        $tenants->connectTo($b, function () {
            $result = app(IntegrityChecker::class)->verify('tenant');
            $this->assertTrue($result['ok']);
        });
    }

    public function test_export_writes_an_ndjson_batch_and_advances_the_watermark(): void
    {
        Storage::fake('local');
        config(['skulos.audit_export_disk' => 'local']);

        School::factory()->create();
        $totalRows = AuditLog::count();
        $this->assertGreaterThan(0, $totalRows);

        $this->artisan('audit-logs:export', ['--connection' => 'platform'])->assertExitCode(0);

        $files = Storage::disk('local')->allFiles('audit-exports/platform');
        $this->assertNotEmpty($files);

        $lines = collect(explode("\n", trim(Storage::disk('local')->get($files[0]))));
        $this->assertSame($totalRows, $lines->count());

        $this->assertSame($totalRows, (int) PlatformSetting::grab('audit_log_export_watermark', 0));
    }

    public function test_export_does_not_reexport_already_shipped_rows(): void
    {
        Storage::fake('local');
        config(['skulos.audit_export_disk' => 'local']);

        School::factory()->create();
        $this->artisan('audit-logs:export', ['--connection' => 'platform'])->assertExitCode(0);
        $firstBatchFiles = count(Storage::disk('local')->allFiles('audit-exports/platform'));

        // No new audit activity since the last export: a second run should
        // produce no new file.
        $this->artisan('audit-logs:export', ['--connection' => 'platform'])->assertExitCode(0);
        $secondBatchFiles = count(Storage::disk('local')->allFiles('audit-exports/platform'));

        $this->assertSame($firstBatchFiles, $secondBatchFiles);
    }

    public function test_admin_can_trigger_a_verify_from_the_ui(): void
    {
        $admin = $this->superAdmin();
        School::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.audit-log.verify'))
            ->assertRedirect()
            ->assertSessionHas('status');
    }

    public function test_admin_ui_verify_reports_a_broken_chain(): void
    {
        $admin = $this->superAdmin();
        School::factory()->create();
        $tampered = AuditLog::orderBy('id')->first();
        DB::table('audit_logs')->where('id', $tampered->id)->update(['action' => 'forged']);

        $this->actingAs($admin)
            ->post(route('admin.audit-log.verify'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
