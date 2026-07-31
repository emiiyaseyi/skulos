<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One supporting document an applicant uploaded (birth certificate, last
 * report card, etc — see config('skulos.admission_document_types')), see
 * the creating migration's docblock for the approval state machine.
 */
class AdmissionDocument extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    protected $connection = 'tenant';

    /**
     * status/verified_by_user_id/verified_at/notes are deliberately NOT
     * fillable (same self-approval-footgun closure as AdmissionPayment/
     * Payment): every write to those fields goes through the
     * controller's own atomic update(), never through create()/fill().
     */
    protected $fillable = [
        'applicant_id', 'document_type', 'file_path',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
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
