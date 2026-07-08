<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One part of the assessment format, e.g. "First CA" (max 20) or "Exam"
 * (max 60). A school can define any set; their max_scores sum to the
 * subject total.
 */
class AssessmentComponent extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'max_score', 'sequence'];

    protected function casts(): array
    {
        return ['max_score' => 'decimal:2', 'sequence' => 'integer'];
    }

    public static function totalPossible(): float
    {
        return (float) static::sum('max_score');
    }
}
