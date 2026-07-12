<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subject teacher's remark on one student's result in one subject, for
 * one term. See the migration for why this is separate from `scores` and
 * `student_term_remarks`.
 */
class SubjectRemark extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['student_id', 'subject_id', 'term_id', 'class_arm_id', 'remark'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
