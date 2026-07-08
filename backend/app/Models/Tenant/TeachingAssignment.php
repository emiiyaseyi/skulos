<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff member teaching one subject to one class arm.
 */
class TeachingAssignment extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['staff_id', 'subject_id', 'class_arm_id'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function classArm(): BelongsTo
    {
        return $this->belongsTo(ClassArm::class);
    }
}
