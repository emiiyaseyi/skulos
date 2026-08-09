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

    /**
     * Two more states added 2026-07-12 to match "Intelligent Assessment &
     * Report Card Workflow.docx"'s "...Approve → Result Locked → Publish
     * → Parents View → Archive": once released, a reviewer can explicitly
     * Lock a report card (a stronger, deliberate "this is truly final"
     * beyond just "released", nobody edits scores for this student's
     * term past this point without a reviewer sending it back first, see
     * ExaminationController::save()) and, separately, Archive a released
     * or locked one once its term/session is over, taking it out of
     * active lists.
     */
    public const STATUS_LOCKED = 'locked';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * Mid-term report cards (2026-08-07): a school can build a lighter
     * report from only part of a term's assessment components (by
     * default CA1+CA2, admin-configured on Assessment Structure)
     * alongside the existing full-term one, each going through the same
     * review workflow as an independent row for the same student/term.
     */
    public const REPORT_TYPE_MID_TERM = 'mid_term';

    public const REPORT_TYPE_FULL_TERM = 'full_term';

    public const PROMOTION_PROMOTE = 'promote';

    public const PROMOTION_REPEAT = 'repeat';

    public const PROMOTION_CONDITIONAL = 'conditional';

    /** Statuses that block further score edits for this student's term (ExaminationController::save() checks this), a reviewer must send it back to needs_correction first. */
    public const LOCKED_FOR_EDITING = [self::STATUS_APPROVED, self::STATUS_LOCKED, self::STATUS_RELEASED, self::STATUS_ARCHIVED];

    /**
     * Statuses a parent/student is allowed to see (user request
     * 2026-07-26: "parent should not be able to see report cards that
     * are yet to be release"). Once released, a card stays visible to
     * the family through its later workflow stages (locked, archived),
     * those are not an un-release.
     */
    public const VISIBLE_TO_FAMILY = [self::STATUS_RELEASED, self::STATUS_LOCKED, self::STATUS_ARCHIVED];

    protected $connection = 'tenant';

    protected $fillable = [
        'student_id', 'term_id', 'report_type', 'status', 'review_stage', 'assigned_to_staff_id', 'notes',
        'promotion_recommendation', 'promotion_reason', 'released_at', 'locked_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['released_at' => 'datetime', 'locked_at' => 'datetime', 'archived_at' => 'datetime'];
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
            self::STATUS_LOCKED => 'Locked',
            self::STATUS_ARCHIVED => 'Archived',
            default => ucfirst($status),
        };
    }

    public static function reportTypeLabel(string $reportType): string
    {
        return match ($reportType) {
            self::REPORT_TYPE_MID_TERM => 'Mid Term Report',
            self::REPORT_TYPE_FULL_TERM => 'Full Term Report',
            default => ucfirst($reportType),
        };
    }

    public static function promotionLabel(?string $value): ?string
    {
        return match ($value) {
            self::PROMOTION_PROMOTE => 'Promote',
            self::PROMOTION_REPEAT => 'Repeat',
            self::PROMOTION_CONDITIONAL => 'Conditional promotion',
            default => null,
        };
    }

    /**
     * The school's configured review chain, an ordered list of role
     * slugs (user directive 2026-07-12: "Admin configures Workflow...
     * Entirely configurable", e.g. Teacher -> Class Teacher -> VP
     * Academic -> Principal at one school, Teacher -> Principal at
     * another). Tenant Setting "report_card_review_chain" overrides
     * config('skulos.default_report_card_review_chain'); the default
     * (['class-teacher']) reproduces the original single-stage behaviour
     * exactly, so an existing school with no chain configured is
     * unaffected.
     */
    public static function reviewChain(): array
    {
        return Setting::grab('report_card_review_chain') ?? config('skulos.default_report_card_review_chain', ['class-teacher']);
    }

    public function currentStageRole(): ?string
    {
        return self::reviewChain()[$this->review_stage] ?? null;
    }

    public function isFinalStage(): bool
    {
        return $this->review_stage >= count(self::reviewChain()) - 1;
    }
}
