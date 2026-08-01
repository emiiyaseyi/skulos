<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A per-taker override on one assessment: their own window, extra
 * attempts, a different time limit, or permission to skip the precheck
 * wizard (an access arrangement, e.g. for a taker sitting in a
 * supervised room where the wizard is redundant).
 *
 * This is the single row the Scheduling, Bulk Scheduling and
 * Assessment Manager screens all write to, and the one place
 * effectiveWindow() consults, so those three screens can never
 * disagree with each other.
 */
class CbtSchedule extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = [
        'assessment_id', 'takeable_type', 'takeable_id', 'opens_at', 'closes_at',
        'extra_attempts', 'duration_override_minutes', 'skip_precheck', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'extra_attempts' => 'integer',
            'duration_override_minutes' => 'integer',
            'skip_precheck' => 'boolean',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CbtAssessment::class, 'assessment_id');
    }

    public function takeable(): MorphTo
    {
        return $this->morphTo();
    }
}
