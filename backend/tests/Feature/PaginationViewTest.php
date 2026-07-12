<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every ->links() call in the app must render SKULOS's own custom
 * pagination view, not Laravel's default (Tailwind assumed) one. Found
 * live 2026-07-12: "check this big arrows on the page, fix" — the
 * default view's prev/next SVG icons have no width/height and rely on
 * Tailwind utility classes SKULOS does not load, so they rendered at the
 * browser's raw SVG fallback size, filling most of the page.
 */
class PaginationViewTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    public function test_a_paginated_page_uses_the_custom_view_not_the_unstyled_default(): void
    {
        $rbac = app(Rbac::class);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'pagerone']));
        $user = User::factory()->create();
        $role = $rbac->cloneTemplateRole($school, 'registrar');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        for ($i = 1; $i <= 25; $i++) {
            Student::create(['admission_number' => "ADM/{$i}", 'first_name' => "Student{$i}", 'last_name' => 'Match']);
        }
        app(TenantManager::class)->forget();

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/students?q=Match");

        $response->assertOk()
            ->assertSee('class="pager"', false)
            ->assertSee('Page 1 of 2')
            // Laravel's default tailwind pagination view wraps prev/next in
            // an SVG with these exact Tailwind utility classes, their
            // absence confirms the default view is not the one rendering.
            ->assertDontSee('stroke-width="1.5"', false)
            ->assertDontSee('rtl:rotate-180', false);
    }
}
