<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One taker's timed attempt at one assessment. See the creating
 * migration's docblock for why `takeable` is polymorphic.
 */
class CbtSession extends Model
{
    use Auditable;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_AUTO_SUBMITTED = 'auto_submitted';

    public const STATUS_GRADED = 'graded';

    /**
     * Stopped by an invigilator (or the auto-suspend risk threshold)
     * rather than finished by the taker — has no score, and can be
     * reinstated back to in_progress from the Admin Backend console.
     */
    public const STATUS_SUSPENDED = 'suspended';

    protected $connection = 'tenant';

    protected $fillable = [
        'assessment_id', 'takeable_type', 'takeable_id', 'started_at', 'ends_at', 'ip_address', 'browser_info',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'submitted_at' => 'datetime',
            'gradebook_synced_at' => 'datetime',
            'total_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'is_flagged' => 'boolean',
            'tab_switches' => 'integer',
            'fullscreen_exits' => 'integer',
            'face_missing_count' => 'integer',
            'risk_score' => 'integer',
            'break_requested' => 'boolean',
            'break_started_at' => 'datetime',
            'break_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** True while the taker's own assessment has been paused wholesale by staff. */
    public function isPaused(): bool
    {
        return $this->assessment?->paused_at !== null;
    }

    /** Live-monitor presence: a taker whose browser has not checked in for 2 minutes reads as offline. */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(2));
    }

    /** True from the moment a break is approved until its own break_ends_at passes. */
    public function isOnBreak(): bool
    {
        return $this->break_started_at !== null && $this->break_ends_at !== null && $this->break_ends_at->isFuture();
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CbtAssessment::class, 'assessment_id');
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

    public function snapshots(): HasMany
    {
        return $this->hasMany(CbtSnapshot::class, 'session_id')->orderByDesc('captured_at');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(CbtIncident::class, 'session_id')->orderByDesc('id');
    }

    /**
     * This attempt's score broken down by question bank (pool) —
     * "the assessment was made of many questions banks... score in
     * Mathematics, English Language etc" (user request 2026-08-12).
     * An assessment with more than one CbtQuestionPool (each pool is a
     * real, separately labelled "question bank" in the builder UI, see
     * cbt/assessments/edit.blade.php's own "Question banks (pools)"
     * section) draws a distinct set of questions per pool for this
     * session (CbtSessionQuestion.pool_id) — this groups this session's
     * own questions by that pool and sums each group's earned vs
     * possible points. Fixed, non-pool questions (CbtSessionQuestion.
     * pool_id null) are bucketed as "General questions". Returns []
     * for a single-bank (or no-bank) assessment, where a breakdown adds
     * nothing over the single overall score already shown.
     *
     * @return array<int, array{label: string, earned: float, max: float}>
     */
    public function scoreBreakdown(): array
    {
        $sessionQuestions = CbtSessionQuestion::where('session_id', $this->id)
            ->with('pool.category')
            ->get();

        if ($sessionQuestions->pluck('pool_id')->unique()->count() < 2) {
            return [];
        }

        $earnedByQuestion = CbtResponse::where('session_id', $this->id)
            ->pluck('score_awarded', 'question_id');

        return $sessionQuestions->groupBy('pool_id')
            ->map(function ($questions) use ($earnedByQuestion) {
                $pool = $questions->first()->pool;
                $label = $pool
                    ? ($pool->label ?: ($pool->category?->name ?? 'Question bank'))
                    : 'General questions';

                return [
                    'label' => $label,
                    'earned' => (float) $questions->sum(fn (CbtSessionQuestion $q) => (float) ($earnedByQuestion[$q->question_id] ?? 0)),
                    'max' => (float) $questions->sum('points'),
                ];
            })
            ->sortBy('label')
            ->values()
            ->all();
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

        // Honours the assessment's score_aggregation policy (plain sum,
        // or each section averaged in equally) — see
        // QuestionSetBuilder::scoreTotalsFor()'s own docblock. Also
        // re-sets max_score, idempotently: correct already under 'sum',
        // and a fixed 100 under 'average_sections' regardless of when
        // grading finishes.
        $totals = app(\App\Services\Cbt\QuestionSetBuilder::class)->scoreTotalsFor($this);

        $this->forceFill([
            'status' => self::STATUS_GRADED,
            'total_score' => $totals['score'],
            'max_score' => $totals['max'],
        ])->save();

        // Gradebook auto-sync (user directive 2026-08-02): this is the
        // one real choke point every grading path already flows through
        // (immediate at submit for an all-objective assessment, or once
        // essay grading finishes the last response) — a no-op unless
        // the assessment actually links a component AND the teacher
        // chose 'auto' sync, see GradebookSyncService::syncIfAutomatic().
        app(\App\Services\Cbt\GradebookSyncService::class)->syncIfAutomatic($this);
    }
}
