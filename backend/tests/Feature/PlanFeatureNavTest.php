<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A school's plan actually drives what shows in its nav (user request
 * 2026-07-11: "once a plan is given to a school, it should automatically
 * appear under the school end for respective users, and if it's not a
 * plan/feature that was given to a school, the feature should not
 * appear" — hidden entirely, not shown locked, per the user's own
 * correction after an earlier locked/greyed out draft).
 */
class PlanFeatureNavTest extends TestCase
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

    protected function schoolOnPlan(array $features, string $slug): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan '.$slug, 'slug' => 'plan-'.$slug,
            'price_per_student_per_term' => 500, 'modules' => [], 'features' => $features,
        ]);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug, 'plan_id' => $plan->id]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_a_feature_included_in_the_plan_shows_in_the_nav(): void
    {
        [$school, $user] = $this->schoolOnPlan(['school_houses', 'result_management'], 'planone');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");

        $response->assertOk()->assertSee('Houses')->assertSee('Results');
    }

    public function test_a_feature_not_in_the_plan_is_not_shown_at_all(): void
    {
        [$school, $user] = $this->schoolOnPlan(['school_houses'], 'plantwo');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");

        $response->assertOk()->assertSee('Houses')->assertDontSee('Report Cards');
    }

    public function test_school_model_has_feature_helper_reflects_the_plan(): void
    {
        $plan = Plan::create([
            'name' => 'Direct Test Plan', 'slug' => 'direct-test-plan',
            'price_per_student_per_term' => 500, 'modules' => [], 'features' => ['school_branding'],
        ]);
        $school = School::factory()->create(['plan_id' => $plan->id]);

        $this->assertTrue($school->hasFeature('school_branding'));
        $this->assertFalse($school->hasFeature('academic_sessions_terms'));
    }

    /**
     * The nav is grouped into collapsible submenus (user request
     * 2026-07-11: "we should use the grouping features where a menu is
     * under a sub menu instead of listing all as one under the menu"),
     * not a flat list under a plain section label.
     */
    public function test_the_nav_renders_grouped_collapsible_submenus(): void
    {
        [$school, $user] = $this->schoolOnPlan(['school_houses', 'result_management'], 'planthree');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");

        $response->assertOk()
            ->assertSee('side-group-btn', false)
            ->assertSee('side-submenu', false)
            ->assertSee('People'); // a group label still renders
    }

    /**
     * Whole functional areas with no screen built yet (Admissions,
     * Finance, HR, Learning) still need to appear once a plan includes
     * any of their features (user request 2026-07-11, after reporting
     * that moving a school to Enterprise showed no change: "it should
     * show for any plan or feature given to the school from the billing
     * page"), shown as a category level Soon entry.
     */
    public function test_a_category_with_no_built_screen_still_appears_as_soon_once_the_plan_includes_it(): void
    {
        [$school, $user] = $this->schoolOnPlan(['online_admissions', 'hr_management', 'lms'], 'planfour');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");

        $response->assertOk()
            ->assertSee('Admissions')
            ->assertSee('Human Resources')
            ->assertSee('Learning');
    }

    public function test_a_category_with_none_of_its_features_in_the_plan_does_not_appear(): void
    {
        [$school, $user] = $this->schoolOnPlan(['school_houses'], 'planfive');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");

        $response->assertOk()
            ->assertDontSee('Admissions')
            ->assertDontSee('Human Resources')
            ->assertDontSee('Learning Management');
    }

    public function test_school_model_has_any_feature_in_category_helper(): void
    {
        $plan = Plan::create([
            'name' => 'Category Test Plan', 'slug' => 'category-test-plan',
            'price_per_student_per_term' => 500, 'modules' => [], 'features' => ['payroll'],
        ]);
        $school = School::factory()->create(['plan_id' => $plan->id]);

        $this->assertTrue($school->hasAnyFeatureInCategory('finance'));
        $this->assertFalse($school->hasAnyFeatureInCategory('learning'));
    }

    public function test_a_school_with_no_plan_falls_back_to_the_default_plan(): void
    {
        Plan::create([
            'name' => 'Default Plan', 'slug' => 'default-plan', 'is_default' => true,
            'price_per_student_per_term' => 500, 'modules' => [], 'features' => ['student_records'],
        ]);
        $school = School::factory()->create(['plan_id' => null]);

        $this->assertTrue($school->hasFeature('student_records'));
        $this->assertFalse($school->hasFeature('payroll'));
    }
}
