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

    // The parent's own response to an offer (2026-08-06), a separate state
    // machine from decision_status above (the SCHOOL's decision) — see the
    // migration that added these columns for the full reasoning.
    public const OFFER_ACCEPTED = 'accepted';

    public const OFFER_DECLINED = 'declined';

    protected $connection = 'tenant';

    protected $fillable = [
        'name', 'first_name', 'other_names', 'last_name', 'date_of_birth', 'email', 'phone', 'session_id', 'campus_id', 'school_class_id',
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
            'decided_at' => 'datetime',
            'offer_responded_at' => 'datetime',
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

    /** The admin's optional stream override, set at offer time (AdmissionAcceptanceService::accept() falls back to the lowest-population arm when this is null). */
    public function preferredClassArm(): BelongsTo
    {
        return $this->belongsTo(ClassArm::class, 'preferred_class_arm_id');
    }

    public function isActive(): bool
    {
        return $this->account_status === self::STATUS_ACTIVE
            && (! $this->expires_at || $this->expires_at->isFuture());
    }

    /**
     * Whether this applicant can sign in at all right now — broader than
     * isActive(): a pending_fee applicant (no real password issued yet)
     * can still sign in, using their own reference number as a bootstrap
     * password (user directive 2026-07-31: "applicants can use the
     * reference number as password too to continue their application
     * process"), so they are never locked out of their own application
     * just for not having paid yet. locked_out/archived still cannot.
     */
    public function canSignIn(): bool
    {
        return in_array($this->account_status, [self::STATUS_PENDING_FEE, self::STATUS_ACTIVE], true)
            && (! $this->expires_at || $this->expires_at->isFuture());
    }

    /**
     * The school's own admission pipeline, an ordered list of stage
     * labels a school can freely add to, edit, or reorder (user
     * directive 2026-07-31: "admin should be able to add new, edit
     * existing one or reorder the admission pipeline as peculiar to the
     * school") — backed by the real AdmissionPipelineStage table, not a
     * fixed catalog + toggle Setting anymore. Labels, not keys: nothing
     * downstream needs a stable identity for a stage, only its position
     * (pipeline_stage is a plain array index — see isFinalStage()/
     * advanceStage() below).
     *
     * @return list<string>
     */
    public static function pipelineChain(): array
    {
        return AdmissionPipelineStage::ordered()->pluck('label')->all();
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

    public function currentStageLabel(): ?string
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
     * Self-service "forgot my reference number/password" identity check
     * (user directive 2026-07-31): first name + last name, date of
     * birth, and either the parent phone or email on file, ALL matching,
     * before the reference/password are ever shown. Deliberately strict
     * (every field required to match) — this is the only gate standing
     * between a guesser and someone else's application details.
     */
    public static function matchForRecovery(string $firstName, string $lastName, string $dateOfBirth, string $phoneOrEmail): ?self
    {
        $phoneOrEmail = trim($phoneOrEmail);

        return static::whereRaw('lower(first_name) = ?', [mb_strtolower(trim($firstName))])
            ->whereRaw('lower(last_name) = ?', [mb_strtolower(trim($lastName))])
            ->whereDate('date_of_birth', $dateOfBirth)
            ->where(function ($q) use ($phoneOrEmail) {
                $q->where('phone', $phoneOrEmail)->orWhereRaw('lower(email) = ?', [mb_strtolower($phoneOrEmail)]);
            })
            ->first();
    }

    /**
     * What to actually show/email once identity is confirmed — a
     * password only exists after the application fee clears
     * (issueCredentials()); before that, the applicant's own reference
     * number doubles as a bootstrap password (see canSignIn()'s own
     * docblock), so recovery is never a dead end just because they
     * haven't paid yet.
     */
    public function recoveryCredentialLine(): string
    {
        return $this->recoverablePassword()
            ? "Password: {$this->recoverablePassword()}"
            : 'No password has been issued yet — sign in using your reference number as the password too, until your application fee is confirmed.';
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
        $style = Setting::grab('admission_password_style') ?? config('skulos.default_admission_password_style', 'random');
        $plain = match ($style) {
            'numeric' => collect(range(1, $length))->map(fn () => random_int(0, 9))->implode(''),
            'alphanumeric' => \App\Support\TemporaryPassword::generate($length),
            // No symbols even on the default: this password is
            // shown in an email and often read aloud to a parent by
            // phone, and symbols do not survive either (2026-08-16).
            default => \App\Support\TemporaryPassword::generate($length),
        };
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
