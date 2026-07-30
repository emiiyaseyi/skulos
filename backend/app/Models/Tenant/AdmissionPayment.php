<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One application (or acceptance) fee submission, see the creating
 * migration's docblock. Approval state machine is a deliberate copy of
 * Payment's own shape.
 */
class AdmissionPayment extends Model
{
    use Auditable;

    public const TYPE_APPLICATION = 'application';

    public const TYPE_ACCEPTANCE = 'acceptance';

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    protected $connection = 'tenant';

    /**
     * status/verified_by_user_id/verified_at/notes are deliberately NOT
     * fillable (same self-approval-footgun closure as Payment): every
     * write to those fields goes through the controller's own atomic
     * update(), never through create()/fill().
     */
    protected $fillable = [
        'applicant_id', 'fee_type', 'amount', 'bank_name', 'reference', 'evidence_path',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'verified_at' => 'datetime'];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
