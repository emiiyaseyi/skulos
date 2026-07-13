<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Manage Modules (Dashboard Design Cross-Check, 2026-07-13: "Blocked, no
 * dedicated modules screen; module state is set per school inside Billing
 * today"). A cross-school matrix over the same real module_states data,
 * toggled through the exact same BillingController::toggleFeature() route
 * already proven by BillingTest, not a new mutation path.
 */
class ManageModulesTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_matrix_shows_real_schools_and_module_states(): void
    {
        $admin = $this->superAdmin();
        $school = app(TenantManager::class)->provision(School::factory()->create(['name' => 'Matrix School']));
        $module = Module::where('is_core', false)->first();
        app(Rbac::class)->setModuleState($school, $module->code, Module::STATE_ENABLED);

        $this->actingAs($admin)->get(route('admin.modules.index'))
            ->assertOk()
            ->assertSee('Matrix School')
            ->assertSee($module->name);
    }

    public function test_toggling_from_the_matrix_uses_the_real_billing_toggle_and_persists(): void
    {
        $admin = $this->superAdmin();
        $school = app(TenantManager::class)->provision(School::factory()->create());
        $module = Module::where('is_core', false)->first();

        $this->assertSame(Module::STATE_DISABLED, app(Rbac::class)->moduleState($school, $module->code));

        $this->actingAs($admin)->post(route('admin.billing.schools.toggle-feature', ['school' => $school->id]), [
            'module' => $module->code,
        ])->assertRedirect();

        $this->assertSame(Module::STATE_ENABLED, app(Rbac::class)->moduleState($school, $module->code));
    }

    public function test_a_non_super_admin_cannot_view_manage_modules(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.modules.index'))->assertNotFound();
    }
}
