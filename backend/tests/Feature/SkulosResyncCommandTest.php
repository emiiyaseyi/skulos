<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolAddOn;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * One command for the three "a catalog change never reaches an
 * already-provisioned school on its own" gotchas documented in CLAUDE.md
 * (tenant migrations, wildcard role permissions, module states), each
 * previously fixed by a one-off tinker script the next time it bit. This
 * proves the command actually performs all three, and that the module
 * state step (opt in, --modules) preserves an active SchoolAddOn grant
 * rather than reproducing BillingController::assignPlan()'s own blind
 * spot (a plan reassignment wipes any module granted beyond the plan).
 */
class SkulosResyncCommandTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected Rbac $rbac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
        $this->seed(RbacSeeder::class);
        $this->rbac = app(Rbac::class);
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
        $school = $tenants->provision(School::factory()->create(['slug' => 'resyncmig']));

        $tenants->use($school);
        DB::connection('tenant')->table('migrations')
            ->where('migration', 'like', '%add_is_active_to_houses%')->delete();
        Schema::connection('tenant')->table('houses', fn ($t) => $t->dropColumn('is_active'));
        $tenants->forget();

        $this->artisan('skulos:resync')->assertSuccessful();

        $tenants->use($school);
        $this->assertContains('is_active', Schema::connection('tenant')->getColumnListing('houses'));
        $tenants->forget();
    }

    /** The core regression this command exists for: a wildcard role cloned before a new permission existed must pick it up, without touching the school's own removals. */
    public function test_it_grants_a_permission_created_after_the_role_was_cloned_but_never_re_adds_an_older_one_the_school_removed(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'resyncperm']));
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');

        // Simulate a school admin deliberately unchecking a permission
        // that existed before (and at) clone time, the same real path
        // RoleManagementController::updateRolePermissions() uses,
        // including the touch() that records "this was a deliberate
        // choice, not just a gap".
        $removedOnPurpose = Permission::where('code', 'staff.delete')->first();
        $ownerRole->permissions()->detach($removedOnPurpose->id);
        $ownerRole->touch();

        // A real "developer adds a permission, deploys, resyncs" gap is
        // always seconds to days, never the same instant as the touch()
        // above, moved forward explicitly so the strictly-after comparison
        // this command relies on cannot collide on timestamp precision.
        $this->travel(1)->minute();

        // Simulate "a brand new extra_permissions entry was added"
        // (the exact scenario CLAUDE.md documents), the real mechanism
        // RbacSeeder itself creates and grants new permissions through,
        // so the seeding this command runs first naturally produces a
        // permission whose created_at is strictly after the role's own
        // last-touched time, the actual gotcha this command exists to fix.
        config(['skulos.extra_permissions.library' => ['checkout']]);

        $this->artisan('skulos:resync')->assertSuccessful();

        $newPermission = Permission::where('code', 'library.checkout')->firstOrFail();

        $ownerRole->refresh();
        $this->assertTrue($ownerRole->permissions()->whereKey($newPermission->id)->exists(), 'a permission created after the role was last touched must be added');
        $this->assertFalse($ownerRole->permissions()->whereKey($removedOnPurpose->id)->exists(), 'a school\'s own removal of a permission that already existed at that time must never be undone');
    }

    public function test_module_states_are_left_alone_without_the_modules_flag(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'resyncnomod']));
        $plan = Plan::create(['name' => 'Bare', 'slug' => 'bare-resync', 'price_per_student_per_term' => 0, 'modules' => [], 'features' => [], 'is_default' => false, 'is_active' => true, 'is_purchasable' => true, 'sort_order' => 1]);
        $school->update(['plan_id' => $plan->id]);
        Module::firstOrCreate(['code' => 'library'], ['name' => 'Library', 'is_core' => false]);
        $this->rbac->setModuleState($school, 'library', Module::STATE_ENABLED);

        $this->artisan('skulos:resync')->assertSuccessful();

        $this->assertSame(Module::STATE_ENABLED, $this->rbac->moduleState($school, 'library'), 'without --modules, existing module state must be untouched');
    }

    /** The improvement over assignPlan()'s own logic: an add on grant must survive a resync even though the module is not in the plan. */
    public function test_the_modules_flag_resyncs_from_the_plan_but_preserves_an_active_add_on_grant(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'resyncmod']));
        $plan = Plan::create(['name' => 'Bare', 'slug' => 'bare-resync-two', 'price_per_student_per_term' => 0, 'modules' => [], 'features' => [], 'is_default' => false, 'is_active' => true, 'is_purchasable' => true, 'sort_order' => 1]);
        $school->update(['plan_id' => $plan->id]);
        Module::firstOrCreate(['code' => 'library'], ['name' => 'Library', 'is_core' => false]);
        Module::firstOrCreate(['code' => 'hostel'], ['name' => 'Hostel', 'is_core' => false]);

        // library is granted via an add on, beyond the plan's own module list.
        SchoolAddOn::create(['school_id' => $school->id, 'add_on_code' => 'library']);
        $this->rbac->setModuleState($school, 'library', Module::STATE_ENABLED);
        // hostel was toggled on manually with no add on backing it.
        $this->rbac->setModuleState($school, 'hostel', Module::STATE_ENABLED);

        $this->artisan('skulos:resync', ['--modules' => true])->assertSuccessful();

        $this->assertSame(Module::STATE_ENABLED, $this->rbac->moduleState($school, 'library'), 'an active add on grant must survive the resync');
        $this->assertSame(Module::STATE_DISABLED, $this->rbac->moduleState($school, 'hostel'), 'a module with no plan and no add on backing it reverts to disabled, matching assignPlan()\'s own rule');
    }

    public function test_an_unprovisioned_school_is_skipped_without_error(): void
    {
        School::factory()->create(['slug' => 'resyncnone', 'database' => null, 'provisioned_at' => null]);

        $this->artisan('skulos:resync')->assertSuccessful();
    }
}
