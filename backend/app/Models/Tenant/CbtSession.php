<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One taker's timed attempt at one paper. See the creating migration's
 * docblock for why `takeable` is polymorphic.
 */
class CbtSession extends Model
{
    use Auditable;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_AUTO_SUBMITTED = 'auto_submitted';

    public const STATUS_GRADED = 'graded';

    protected $connection = 'tenant';

    protected $fillable = [
        'paper_id', 'takeable_type', 'takeable_id', 'started_at', 'ends_at', 'ip_address', 'browser_info',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'submitted_at' => 'datetime',
            'total_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'is_flagged' => 'boolean',
            'tab_switches' => 'integer',
            'fullscreen_exits' => 'integer',
            'face_missing_count' => 'integer',
            'risk_score' => 'integer',
        ];
    }

    public function paper(): BelongsTo
    {
        return $this->belongsTo(CbtPaper::class);
    }

    public function takeable(): MorphTo
    {
        return $this->morphTo();
    }

    public function responses(): HasMany
    {
        // Explicit FK: Eloquent's default guess would be "cbt_session_id"
        // (from the CbtSession class name), but the real column is
        // "session_id" (see the creating migration).
        return $this->hasMany(CbtResponse::class, 'session_id');
    }

    public function securityEvents(): HasMany
    {
        return $this->hasMany(CbtSecurityEvent::class, 'session_id')->orderByDesc('occurred_at');
    }

    public function isExpired(): bool
    {
        return $this->ends_at->isPast();
    }

    /**
     * A session is only ever "graded" once every response in it has left
     * `status=ungraded` — auto-graded objective answers get there
     * immediately on submit, essays only once a human (or the Phase 2c
     * double-grading reconciliation) finishes with them. Safe to call
     * repeatedly; a no-op until the last ungraded response clears.
     */
    public function finalizeIfFullyGraded(): void
    {
        if (! in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_AUTO_SUBMITTED], true)) {
            return;
        }

        $stillUngraded = $this->responses()->where('status', CbtResponse::STATUS_UNGRADED)->exists();
        if ($stillUngraded) {
            return;
        }

        $this->forceFill([
            'status' => self::STATUS_GRADED,
            'total_score' => $this->responses()->sum('score_awarded'),
        ])->save();
    }
}
