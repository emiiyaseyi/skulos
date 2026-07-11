<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workflow state for one student's report card in one term (user request
 * 2026-07-11). The report card's actual content is always computed live
 * from Score rows via ResultService, this row only tracks where it is in
 * the review process, not a stored copy of the marks.
 */
class ReportCardReview extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_NEEDS_CORRECTION = 'needs_correction';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RELEASED = 'released';

    protected $connection = 'tenant';

    protected $fillable = ['student_id', 'term_id', 'status', 'assigned_to_staff_id', 'notes', 'released_at'];

    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_to_staff_id');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_PENDING_REVIEW => 'Pending review',
            self::STATUS_NEEDS_CORRECTION => 'Needs correction',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_RELEASED => 'Released',
            default => ucfirst($status),
        };
    }
}
