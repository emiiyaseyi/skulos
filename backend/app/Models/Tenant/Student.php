<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A student record, keyed externally by admission_number. Lives in the
 * school's tenant database. Email is optional and never required.
 */
class Student extends Model
{
    use Auditable;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_GRADUATED = 'graduated';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_SUSPENDED = 'suspended';

    // Automated admissions pipeline (2026-08-06): a Student row created the
    // moment a parent accepts an offer, before fees clear or the receiving
    // class teacher onboards them. Deliberately excluded from scopeActive()
    // below, which is what keeps a dormant admission off rosters/attendance/
    // gradebooks — see ClassTeacherOnboardingService::onboard() for the only
    // path that ever flips this to STATUS_ACTIVE.
    public const STATUS_PENDING_ENROLLMENT = 'pending_enrollment';

    protected $connection = 'tenant';

    protected $fillable = [
        'admission_number', 'first_name', 'last_name', 'other_names',
        'gender', 'date_of_birth', 'email', 'phone', 'parent_mobile',
        'admission_session_id', 'current_class_arm_id', 'house_id', 'club_id',
        'nationality', 'country', 'state_of_origin', 'lga', 'religion', 'blood_group',
        'genotype', 'previous_school', 'address', 'passport_path',
        'admitted_on', 'custom', 'status', 'user_id',
        'admission_fees_cleared_at', 'onboarded_at', 'onboarded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'admitted_on' => 'date',
            'custom' => 'array',
            'admission_fees_cleared_at' => 'datetime',
            'onboarded_at' => 'datetime',
        ];
    }

    public function guardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    /**
     * The platform login this student signs in with, if one has been
     * created for them. Built manually, not a plain belongsTo() — see
     * the matching comment on Staff::user()/StudentGuardian::user(), a
     * bare belongsTo() would inherit this model's tenant connection onto
     * User (a platform model), throwing "no such table: users".
     */
    public function user(): BelongsTo
    {
        $related = (new \App\Models\User())->setConnection(config('database.default'));

        return $this->newBelongsTo($related->newQuery(), $this, 'user_id', 'id', 'user');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function currentClassArm(): BelongsTo
    {
        return $this->belongsTo(ClassArm::class, 'current_class_arm_id');
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function fullName(): string
    {
        return implode(' ', array_filter([$this->first_name, $this->other_names, $this->last_name]));
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopePendingEnrollment($query)
    {
        return $query->where('status', self::STATUS_PENDING_ENROLLMENT);
    }

    /**
     * Automated admissions pipeline (2026-08-06): {SchoolAbbrev}/{ClassAbbrev}/
     * {Year}/{Serial}, e.g. "GA/JSS1/2026/0001". The class abbreviation is
     * deliberately a different, simpler algorithm than Applicant::abbreviate()
     * (school-name style, first letter of each word) — a class name like
     * "JSS 1" strips to "JSS1", not "J1", which is what "JSS1" actually
     * means to a school. Serial is scoped via a LIKE match against the
     * prefix itself (not a join through current_class_arm_id, which can
     * change later via promotion), so it stays stable for the life of the
     * number; withTrashed() is required per this project's own documented
     * gotcha (Student is soft-deleted, a withdrawn student's number must
     * never be reissued).
     */
    public static function nextAdmissionNumber(\App\Models\School $school, SchoolClass $schoolClass, ?AcademicSession $session = null): string
    {
        $schoolAbbrev = static::abbreviateSchoolName($school->name);
        $classAbbrev = static::abbreviateClassName($schoolClass->name);
        $year = static::admissionYear($session);
        $prefix = "{$schoolAbbrev}/{$classAbbrev}/{$year}/";

        $count = static::withTrashed()->where('admission_number', 'like', $prefix.'%')->count();
        $serial = str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);

        return $prefix.$serial;
    }

    protected static function abbreviateSchoolName(string $text): string
    {
        $words = array_filter(preg_split('/[\s\-]+/', trim($text)) ?: []);
        $letters = collect($words)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');

        return $letters !== '' ? $letters : mb_strtoupper(mb_substr($text, 0, 3));
    }

    /** Strip-spaces, not first-letter: "JSS 1" -> "JSS1", "Primary 1" -> "PRIMARY1" (capped to 8 chars). */
    protected static function abbreviateClassName(string $text): string
    {
        $stripped = mb_strtoupper(preg_replace('/[\s\-]+/', '', trim($text)) ?? '');

        return $stripped !== '' ? mb_substr($stripped, 0, 8) : 'GEN';
    }

    protected static function admissionYear(?AcademicSession $session): string
    {
        if ($session?->name && preg_match('/\d{4}/', $session->name, $m)) {
            return $m[0];
        }

        return (string) now()->year;
    }
}
