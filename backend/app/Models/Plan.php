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
}
