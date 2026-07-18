<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * When a school lets teachers enter scores for one assessment component
 * in one term (user request 2026-07-18). A pair with no row at all is
 * unrestricted, an admin only sets a window for the components/terms
 * that actually need one.
 */
class AssessmentWindow extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['assessment_component_id', 'term_id', 'opens_at', 'closes_at'];

    protected function casts(): array
    {
        return ['opens_at' => 'datetime', 'closes_at' => 'datetime'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(AssessmentComponent::class, 'assessment_component_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(AssessmentWindowExtension::class);
    }
}
