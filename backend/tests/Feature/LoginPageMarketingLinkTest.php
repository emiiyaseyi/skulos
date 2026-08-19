<?php

namespace Tests\Feature;

use App\Models\School;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * "On each login page, make the SKULOS logo clickable which takes one
 * to the marketing homepage" (2026-08-19). Both screens tested here
 * render from a school's own subdomain, which is exactly the case
 * where a naive plain "/" link would be wrong (it would hit that
 * subdomain's own root, not the real marketing site).
 */
class LoginPageMarketingLinkTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config([
            'tenancy.sqlite_path' => $this->tenantDir,
            'tenancy.driver' => 'sqlite',
            'app.domain' => 'localhost',
            'app.url' => 'https://localhost',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    public function test_the_school_login_page_links_the_skulos_wordmark_to_the_real_marketing_root(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'linktest']));

        $this->get("http://{$school->slug}.localhost/login?as=staff")
            ->assertOk()
            // The bare marketing root, not this subdomain's own "/".
            ->assertSee('href="https://localhost/"', false);
    }

    public function test_every_login_layout_variant_links_to_the_marketing_root(): void
    {
        // The 'split' layout is what the earlier screenshot showed;
        // covering it too, not just the 'centered' default, confirms
        // the fix lives in the one shared partial every layout includes
        // rather than accidentally only working for one of them.
        $school = app(TenantManager::class)->provision(
            School::factory()->create(['slug' => 'linktesttwo', 'login_layout' => 'split'])
        );

        $this->get("http://{$school->slug}.localhost/login?as=staff")
            ->assertOk()
            ->assertSee('href="https://localhost/"', false);
    }
}
