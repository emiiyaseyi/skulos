<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row means Narrative Evaluation is on for this class, for this one
 * term only (user request 2026-07-26: "per-class-per-term toggle,
 * re-toggleable across terms"). No row for a given class/term pair means
 * scored, the default. See SchoolClass::isNarrativeEvaluationForTerm().
 */
class ClassNarrativeTerm extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['school_class_id', 'term_id'];

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
