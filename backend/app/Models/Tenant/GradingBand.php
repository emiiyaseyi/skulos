<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One band of a grading scale: [min_score, max_score] => grade + remark.
 */
class GradingBand extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['grading_scale_id', 'min_score', 'max_score', 'grade', 'remark', 'points'];

    public function scale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class, 'grading_scale_id');
    }
}
