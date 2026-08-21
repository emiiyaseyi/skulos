<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Plan;
use App\Models\School;
use App\Models\TrialRequest;
use App\Models\User;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A trial requester picks the features they want from the real catalog
 * on the public form (2026-08-19, direct instruction), and the
 * reviewing admin sees that request pre-filled on the approve screen,
 * able to add or remove before the school is actually created. What is
 * finally submitted there becomes School::feature_overrides, which
 * REPLACES whatever the assigned plan would otherwise grant.
 */
class TrialRequestFeatureSelectionTest extends TestCase
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

    /**
     * A real, purchasable plan claiming every key these tests reference
     * (2026-08-21): validation now requires a submitted feature key to
     * actually be in Plan::builtFeatureKeys() -- the union across
     * purchasable plans -- not just anywhere in the raw catalog, since
     * the checklist itself only ever shows that filtered set now.
     */
    protected function makeBuiltPlan(array $features): Plan
    {
        return Plan::create([
            'name' => 'Test Plan', 'slug' => 'test-plan-'.uniqid(),
            'price_per_student_per_term' => 500, 'modules' => [],
            'features' => $features, 'is_purchasable' => true,
        ]);
    }

    protected function submitTrial(array $overrides = []): TrialRequest
    {
        Country::firstOrCreate(['name' => 'Nigeria'], ['iso2' => 'NG', 'sequence' => 1]);

        $this->post('/trial', array_merge([
            'school_name' => 'Bright Star Academy',
            'preferred_slug' => 'brightstar'.uniqid(),
            'country' => 'Nigeria',
            'contact_name' => 'Ada Okoro',
            'email' => 'ada'.uniqid().'@example.com',
        ], $overrides))->assertRedirect(route('trial.thanks'));

        return TrialRequest::latest()->first();
    }

    public function test_a_prospect_can_select_real_features_on_the_public_form(): void
    {
        $this->makeBuiltPlan(['attendance_management', 'student_reports']);

        $trial = $this->submitTrial([
            'requested_features' => ['attendance_management', 'student_reports'],
        ]);

        $this->assertSame(['attendance_management', 'student_reports'], $trial->requested_features);
    }

    public function test_an_unknown_feature_key_is_rejected_not_silently_dropped(): void
    {
        Country::firstOrCreate(['name' => 'Nigeria'], ['iso2' => 'NG', 'sequence' => 1]);

        $this->post('/trial', [
            'school_name' => 'Bright Star Academy',
            'preferred_slug' => 'brightstar'.uniqid(),
            'country' => 'Nigeria',
            'contact_name' => 'Ada Okoro',
            'email' => 'ada@example.com',
            'requested_features' => ['not_a_real_feature'],
        ])->assertSessionHasErrors('requested_features.0');
    }

    public function test_leaving_the_checklist_untouched_at_approval_leaves_the_plan_in_full_control(): void
    {
        // The real regression this guards: an early draft defaulted a
        // missing key to an empty array, which would have silently
        // zeroed out every new school's features the moment an admin
        // approved without opening this new section at all.
        $plan = $this->makeBuiltPlan(['attendance_management']);
        $trial = $this->submitTrial();

        $this->actingAs($this->superAdmin())->post(route('admin.trial-requests.approve', $trial), [
            'plan_id' => $plan->id,
        ])->assertRedirect();

        $school = School::find($trial->fresh()->school_id);

        $this->assertNull($school->feature_overrides);
        $this->assertTrue($school->hasFeature('attendance_management'));
    }

    public function test_the_admins_final_selection_at_approval_replaces_the_plans_own_features(): void
    {
        // Both keys the admin ultimately grants must be in scope too --
        // a real plan somewhere claims each of them, same as the
        // checklist's own builtFeatureCatalog() would only ever offer
        // keys that appear on at least one purchasable plan.
        $plan = $this->makeBuiltPlan(['attendance_management', 'student_reports', 'parent_portal']);
        $trial = $this->submitTrial([
            'requested_features' => ['student_reports'],
        ]);

        // The admin adds a feature the trial never asked for, and drops
        // the plan's own "attendance_management" -- exactly the "add or
        // remove from the features" the instruction described.
        $this->actingAs($this->superAdmin())->post(route('admin.trial-requests.approve', $trial), [
            'plan_id' => $plan->id,
            'feature_overrides' => ['student_reports', 'parent_portal'],
        ])->assertRedirect();

        $school = School::find($trial->fresh()->school_id);

        $this->assertSame(['student_reports', 'parent_portal'], $school->feature_overrides);
        $this->assertTrue($school->hasFeature('student_reports'));
        $this->assertTrue($school->hasFeature('parent_portal'));
        // The plan granted this, but the override replaces it outright
        // rather than merging with it.
        $this->assertFalse($school->hasFeature('attendance_management'));
    }

    public function test_the_checklist_never_shows_features_from_a_non_purchasable_plan(): void
    {
        // The real regression this guards (direct report, 2026-08-21):
        // the checklist was showing the full 140-key raw catalog, 18
        // categories deep, including "Future Features" and "Enterprise
        // Features" that have no screen behind them at all -- because it
        // read straight from the catalog config instead of
        // Plan::builtFeatureCatalog(), which only includes what a real,
        // purchasable plan actually claims.
        $this->makeBuiltPlan(['attendance_management']);
        Plan::create([
            'name' => 'Enterprise', 'slug' => 'enterprise-'.uniqid(),
            'price_per_student_per_term' => 1500, 'modules' => [],
            'features' => ['white_label', 'custom_module_development'],
            'is_purchasable' => false,
        ]);

        Country::firstOrCreate(['name' => 'Nigeria'], ['iso2' => 'NG', 'sequence' => 1]);

        $response = $this->get('/trial');

        $response->assertSee('attendance_management', false);
        $response->assertDontSee('white_label', false);
        $response->assertDontSee('custom_module_development', false);
    }

    public function test_an_unknown_feature_key_at_approval_is_rejected(): void
    {
        $trial = $this->submitTrial();

        $this->actingAs($this->superAdmin())->post(route('admin.trial-requests.approve', $trial), [
            'feature_overrides' => ['not_a_real_feature'],
        ])->assertSessionHasErrors('feature_overrides.0');

        $this->assertSame(TrialRequest::STATUS_PENDING, $trial->fresh()->status);
    }
}
