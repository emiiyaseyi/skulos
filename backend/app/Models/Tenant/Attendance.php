<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attendance mark for a student or staff member on a date. Status must
 * be one of the school's configured attendance statuses.
 */
class Attendance extends Model
{
    use Auditable;

    public const PERSON_STUDENT = 'student';
    public const PERSON_STAFF = 'staff';

    protected $connection = 'tenant';

    protected $table = 'attendance';

    protected $fillable = [
        'date', 'person_type', 'person_id', 'class_arm_id',
        'status', 'note', 'recorded_by_user_id',
    ];

    // 'date' is intentionally NOT cast to a datetime: it is a date-only
    // column stored as "YYYY-MM-DD", and the idempotent updateOrCreate match
    // relies on that exact string (a datetime cast would serialize it to
    // "YYYY-MM-DD 00:00:00" and break the match).

    public static function statuses(): array
    {
        return config('skulos.attendance_statuses', ['present', 'absent']);
    }

    public function classArm(): BelongsTo
    {
        return $this->belongsTo(ClassArm::class);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('date', $date);
    }

    public function scopeStudents($query)
    {
        return $query->where('person_type', self::PERSON_STUDENT);
    }

    public function scopeStaff($query)
    {
        return $query->where('person_type', self::PERSON_STAFF);
    }
}
