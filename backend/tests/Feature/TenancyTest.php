<?php

namespace Tests\Feature;

use App\Models\School;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep test tenant databases away from dev ones, and clean per run.
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);

        parent::tearDown();
    }

    public function test_provisioning_creates_a_dedicated_database_with_tenant_schema(): void
    {
        $school = School::factory()->create(['slug' => 'greenfield-academy']);

        app(TenantManager::class)->provision($school);

        $school->refresh();
        $this->assertSame('greenfield_academy', $school->database);
        $this->assertSame(School::STATUS_ACTIVE, $school->status);
        $this->assertNotNull($school->provisioned_at);
        $this->assertFileExists($this->tenantDir.DIRECTORY_SEPARATOR.'greenfield_academy.sqlite');

        app(TenantManager::class)->connectTo($school, function () {
            $this->assertTrue(Schema::connection('tenant')->hasTable('settings'));
        });
    }

    public function test_two_schools_have_fully_isolated_databases(): void
    {
        $tenants = app(TenantManager::class);

        $alpha = $tenants->provision(School::factory()->create(['slug' => 'alpha']));
        $beta = $tenants->provision(School::factory()->create(['slug' => 'beta']));

        $tenants->connectTo($alpha, function () {
            DB::connection('tenant')->table('settings')->insert([
                'key' => 'motto',
                'value' => json_encode('Knowledge is light'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $tenants->connectTo($beta, function () {
            $this->assertSame(0, DB::connection('tenant')->table('settings')->count());
        });

        $tenants->connectTo($alpha, function () {
            $this->assertSame(1, DB::connection('tenant')->table('settings')->count());
        });
    }

    public function test_provisioning_is_idempotent(): void
    {
        $tenants = app(TenantManager::class);
        $school = $tenants->provision(School::factory()->create(['slug' => 'once']));

        $provisionedAt = $school->provisioned_at;
        $tenants->provision($school->refresh());

        $this->assertEquals($provisionedAt, $school->refresh()->provisioned_at);
    }

    public function test_middleware_resolves_school_from_header(): void
    {
        $school = app(TenantManager::class)->provision(
            School::factory()->create(['slug' => 'resolved'])
        );

        Route::middleware(['web', 'tenant'])->get('/_test/whoami', function () {
            return response()->json(['school' => request()->attributes->get('school')->slug]);
        });

        $this->withHeader('X-School', 'resolved')
            ->getJson('/_test/whoami')
            ->assertOk()
            ->assertJson(['school' => 'resolved']);
    }

    /** A stray mixed-case slug must still resolve, not 404 (see SchoolAdminManagementTest's matching creation-side regression test). */
    public function test_middleware_resolves_school_case_insensitively(): void
    {
        $school = app(TenantManager::class)->provision(
            School::factory()->create(['slug' => 'training'])
        );

        Route::middleware(['web', 'tenant'])->get('/_test/whoami', function () {
            return response()->json(['school' => request()->attributes->get('school')->slug]);
        });

        $this->withHeader('X-School', 'Training')
            ->getJson('/_test/whoami')
            ->assertOk()
            ->assertJson(['school' => 'training']);
    }

    public function test_middleware_rejects_unknown_and_unprovisioned_schools(): void
    {
        Route::middleware(['web', 'tenant'])->get('/_test/whoami', fn () => 'ok');

        $this->withHeader('X-School', 'ghost')->getJson('/_test/whoami')->assertNotFound();

        School::factory()->create(['slug' => 'unprovisioned']);
        $this->withHeader('X-School', 'unprovisioned')->getJson('/_test/whoami')->assertNotFound();
    }

    public function test_middleware_rejects_suspended_schools(): void
    {
        $school = app(TenantManager::class)->provision(
            School::factory()->create(['slug' => 'naughty'])
        );
        $school->update(['status' => School::STATUS_SUSPENDED]);

        Route::middleware(['web', 'tenant'])->get('/_test/whoami', fn () => 'ok');

        $this->withHeader('X-School', 'naughty')->getJson('/_test/whoami')->assertForbidden();
    }
}
