<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * See the creating migration's own docblock for why this exists — a
 * live face match that fails 3 client-side attempts lands here for a
 * human decision instead of blocking the taker outright.
 */
class CbtFaceApproval extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $connection = 'tenant';

    protected $fillable = ['precheck_session_id', 'match_score', 'attempts'];

    protected function casts(): array
    {
        return [
            'match_score' => 'float',
            'attempts' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function precheckSession(): BelongsTo
    {
        return $this->belongsTo(CbtPrecheckSession::class, 'precheck_session_id');
    }
}
