<?php

namespace Tests\Feature;

use App\Models\BillingWaiver;
use App\Models\Module;
use App\Models\Plan;
use App\Models\School;
use App\Models\SchoolAddOn;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Billing (tracker section 14, sourced from "SKULOS PRICING PLAN AND
 * FEATURES.docx"): per student, per term plans that bundle a marketing
 * feature checklist and a set of RBAC modules, per-school plan assignment
 * (upgrade/downgrade), ad hoc extra features beyond the plan, an add on
 * marketplace purchasable on any plan, and free-term waivers.
 */
class BillingTest extends TestCase
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

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    /** Audit follow-up 2026-07-30: the Schools table on this page used to load with no bound at all. */
    public function test_the_schools_table_paginates(): void
    {
        $admin = $this->superAdmin();
        School::factory()->count(35)->create();

        $response = $this->actingAs($admin)->get(route('admin.billing.index'));
        $response->assertOk();

        $schools = $response->viewData('schools');
        $this->assertSame(30, $schools->perPage());
        $this->assertSame(30, $schools->count());
        $this->assertSame(35, $schools->total());
    }

    public function test_admin_can_create_and_edit_a_plan(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.billing.plans.store'), [
            'name' => 'Custom Tier',
            'price_per_student_per_term' => 999.50,
            'modules' => ['library'],
            'features' => ['student_records', 'dashboard'],
        ])->assertRedirect();

        $plan = Plan::where('slug', 'custom-tier')->first();
        $this->assertNotNull($plan);
        $this->assertSame(['library'], $plan->modules);
        $this->assertSame(['student_records', 'dashboard'], $plan->features);
        $this->assertEquals(999.50, $plan->price_per_student_per_term);

        $this->actingAs($admin)->post(route('admin.billing.plans.update', $plan), [
            'name' => 'Custom Tier',
            'price_per_student_per_term' => 750,
            'modules' => [],
            'features' => ['student_records'],
        ])->assertRedirect();

        $this->assertSame([], $plan->fresh()->modules);
        $this->assertSame(['student_records'], $plan->fresh()->features);
        $this->assertEquals(750, $plan->fresh()->price_per_student_per_term);
    }

    public function test_admin_can_retire_and_reoffer_a_plan(): void
    {
        $admin = $this->superAdmin();
        $plan = Plan::create(['name' => 'Test Plan', 'slug' => 'test-plan', 'price_per_student_per_term' => 500, 'modules' => [], 'features' => []]);

        $this->actingAs($admin)->post(route('admin.billing.plans.toggle-active', $plan))->assertRedirect();
        $this->assertFalse($plan->fresh()->is_active);

        $this->actingAs($admin)->post(route('admin.billing.plans.toggle-active', $plan))->assertRedirect();
        $this->assertTrue($plan->fresh()->is_active);
    }

    public function test_admin_can_mark_a_plan_coming_soon_and_sellable_again(): void
    {
        $admin = $this->superAdmin();
        $plan = Plan::create(['name' => 'Test Plan', 'slug' => 'test-plan-2', 'price_per_student_per_term' => 500, 'modules' => [], 'features' => [], 'is_purchasable' => true]);

        $this->actingAs($admin)->post(route('admin.billing.plans.toggle-purchasable', $plan))->assertRedirect();
        $this->assertFalse($plan->fresh()->is_purchasable);

        $this->actingAs($admin)->post(route('admin.billing.plans.toggle-purchasable', $plan))->assertRedirect();
        $this->assertTrue($plan->fresh()->is_purchasable);
    }

    public function test_a_plan_marked_coming_soon_cannot_be_assigned_to_a_school(): void
    {
        $admin = $this->superAdmin();
        $school = School::factory()->create(['slug' => 'notyetschool']);
        $plan = Plan::create(['name' => 'Enterprise', 'slug' => 'enterprise-x', 'price_per_student_per_term' => 1500, 'modules' => [], 'features' => [], 'is_purchasable' => false]);

        $this->actingAs($admin)->post(route('admin.billing.schools.assign-plan', $school), [
            'plan_id' => $plan->id,
        ])->assertStatus(422);

        $this->assertNull($school->fresh()->plan_id);
    }

    public function test_assigning_a_plan_enables_its_modules_and_disables_the_rest(): void
    {
        $admin = $this->superAdmin();
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'planschool']));
        $growth = Plan::create(['name' => 'Growth', 'slug' => 'growth-t', 'price_per_student_per_term' => 800, 'modules' => ['library', 'biometric'], 'features' => []]);

        $this->actingAs($admin)->post(route('admin.billing.schools.assign-plan', $school), [
            'plan_id' => $growth->id,
        ])->assertRedirect();

        $school->refresh();
        $this->assertSame($growth->id, $school->plan_id);

        $rbac = app(Rbac::class);
        $this->assertSame(Module::STATE_ENABLED, $rbac->moduleState($school, 'library'));
        $this->assertSame(Module::STATE_ENABLED, $rbac->moduleState($school, 'biometric'));
        $this->assertSame(Module::STATE_DISABLED, $rbac->moduleState($school, 'ai'));

        // Downgrade to a plan with fewer modules actually removes access.
        $starter = Plan::create(['name' => 'Starter', 'slug' => 'starter-t', 'price_per_student_per_term' => 500, 'modules' => [], 'features' => []]);
        $this->actingAs($admin)->post(route('admin.billing.schools.assign-plan', $school), ['plan_id' => $starter->id]);

        $this->assertSame(Module::STATE_DISABLED, $rbac->moduleState($school, 'library'));
    }

    public function test_admin_can_grant_an_ad_hoc_feature_beyond_the_plan(): void
    {
        $admin = $this->superAdmin();
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'adhoc']));
        $starter = Plan::create(['name' => 'Starter', 'slug' => 'starter-adhoc', 'price_per_student_per_term' => 500, 'modules' => [], 'features' => []]);
        $school->update(['plan_id' => $starter->id]);

        $this->actingAs($admin)->post(route('admin.billing.schools.toggle-feature', $school), [
            'module' => 'ai',
        ])->assertRedirect();

        $this->assertSame(Module::STATE_ENABLED, app(Rbac::class)->moduleState($school, 'ai'));
    }

    public function test_admin_can_grant_and_revoke_an_add_on(): void
    {
        $admin = $this->superAdmin();
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'addonschool']));

        $this->actingAs($admin)->post(route('admin.billing.schools.add-ons.store', $school), [
            'add_on_code' => 'library',
            'note' => 'Paid annually',
        ])->assertRedirect();

        $addOn = SchoolAddOn::where('school_id', $school->id)->where('add_on_code', 'library')->first();
        $this->assertNotNull($addOn);
        $this->assertSame($admin->id, $addOn->granted_by);

        // "library" maps to the library RBAC module, so granting it is functionally real.
        $this->assertSame(Module::STATE_ENABLED, app(Rbac::class)->moduleState($school, 'library'));

        $this->actingAs($admin)->delete(route('admin.billing.add-ons.destroy', $addOn))->assertRedirect();
        $this->assertDatabaseMissing('school_add_ons', ['id' => $addOn->id]);
    }

    /**
     * Real bug found 2026-08-07 (user directive: "fix this so that the
     * platform owner/admin can give it to a school"): a waiver used to
     * only ever create a BillingWaiver label, never consulted by
     * SubscriptionService and never moving next_billing_date — this
     * locks in that it now actually waives billing.
     */
    public function test_admin_can_mark_a_term_free_and_it_actually_pushes_the_due_date(): void
    {
        $admin = $this->superAdmin();
        $school = School::factory()->create(['slug' => 'freeterm', 'next_billing_date' => now()->addDays(10)]);

        $this->actingAs($admin)->post(route('admin.billing.schools.waivers.store', $school), [
            'label' => 'First Term 2025/2026',
            'note' => 'Promotional trial term',
            'days' => 100,
        ])->assertRedirect();

        $waiver = BillingWaiver::where('school_id', $school->id)->first();
        $this->assertNotNull($waiver);
        $this->assertSame('First Term 2025/2026', $waiver->label);
        $this->assertSame($admin->id, $waiver->granted_by);

        $fresh = $school->fresh();
        $this->assertEquals(now()->addDays(10)->addDays(100)->toDateString(), $fresh->next_billing_date->toDateString());

        $event = SubscriptionEvent::where('school_id', $school->id)->where('type', SubscriptionEvent::TYPE_WAIVER)->first();
        $this->assertNotNull($event, 'a real audit event must exist for the waived period');
        $this->assertEquals($fresh->next_billing_date->toDateString(), $event->extended_to->toDateString());

        $this->actingAs($admin)->delete(route('admin.billing.waivers.destroy', $waiver))->assertRedirect();
        $this->assertDatabaseMissing('billing_waivers', ['id' => $waiver->id]);
    }

    public function test_marking_a_term_free_reinstates_a_school_locked_out_for_billing(): void
    {
        $admin = $this->superAdmin();
        $school = School::factory()->create([
            'slug' => 'lockedfreeterm',
            'status' => School::STATUS_SUSPENDED,
            'suspension_reason' => School::SUSPENSION_REASON_BILLING,
        ]);

        $this->actingAs($admin)->post(route('admin.billing.schools.waivers.store', $school), [
            'label' => 'Goodwill waiver', 'days' => 90,
        ])->assertRedirect();

        $fresh = $school->fresh();
        $this->assertSame(School::STATUS_ACTIVE, $fresh->status);
        $this->assertNull($fresh->suspension_reason);
    }

    public function test_an_admin_can_set_a_custom_price_for_one_school(): void
    {
        $admin = $this->superAdmin();
        $plan = Plan::create(['name' => 'Priced Plan', 'slug' => 'priced-plan', 'price_per_student_per_term' => 1000, 'modules' => [], 'features' => []]);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'customprice', 'plan_id' => $plan->id]));

        $this->actingAs($admin)->post(route('admin.billing.schools.price', $school), [
            'price_per_student_per_term' => 650,
        ])->assertRedirect();

        $this->assertEquals(650, $school->fresh()->price_per_student_per_term_override);
        $this->assertEquals(650.0, app(SubscriptionService::class)->effectivePrice($school->fresh()));

        // Clearing (blank submit) falls back to the plan's own price.
        $this->actingAs($admin)->post(route('admin.billing.schools.price', $school), [
            'price_per_student_per_term' => '',
        ])->assertRedirect();

        $this->assertNull($school->fresh()->price_per_student_per_term_override);
        $this->assertEquals(1000.0, app(SubscriptionService::class)->effectivePrice($school->fresh()));
    }

    public function test_an_admin_can_bulk_set_a_price_across_several_schools_at_once(): void
    {
        $admin = $this->superAdmin();
        $plan = Plan::create(['name' => 'Bulk Plan', 'slug' => 'bulk-plan', 'price_per_student_per_term' => 500, 'modules' => [], 'features' => []]);
        $one = School::factory()->create(['slug' => 'bulkone', 'plan_id' => $plan->id]);
        $two = School::factory()->create(['slug' => 'bulktwo', 'plan_id' => $plan->id]);
        $untouched = School::factory()->create(['slug' => 'bulkuntouched', 'plan_id' => $plan->id]);

        $this->actingAs($admin)->post(route('admin.billing.schools.price.bulk'), [
            'school_ids' => [$one->id, $two->id],
            'price_per_student_per_term' => 425,
        ])->assertRedirect();

        $this->assertEquals(425, $one->fresh()->price_per_student_per_term_override);
        $this->assertEquals(425, $two->fresh()->price_per_student_per_term_override);
        $this->assertNull($untouched->fresh()->price_per_student_per_term_override, 'a school not ticked must be completely unaffected');
    }

    /** A school with no override at all must be billed at exactly its plan's price, unchanged by this feature existing. */
    public function test_a_school_with_no_price_override_is_billed_at_the_plans_own_price(): void
    {
        $plan = Plan::create(['name' => 'Default Priced Plan', 'slug' => 'default-priced-plan', 'price_per_student_per_term' => 777, 'modules' => [], 'features' => []]);
        $school = School::factory()->create(['slug' => 'nooverride', 'plan_id' => $plan->id]);

        $this->assertEquals(777.0, app(SubscriptionService::class)->effectivePrice($school));
    }

    public function test_only_super_admin_can_manage_billing(): void
    {
        $regular = User::factory()->create();
        $school = School::factory()->create(['slug' => 'notallowedbilling']);

        $this->actingAs($regular)->get(route('admin.billing.index'))->assertNotFound();
        $this->actingAs($regular)->post(route('admin.billing.schools.waivers.store', $school), ['label' => 'X'])->assertNotFound();
    }

    public function test_billing_index_shows_plans_and_schools(): void
    {
        $admin = $this->superAdmin();
        Plan::create(['name' => 'Visible Plan', 'slug' => 'visible-plan', 'price_per_student_per_term' => 600, 'modules' => [], 'features' => []]);
        School::factory()->create(['slug' => 'listedschool', 'name' => 'Listed School']);

        $this->actingAs($admin)->get(route('admin.billing.index'))
            ->assertOk()->assertSee('Visible Plan')->assertSee('Listed School');
    }
}
