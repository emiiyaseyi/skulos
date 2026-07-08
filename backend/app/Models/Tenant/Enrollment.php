<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's placement in a class arm for one academic session. The
 * history of these rows is what drives promotion and transcripts.
 */
class Enrollment extends Model
{
    use Auditable;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PROMOTED = 'promoted';
    public const STATUS_REPEATED = 'repeated';
    public const STATUS_GRADUATED = 'graduated';
    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $connection = 'tenant';

    protected $fillable = [
        'student_id', 'class_arm_id', 'academic_session_id', 'status', 'enrolled_on',
    ];

    protected function casts(): array
    {
        return ['enrolled_on' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classArm(): BelongsTo
    {
        return $this->belongsTo(ClassArm::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }
}
