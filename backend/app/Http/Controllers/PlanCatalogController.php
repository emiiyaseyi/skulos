<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\JsonResponse;

/**
 * Read only public JSON feed of the live pricing catalog (2026-07-21:
 * "wire the marketing pages themselves to read live from the plans table
 * instead of matching it by hand in static HTML", tracker section 13).
 * The static site under /site (deployed separately to GitHub Pages, see
 * .github/workflows/pages.yml) has no server side rendering of its own, so
 * this is the bridge: site/assets/pricing.js fetches this on load and
 * renders the price and full feature checklist from it, instead of the
 * price/features being retyped by hand into the HTML whenever an admin
 * changes a plan on the Billing screen.
 *
 * Platform level reference data, so this route carries no domain
 * constraint (see routes/web.php, the "on any host" group, same reasoning
 * as GeoController) and no auth: the marketing site is public by
 * definition, and nothing here writes.
 */
class PlanCatalogController extends Controller
{
    public function index(): JsonResponse
    {
        $catalog = config('skulos.plan_feature_catalog');

        $plans = Plan::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (Plan $plan) use ($catalog) {
                $keys = array_flip($plan->features ?? []);

                $featureGroups = collect($catalog)
                    ->map(fn ($category) => [
                        'label' => $category['label'],
                        'features' => collect($category['features'])
                            ->filter(fn ($label, $key) => isset($keys[$key]))
                            ->values()->all(),
                    ])
                    ->filter(fn ($category) => count($category['features']) > 0)
                    ->values();

                return [
                    'slug' => $plan->slug,
                    'name' => $plan->name,
                    'price_per_student_per_term' => (float) $plan->price_per_student_per_term,
                    'is_purchasable' => (bool) $plan->is_purchasable,
                    'sort_order' => $plan->sort_order,
                    'feature_groups' => $featureGroups,
                ];
            });

        return response()->json([
            'plans' => $plans,
            'universal_inclusions' => config('skulos.plan_universal_inclusions'),
        ]);
    }
}
