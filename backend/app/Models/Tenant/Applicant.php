<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A prospective student, before any admission decision is made. Kept
 * deliberately separate from School\User + school_user (see the creating
 * migration's docblock for the full reasoning): an applicant is not a
 * school member, must never appear in any RBAC/dashboard/roster query
 * that assumes school_user rows are real members, and signs in through
 * its own "applicant" guard, not the platform's normal login.
 */
class Applicant extends Authenticatable
{
    use Auditable;

    public const STATUS_PENDING_FEE = 'pending_fee';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LOCKED_OUT = 'locked_out';

    public const STATUS_ARCHIVED = 'archived';

    protected $connection = 'tenant';

    protected $fillable = [
        'name', 'email', 'phone', 'session_id', 'campus_id', 'school_class_id',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'expires_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function isActive(): bool
    {
        return $this->account_status === self::STATUS_ACTIVE
            && (! $this->expires_at || $this->expires_at->isFuture());
    }

    /**
     * The school's configured admission pipeline, an ordered list of
     * stage keys (same shape as ReportCardReview::reviewChain(): tenant
     * Setting overrides config('skulos.default_admission_pipeline_chain'),
     * the default reproduces every stage in the natural order so an
     * existing school is unaffected until it deliberately trims one).
     *
     * @return list<string>
     */
    public static function pipelineChain(): array
    {
        return Setting::grab('admission_pipeline_chain') ?? config('skulos.default_admission_pipeline_chain', []);
    }

    public function currentStageKey(): ?string
    {
        return static::pipelineChain()[$this->pipeline_stage] ?? null;
    }

    public function isFinalStage(): bool
    {
        return $this->pipeline_stage >= count(static::pipelineChain()) - 1;
    }
}
