<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question's placement on one assessment — sequence within the
 * assessment, and an optional per-assessment points override (the same
 * question can be worth different points on different assessments).
 */
class CbtAssessmentQuestion extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['assessment_id', 'question_id', 'sequence', 'points_override'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'points_override' => 'decimal:2',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CbtAssessment::class, 'assessment_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(CbtQuestion::class);
    }
}
