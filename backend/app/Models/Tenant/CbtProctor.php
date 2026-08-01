<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member of staff who may invigilate. A null assessment_id
 * means school-wide: they proctor every assessment.
 */
class CbtProctor extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['assessment_id', 'user_id', 'role'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CbtAssessment::class, 'assessment_id');
    }
}
