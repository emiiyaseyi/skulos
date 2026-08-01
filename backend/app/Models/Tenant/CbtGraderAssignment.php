<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which grader owns which attempt, written by
 * GradingDistributionService when essays are shared out.
 */
class CbtGraderAssignment extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['assessment_id', 'session_id', 'grader_user_id'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CbtSession::class, 'session_id');
    }
}
