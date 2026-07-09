<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Campus;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CampusTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected Rbac $rbac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
        $this->seed(RbacSeeder::class);
        $this->rbac = app(Rbac::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function schoolWithMember(string $roleSlug, string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_admin_can_add_and_remove_a_campus(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'campusone');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/campuses", [
            'name' => 'Main Campus', 'address' => '1 School Road', 'is_main' => '1',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $campus = Campus::where('name', 'Main Campus')->first();
        app(TenantManager::class)->forget();
        $this->assertNotNull($campus);
        $this->assertTrue($campus->is_main);

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/campuses")->assertOk()->assertSee('Main Campus');

        $this->actingAs($admin)->delete("http://{$school->slug}.localhost/campuses/{$campus->id}")->assertRedirect();

        app(TenantManager::class)->use($school);
        $remaining = Campus::count();
        app(TenantManager::class)->forget();
        $this->assertSame(0, $remaining);
    }

    public function test_only_one_main_campus_at_a_time(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'campustwo');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/campuses", ['name' => 'Site A', 'is_main' => '1']);
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/campuses", ['name' => 'Site B', 'is_main' => '1']);

        app(TenantManager::class)->use($school);
        $mains = Campus::where('is_main', true)->count();
        app(TenantManager::class)->forget();
        $this->assertSame(1, $mains);
    }

    public function test_a_parent_cannot_manage_campuses(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'campusthree');

        $this->actingAs($parent)->post("http://{$school->slug}.localhost/campuses", ['name' => 'Somewhere'])
            ->assertForbidden();
    }
}
