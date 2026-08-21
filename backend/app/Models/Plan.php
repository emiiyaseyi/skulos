<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A billing plan: a per-student, per-term price, the set of RBAC modules
 * a school on this plan gets (core modules, tracker section 5, are always
 * available regardless of plan), and the customer-facing feature
 * checklist ("features", keys into config('skulos.plan_feature_catalog'))
 * shown on the marketing pricing page. Seeded with the 4 confirmed tiers
 * (Foundation, Advance, Professional, Enterprise — tracker section 14)
 * but fully admin editable afterwards — see Admin\BillingController.
 * "is_purchasable" is false for tiers not yet built (shown "Coming soon"
 * rather than sold).
 */
class Plan extends Model
{
    use Auditable;

    protected $fillable = [
        'name', 'slug', 'price_per_student_per_term', 'storage_limit_mb', 'modules', 'features', 'is_default', 'is_active', 'is_purchasable', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'features' => 'array',
            'price_per_student_per_term' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'is_purchasable' => 'boolean',
        ];
    }

    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }

    /**
     * Every real key in the feature catalog, flattened across all
     * categories (2026-08-19). The one place this list is assembled,
     * so the trial form's public submission and a school's own
     * feature-override grant are both validated against the exact same
     * set a plan's own "features" array is drawn from.
     *
     * @return array<int, string>
     */
    public static function allFeatureKeys(): array
    {
        $keys = [];
        foreach (config('skulos.plan_feature_catalog', []) as $category) {
            $keys = array_merge($keys, array_keys($category['features'] ?? []));
        }

        return $keys;
    }

    /**
     * Only the features a school could actually get today (2026-08-21,
     * direct correction: the trial form and approval screen were showing
     * the full catalog -- 140 keys across 18 categories, including
     * "Future Features" and "Enterprise Features" that have no screen
     * behind them at all yet -- rather than what is genuinely built).
     *
     * Sourced from is_purchasable plans specifically (Foundation and
     * Advance today), not a hardcoded slug list: whichever plans are
     * actually sellable already represents "this is real and launched"
     * everywhere else in the app (see Admin\TrialRequestAdminController::
     * index()'s own plan dropdown), so this stays correct automatically
     * the day a third tier is marked purchasable, with no second place
     * to remember to update.
     *
     * @return array<int, string>
     */
    public static function builtFeatureKeys(): array
    {
        $keys = [];
        foreach (static::where('is_purchasable', true)->get() as $plan) {
            $keys = array_merge($keys, $plan->features ?? []);
        }

        return array_values(array_unique($keys));
    }

    /**
     * The same shape as config('skulos.plan_feature_catalog'), filtered
     * down to builtFeatureKeys() -- categories with nothing built are
     * dropped entirely rather than left showing as an empty section, and
     * every remaining category only lists the features actually in
     * scope. This is what the trial form and its approval screen render;
     * Billing's own plan editor still uses the full raw catalog, since
     * defining what a future tier will eventually include is exactly
     * what that screen is for.
     *
     * @return array<string, array{label: string, features: array<string, string>}>
     */
    public static function builtFeatureCatalog(): array
    {
        $built = array_flip(static::builtFeatureKeys());
        $catalog = [];

        foreach (config('skulos.plan_feature_catalog', []) as $catKey => $category) {
            $features = array_intersect_key($category['features'] ?? [], $built);
            if ($features !== []) {
                $catalog[$catKey] = ['label' => $category['label'], 'features' => $features];
            }
        }

        return $catalog;
    }
}
