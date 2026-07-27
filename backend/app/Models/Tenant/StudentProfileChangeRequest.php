<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parent's requested change to their child's profile, held for the
 * class teacher then the school admin to decide, in that order (user
 * request 2026-07-26). Two real sequential stages, unlike the flat
 * single-approver ProfileChangeRequest a member's own name/email/phone
 * already uses.
 */
class StudentProfileChangeRequest extends Model
{
    use Auditable;

    public const STATUS_PENDING_CLASS_TEACHER = 'pending_class_teacher';

    public const STATUS_PENDING_ADMIN = 'pending_admin';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED_BY_CLASS_TEACHER = 'rejected_by_class_teacher';

    public const STATUS_REJECTED_BY_ADMIN = 'rejected_by_admin';

    protected $connection = 'tenant';

    protected $fillable = [
        'student_id', 'submitted_by_user_id', 'original', 'requested', 'status',
        'class_teacher_staff_id', 'class_teacher_decided_by_user_id', 'class_teacher_decided_at', 'class_teacher_notes',
        'admin_decided_by_user_id', 'admin_decided_at', 'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'original' => 'array',
            'requested' => 'array',
            'class_teacher_decided_at' => 'datetime',
            'admin_decided_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classTeacherStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'class_teacher_staff_id');
    }

    /**
     * The requesting platform user. Built manually, not a plain
     * belongsTo(): a bare belongsTo() would inherit this model's tenant
     * connection onto User (a platform model), the same reasoning
     * ProfileChangeRequest::applicant() already documents.
     */
    public function submittedBy(): BelongsTo
    {
        $related = (new User())->setConnection(config('database.default'));

        return $this->newBelongsTo($related->newQuery(), $this, 'submitted_by_user_id', 'id', 'submittedBy');
    }

    public function isPendingClassTeacher(): bool
    {
        return $this->status === self::STATUS_PENDING_CLASS_TEACHER;
    }

    public function isPendingAdmin(): bool
    {
        return $this->status === self::STATUS_PENDING_ADMIN;
    }

    public function isDecided(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED_BY_CLASS_TEACHER, self::STATUS_REJECTED_BY_ADMIN], true);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING_CLASS_TEACHER => 'Awaiting class teacher',
            self::STATUS_PENDING_ADMIN => 'Awaiting school admin',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED_BY_CLASS_TEACHER => 'Rejected by class teacher',
            self::STATUS_REJECTED_BY_ADMIN => 'Rejected by admin',
            default => ucfirst($status),
        };
    }
}
