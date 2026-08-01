<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One explicitly assigned taker on an assessment — the Bulk Assignment
 * screen's own output. Polymorphic `takeable` for the same reason
 * CbtSession's own is: a School\User today, an Applicant later, no
 * schema fork.
 */
class CbtAssessmentTaker extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['assessment_id', 'takeable_type', 'takeable_id', 'assigned_by_user_id'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CbtAssessment::class, 'assessment_id');
    }

    public function takeable(): MorphTo
    {
        return $this->morphTo();
    }
}
