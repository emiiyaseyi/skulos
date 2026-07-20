<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell of the weekly timetable grid: this class arm, this day, this
 * period, teaching this subject, taught by this staff member. staff_id is
 * a plain id (no FK), matching ClassArm::class_teacher_staff_id's own
 * established convention.
 */
class TimetableEntry extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['class_arm_id', 'timetable_period_id', 'day_of_week', 'subject_id', 'staff_id'];

    public function classArm(): BelongsTo
    {
        return $this->belongsTo(ClassArm::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(TimetablePeriod::class, 'timetable_period_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }
}
