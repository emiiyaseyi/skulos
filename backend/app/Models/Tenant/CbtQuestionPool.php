<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rule that draws N random questions from a category into an
 * assessment, so no two takers get quite the same paper.
 */
class CbtQuestionPool extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['assessment_id', 'category_id', 'draw_count', 'points_each', 'sequence'];

    protected function casts(): array
    {
        return [
            'draw_count' => 'integer',
            'points_each' => 'decimal:2',
            'sequence' => 'integer',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CbtAssessment::class, 'assessment_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CbtQuestionCategory::class, 'category_id');
    }
}
