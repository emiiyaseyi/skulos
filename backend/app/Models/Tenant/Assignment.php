<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A homework/coursework assignment for one subject, one class arm.
 * Tenant only. No submission/grading workflow yet, see the migration's
 * docblock.
 */
class Assignment extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = [
        'title', 'description', 'subject_id', 'class_arm_id', 'term_id', 'due_on', 'created_by_staff_id',
    ];

    protected function casts(): array
    {
        return ['due_on' => 'date'];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function classArm(): BelongsTo
    {
        return $this->belongsTo(ClassArm::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }

    public function scopeUpcoming($query)
    {
        return $query->whereNotNull('due_on')
            ->whereDate('due_on', '>=', now()->toDateString())
            ->orderBy('due_on');
    }
}
