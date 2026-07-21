<?php

namespace Tests\Feature;

use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Live bug found 2026-07-21 ("the page just keeps reloading"): an
 * unauthenticated request to a protected route on a school's own
 * subdomain (a session that had genuinely expired, e.g. via the idle
 * timeout) redirected to the platform admin's bare-domain login instead
 * of that school's own branded one — Laravel's default guest redirect
 * just calls route('login') unconditionally, the one route literally
 * named that, regardless of which host the request actually came in on.
 * Fixed in bootstrap/app.php via a domain-aware redirectGuestsTo closure.
 */
class DomainAwareGuestRedirectTest extends TestCase
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
        app(\App\Services\Tenancy\TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    public function test_an_unauthenticated_school_subdomain_request_redirects_to_that_schools_own_login(): void
    {
        $school = app(\App\Services\Tenancy\TenantManager::class)->provision(School::factory()->create(['slug' => 'redirectcheck']));

        $this->get('http://redirectcheck.localhost/dashboard')
            ->assertRedirect('http://redirectcheck.localhost/login');
    }

    public function test_an_unauthenticated_platform_admin_request_still_redirects_to_the_bare_domain_login(): void
    {
        $this->get('http://localhost/admin')
            ->assertRedirect(route('login'));
    }
}
