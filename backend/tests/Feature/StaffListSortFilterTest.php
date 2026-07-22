<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Staff;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** User report 2026-07-21: "Staff and parent list does not have the sort and filter option." */
class StaffListSortFilterTest extends TestCase
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

    public function test_the_staff_list_can_be_filtered_by_status(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'staffsortone');

        app(TenantManager::class)->use($school);
        Staff::create(['staff_id' => 'S1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'status' => 'active']);
        Staff::create(['staff_id' => 'S2', 'first_name' => 'Bode', 'last_name' => 'Ade', 'status' => 'on_leave']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/staff?status=on_leave");

        $response->assertOk()->assertSee('Bode Ade')->assertDontSee('Ada Obi');
    }

    public function test_the_staff_list_can_be_sorted_by_position_descending(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'staffsorttwo');

        app(TenantManager::class)->use($school);
        Staff::create(['staff_id' => 'S1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Bursar', 'status' => 'active']);
        Staff::create(['staff_id' => 'S2', 'first_name' => 'Bode', 'last_name' => 'Ade', 'position' => 'Teacher', 'status' => 'active']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/staff?sort=position&dir=desc");

        $response->assertOk();
        $content = $response->getContent();
        $this->assertTrue(strpos($content, 'Teacher') < strpos($content, 'Bursar'));
    }
}
