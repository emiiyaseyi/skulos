<?php

namespace Tests\Feature;

use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public plan catalog feed (2026-07-21 marketing CMS wiring) behind
 * site/assets/pricing.js — see PlanCatalogController.
 */
class PlanCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_every_active_plan_ordered_with_grouped_features(): void
    {
        $this->seed(PlanSeeder::class);

        $response = $this->getJson(route('plans.index'))->assertOk();
        $plans = $response->json('plans');

        $this->assertSame(['foundation', 'advance', 'professional', 'enterprise'], collect($plans)->pluck('slug')->all());
        // assertEquals, not assertSame: json_encode() drops the .0 for a
        // whole-number float unless JSON_PRESERVE_ZERO_FRACTION is set, so
        // this decodes back as a plain int 500, not a float — a real,
        // harmless JSON encoding quirk, not a bug, and irrelevant to the
        // JS consumer either way.
        $this->assertEquals(500.0, $plans[0]['price_per_student_per_term']);
        $this->assertTrue($plans[0]['is_purchasable']);
        $this->assertFalse($plans[2]['is_purchasable'], 'Professional is not yet purchasable');

        $academic = collect($plans[0]['feature_groups'])->firstWhere('label', 'Academic');
        $this->assertNotNull($academic);
        $this->assertContains('Student Records', $academic['features']);
    }

    public function test_it_omits_a_category_the_plan_has_no_features_in(): void
    {
        $this->seed(PlanSeeder::class);

        $response = $this->getJson(route('plans.index'))->assertOk();
        $foundation = collect($response->json('plans'))->firstWhere('slug', 'foundation');

        $admissions = collect($foundation['feature_groups'])->firstWhere('label', 'Admissions');
        $this->assertNull($admissions, 'Foundation has no admissions features (suspended module), so the category must not appear at all, not as an empty group');
    }

    public function test_it_excludes_an_inactive_plan(): void
    {
        Plan::create([
            'name' => 'Retired Tier', 'slug' => 'retired', 'price_per_student_per_term' => 999,
            'modules' => [], 'features' => [], 'is_default' => false, 'is_active' => false,
            'is_purchasable' => false, 'sort_order' => 99,
        ]);

        $response = $this->getJson(route('plans.index'))->assertOk();

        $this->assertNotContains('retired', collect($response->json('plans'))->pluck('slug')->all());
    }

    public function test_it_includes_the_universal_inclusions_list(): void
    {
        $response = $this->getJson(route('plans.index'))->assertOk();

        $this->assertSame(config('skulos.plan_universal_inclusions'), $response->json('universal_inclusions'));
    }

    public function test_the_endpoint_needs_no_authentication(): void
    {
        $this->getJson(route('plans.index'))->assertOk();
    }
}
