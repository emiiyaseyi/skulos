<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\Tenant\Setting;
use App\Models\User;
use App\Services\Auditing\Recorder;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AuditTrailTest extends TestCase
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

    public function test_creating_a_platform_model_writes_a_created_log(): void
    {
        $school = School::factory()->create(['name' => 'Audit High']);

        $log = AuditLog::where('auditable_type', School::class)
            ->where('auditable_id', $school->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Audit High', $log->new_values['name']);
        $this->assertNull($log->old_values);
    }

    public function test_updates_log_only_the_changed_attributes_with_old_and_new(): void
    {
        $school = School::factory()->create(['name' => 'Before Name']);

        $school->update(['name' => 'After Name']);

        $log = AuditLog::where('auditable_type', School::class)
            ->where('auditable_id', $school->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(['name' => 'Before Name'], $log->old_values);
        $this->assertSame(['name' => 'After Name'], $log->new_values);
    }

    public function test_soft_delete_and_restore_are_both_logged(): void
    {
        $school = School::factory()->create();

        $school->delete();
        $school->restore();

        $actions = AuditLog::where('auditable_type', School::class)
            ->where('auditable_id', $school->id)
            ->pluck('action');

        $this->assertContains('deleted', $actions);
        $this->assertContains('restored', $actions);
    }

    public function test_hidden_attributes_never_reach_the_log(): void
    {
        $user = User::factory()->create();

        $logs = AuditLog::where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->get();

        $this->assertNotEmpty($logs);
        foreach ($logs as $log) {
            $this->assertArrayNotHasKey('password', $log->new_values ?? []);
            $this->assertArrayNotHasKey('remember_token', $log->new_values ?? []);
            $this->assertArrayNotHasKey('is_super_admin', $log->new_values ?? []);
        }
    }

    public function test_tenant_model_audits_stay_in_the_tenant_database(): void
    {
        $tenants = app(TenantManager::class);
        $school = $tenants->provision(School::factory()->create(['slug' => 'audited']));

        $tenants->connectTo($school, function () {
            Setting::put('motto', 'Truth and Service');

            $tenantLog = AuditLog::on('tenant')
                ->where('auditable_type', Setting::class)
                ->where('action', 'created')
                ->first();

            $this->assertNotNull($tenantLog);
            $this->assertSame('Truth and Service', json_decode($tenantLog->new_values['value']));
        });

        // Nothing about the Setting leaked into the platform audit table.
        $this->assertSame(
            0,
            AuditLog::where('auditable_type', Setting::class)->count(),
        );
    }

    public function test_acting_user_is_recorded(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);

        $school = School::factory()->create();

        $log = AuditLog::where('auditable_type', School::class)
            ->where('auditable_id', $school->id)
            ->where('action', 'created')
            ->first();

        $this->assertSame($actor->id, $log->user_id);
    }

    public function test_custom_actions_can_be_recorded(): void
    {
        $school = School::factory()->create();

        app(Recorder::class)->record('results.published', $school, [], ['term' => 'third']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'results.published',
            'auditable_type' => School::class,
            'auditable_id' => $school->id,
        ]);
    }

    public function test_audit_logs_are_immutable(): void
    {
        School::factory()->create();
        $log = AuditLog::firstOrFail();

        try {
            $log->update(['action' => 'tampered']);
            $this->fail('Expected update to throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit logs are immutable.', $e->getMessage());
        }

        try {
            $log->delete();
            $this->fail('Expected delete to throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit logs cannot be deleted.', $e->getMessage());
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }
}
