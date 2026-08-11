<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's outcome within a PromotionRun — promoted, repeated
 * (with a reason), graduated, or skipped (no results / already moved /
 * inactive), each with a reason so the admin can see why a given
 * student was not moved.
 */
class PromotionRunItem extends Model
{
    protected $connection = 'tenant';

    public const OUTCOME_PROMOTED = 'promoted';

    public const OUTCOME_REPEATED = 'repeated';

    public const OUTCOME_GRADUATED = 'graduated';

    public const OUTCOME_SKIPPED_NO_RESULTS = 'skipped_no_results';

    public const OUTCOME_SKIPPED_ALREADY_MOVED = 'skipped_already_moved';

    public const OUTCOME_SKIPPED_INACTIVE = 'skipped_inactive';

    protected $fillable = [
        'promotion_run_id', 'student_id', 'from_class_arm_id', 'to_class_arm_id',
        'average', 'outcome', 'reason',
    ];

    public function promotionRun(): BelongsTo
    {
        return $this->belongsTo(PromotionRun::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function fromClassArm(): BelongsTo
    {
        return $this->belongsTo(ClassArm::class, 'from_class_arm_id');
    }

    public function toClassArm(): BelongsTo
    {
        return $this->belongsTo(ClassArm::class, 'to_class_arm_id');
    }

    public function wasMoved(): bool
    {
        return in_array($this->outcome, [self::OUTCOME_PROMOTED, self::OUTCOME_GRADUATED], true);
    }

    public static function outcomeLabel(string $outcome): string
    {
        return match ($outcome) {
            self::OUTCOME_PROMOTED => 'Promoted',
            self::OUTCOME_REPEATED => 'Repeated',
            self::OUTCOME_GRADUATED => 'Graduated',
            self::OUTCOME_SKIPPED_NO_RESULTS => 'No results recorded',
            self::OUTCOME_SKIPPED_ALREADY_MOVED => 'Already moved',
            self::OUTCOME_SKIPPED_INACTIVE => 'Not active',
            default => ucfirst($outcome),
        };
    }
}
