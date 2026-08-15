<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * "Demo account" support (2026-08-15): a school_user row flagged
 * is_demo_readonly can never make any change, including its own
 * password, even though it's a completely ordinary authenticated
 * member otherwise. Scoped per school-membership, not the User itself.
 */
class DemoAccountTest extends TestCase
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

    public function test_a_demo_account_cannot_change_its_own_password(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'demoschool']));
        $user = User::factory()->create(['password' => bcrypt('original-pass')]);
        $role = app(Rbac::class)->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active', 'is_demo_readonly' => true]);

        $response = $this->actingAs($user)->post("http://{$school->slug}.localhost/password/change", [
            'current_password' => 'original-pass',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('original-pass', $user->fresh()->password));
    }

    public function test_a_demo_account_can_still_view_pages_normally(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'demoschool2']));
        $user = User::factory()->create();
        $role = app(Rbac::class)->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active', 'is_demo_readonly' => true]);

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");

        $response->assertOk();
    }

    public function test_an_ordinary_non_demo_account_can_still_change_its_own_password(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'demoschool3']));
        $user = User::factory()->create(['password' => bcrypt('original-pass')]);
        $role = app(Rbac::class)->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($user)->post("http://{$school->slug}.localhost/password/change", [
            'current_password' => 'original-pass',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ]);

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('a-new-password', $user->fresh()->password));
    }

    public function test_the_demo_flag_is_scoped_to_one_school_membership_only(): void
    {
        $demoSchool = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'demoschool4']));
        $realSchool = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'realschool4']));
        $user = User::factory()->create(['password' => bcrypt('original-pass')]);

        $demoRole = app(Rbac::class)->cloneTemplateRole($demoSchool, 'class-teacher');
        $realRole = app(Rbac::class)->cloneTemplateRole($realSchool, 'class-teacher');
        $demoSchool->users()->attach($user->id, ['role_id' => $demoRole->id, 'status' => 'active', 'is_demo_readonly' => true]);
        $realSchool->users()->attach($user->id, ['role_id' => $realRole->id, 'status' => 'active']);

        $this->actingAs($user)->post("http://{$realSchool->slug}.localhost/password/change", [
            'current_password' => 'original-pass',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ]);

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('a-new-password', $user->fresh()->password));
    }
}
