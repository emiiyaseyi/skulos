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

    // Decision state (Phase 1g), a separate state machine from
    // account_status above — see the migration that added these columns.
    public const DECISION_OFFERED = 'offered';

    public const DECISION_REJECTED = 'rejected';

    public const DECISION_WAITLISTED = 'waitlisted';

    public const DECISION_CONVERTED = 'converted';

    protected $connection = 'tenant';

    protected $fillable = [
        'name', 'first_name', 'last_name', 'date_of_birth', 'email', 'phone', 'session_id', 'campus_id', 'school_class_id',
    ];

    protected $hidden = [
        'password', 'password_plain_encrypted', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'expires_at' => 'datetime',
            'date_of_birth' => 'date',
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

    public function convertedStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'converted_student_id');
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

    /**
     * Builds the applicant's admission_number/reference (user directive
     * 2026-07-31: "the application number generation should be set by
     * admin under the admission setting"). "structured" derives a
     * school + class abbreviation from their real names; "custom" (the
     * default, reproducing this module's original fixed APP/0001 shape)
     * appends the serial to an admin-chosen prefix instead. Either way
     * the serial is a plain running count, same as before this setting
     * existed — Applicant is never soft-deleted, so a plain count() is
     * an honest running total.
     */
    public static function nextReferenceNumber(?SchoolClass $schoolClass = null): string
    {
        $serial = str_pad((string) (static::count() + 1), 4, '0', STR_PAD_LEFT);
        $style = Setting::grab('admission_reference_style') ?? config('skulos.default_admission_reference_style', 'custom');

        if ($style === 'structured') {
            $school = app(\App\Services\Tenancy\TenantManager::class)->current();
            $schoolAbbrev = $school ? static::abbreviate($school->name) : 'SCH';
            $classAbbrev = $schoolClass ? static::abbreviate($schoolClass->name) : 'GEN';

            return "{$schoolAbbrev}/{$classAbbrev}/{$serial}";
        }

        $prefix = Setting::grab('admission_reference_prefix') ?? config('skulos.default_admission_reference_prefix', 'APP');

        return "{$prefix}/{$serial}";
    }

    protected static function abbreviate(string $text): string
    {
        $words = array_filter(preg_split('/[\s\-]+/', trim($text)) ?: []);
        $letters = collect($words)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');

        return $letters !== '' ? $letters : mb_strtoupper(mb_substr($text, 0, 3));
    }

    public function currentStageKey(): ?string
    {
        return static::pipelineChain()[$this->pipeline_stage] ?? null;
    }

    public function isFinalStage(): bool
    {
        return $this->pipeline_stage >= count(static::pipelineChain()) - 1;
    }

    public function advanceStage(): void
    {
        if (! $this->isFinalStage()) {
            $this->forceFill(['pipeline_stage' => $this->pipeline_stage + 1])->save();
        }
    }

    /**
     * Called once the application fee is verified (AdmissionFeeReview
     * Controller): generates the password an applicant needed to be told
     * about (user directive 2026-07-30: "a generated password they will
     * see upon confirmation of admission application fee"), opens their
     * temporary window, and activates the account. The plain password is
     * returned (not stored anywhere in plain form, the 'hashed' cast on
     * $password hashes it on save) so the caller can email it once, the
     * only time it is ever knowable again.
     */
    public function issueCredentials(): string
    {
        $length = (int) (Setting::grab('admission_password_length') ?? config('skulos.default_admission_password_length', 10));
        $length = max(6, min(24, $length));
        $plain = \Illuminate\Support\Str::password($length);
        $ttlDays = (int) (Setting::grab('admission_account_ttl_days') ?? config('skulos.default_admission_account_ttl_days', 30));

        $this->forceFill([
            'password' => $plain,
            'password_plain_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString($plain),
            'account_status' => self::STATUS_ACTIVE,
            'expires_at' => now()->addDays($ttlDays),
        ])->save();

        return $plain;
    }

    /**
     * Decrypts the recoverable copy stored by issueCredentials(), for the
     * applicant's own signed status page and for admin staff viewing the
     * pipeline board — never for anything serialized to JSON or a public
     * API (see the $hidden entry above).
     */
    public function recoverablePassword(): ?string
    {
        if (! $this->password_plain_encrypted) {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($this->password_plain_encrypted);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return null;
        }
    }
}
