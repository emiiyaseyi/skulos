<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single student's score for one assessment component, in one subject,
 * class arm and term. The subject total is the sum of its component scores.
 */
class Score extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = [
        'student_id', 'subject_id', 'class_arm_id', 'term_id',
        'assessment_component_id', 'score',
    ];

    protected function casts(): array
    {
        return ['score' => 'decimal:2'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(AssessmentComponent::class, 'assessment_component_id');
    }
}
