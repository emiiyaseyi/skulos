<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One part of the assessment format, e.g. "First CA" (max 20) or "Exam"
 * (max 60). A school can define any set; their max_scores sum to the
 * subject total. "type" ('ca' or 'exam') is purely organizational, for
 * grouping the admin screen into "Test / Continuous Assessment" and
 * "Examinations" (user request 2026-07-10) — ResultService sums every
 * component regardless of type, it does not affect grading.
 */
class AssessmentComponent extends Model
{
    use Auditable;

    public const TYPE_CA = 'ca';

    public const TYPE_EXAM = 'exam';

    protected $connection = 'tenant';

    protected $fillable = ['name', 'type', 'max_score', 'sequence'];

    protected function casts(): array
    {
        return ['max_score' => 'decimal:2', 'sequence' => 'integer'];
    }

    public static function totalPossible(): float
    {
        return (float) static::sum('max_score');
    }

    public function scopeCa($query)
    {
        return $query->where('type', self::TYPE_CA);
    }

    public function scopeExam($query)
    {
        return $query->where('type', self::TYPE_EXAM);
    }
}
