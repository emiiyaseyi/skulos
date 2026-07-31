<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual "I forgot my reference number/password and the automatic
 * check didn't match" request, see the creating migration's docblock.
 * Same approval state machine shape as AdmissionDocument/AdmissionPayment.
 */
class AdmissionRecoveryRequest extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $connection = 'tenant';

    protected $fillable = [
        'remembered_name', 'description', 'email',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function matchedApplicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'matched_applicant_id');
    }
}
