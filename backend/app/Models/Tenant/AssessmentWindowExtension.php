<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One teacher's personal request to keep entering scores past a closed
 * assessment window (user request 2026-07-18: "the teacher will have to
 * request within the window period, give a reason and when they want the
 * new window to close for them... the admin can review, adjust their
 * window, reject or approve"). Scoped to the requesting staff member
 * only, not a change to the window everyone else sees.
 */
class AssessmentWindowExtension extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $connection = 'tenant';

    /**
     * status/reviewed_by_user_id/reviewed_at are deliberately NOT
     * fillable, the same self-approval guard Payment::$fillable already
     * established: every write to those fields goes through
     * AssessmentWindowController's own review actions, never a raw
     * create()/fill() from request input.
     */
    protected $fillable = ['assessment_window_id', 'staff_id', 'reason', 'requested_close_at', 'admin_note'];

    protected function casts(): array
    {
        return ['requested_close_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function window(): BelongsTo
    {
        return $this->belongsTo(AssessmentWindow::class, 'assessment_window_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
