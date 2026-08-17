<?php

namespace Tests\Feature;

use App\Models\School;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TenantManager now verifies a connection actually works (a real SELECT
 * 1, not just that a PDO object was built) every time it switches
 * schools, and gives it exactly one clean retry before giving up
 * (2026-08-17, found live: a database account stuck in a bad state made
 * every connection attempt hang indefinitely with no exception for
 * anything to react to — see TenantManager::verifyConnection()'s own
 * docblock for the full reasoning).
 *
 * What matters most here isn't proving a genuinely flaky connection
 * recovers on retry (that needs real infrastructure flakiness, not
 * something a unit test can honestly fabricate) — it's proving the
 * two things that would make this change unsafe if wrong: it must not
 * slow down or break the normal, healthy path, and a truly broken
 * connection must still fail loudly rather than hanging forever or
 * being silently swallowed.
 */
class TenantConnectionResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        \Illuminate\Support\Facades\File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    public function test_switching_to_a_healthy_school_still_works_exactly_as_before(): void
    {
        $manager = app(TenantManager::class);
        $school = $manager->provision(School::factory()->create(['slug' => 'resilient']));

        // The regression this change could realistically cause: an
        // extra query added to every connection switch that somehow
        // breaks the normal path. It must not.
        $manager->use($school);

        $this->assertSame(1, DB::connection('tenant')->select('select 1 as one')[0]->one);
    }

    public function test_a_genuinely_broken_tenant_connection_throws_rather_than_hanging_or_being_swallowed(): void
    {
        $manager = app(TenantManager::class);
        $school = School::factory()->create(['slug' => 'broken', 'database' => 'broken']);
        $school->forceFill(['status' => School::STATUS_ACTIVE, 'provisioned_at' => now()])->save();

        // A tenant sqlite path pointed at a directory that cannot exist
        // (a file sitting where a directory needs to be) is a real,
        // permanent failure — no retry could ever fix it, which is
        // exactly the case that must still surface as a real exception,
        // not a silently accepted "it's fine" or an infinite retry loop.
        $blocker = storage_path('framework/testing/tenants-blocker-'.getmypid());
        file_put_contents($blocker, 'x');
        config(['tenancy.sqlite_path' => $blocker]);

        try {
            $this->expectException(\Throwable::class);
            $manager->use($school);
        } finally {
            @unlink($blocker);
        }
    }
}
