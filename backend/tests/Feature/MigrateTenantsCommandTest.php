<?php

namespace Tests\Feature;

use App\Models\School;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression coverage for a real live bug (2026-07-11): a school already
 * provisioned before a new tenant migration is added never receives it
 * on its own, TenantManager::migrate() has to be run against it again.
 * This command is that "again", across every provisioned school at once.
 */
class MigrateTenantsCommandTest extends TestCase
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

    public function test_it_migrates_every_provisioned_school(): void
    {
        $tenants = app(TenantManager::class);
        $one = $tenants->provision(School::factory()->create(['slug' => 'migone']));
        $two = $tenants->provision(School::factory()->create(['slug' => 'migtwo']));

        // Simulate "a migration was added after these schools already existed":
        // remove both the schema change AND its row in this tenant's own
        // migrations table, exactly what an old tenant database looks like
        // for a migration file that did not exist yet when it was provisioned.
        // Just dropping the column would not be enough, Laravel's migrate only
        // re-runs migrations missing from that tracking table, not ones it
        // already believes ran.
        foreach ([$one, $two] as $school) {
            $tenants->use($school);
            DB::connection('tenant')->table('migrations')
                ->where('migration', 'like', '%add_is_active_to_houses%')->delete();
            Schema::connection('tenant')->table('houses', fn ($t) => $t->dropColumn('is_active'));
            $tenants->forget();
        }

        $this->artisan('tenants:migrate')->assertSuccessful();

        $tenants->use($one);
        $this->assertContains('is_active', Schema::connection('tenant')->getColumnListing('houses'));
        $tenants->forget();
        $tenants->use($two);
        $this->assertContains('is_active', Schema::connection('tenant')->getColumnListing('houses'));
        $tenants->forget();
    }

    public function test_an_unprovisioned_school_is_skipped_without_error(): void
    {
        School::factory()->create(['slug' => 'neverprovisioned', 'database' => null, 'provisioned_at' => null]);

        $this->artisan('tenants:migrate')->assertSuccessful();
    }
}
