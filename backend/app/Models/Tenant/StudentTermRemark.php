<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per student, per term remarks shown on the report card. Not per-subject,
 * so kept separate from `scores`.
 */
class StudentTermRemark extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = [
        'student_id', 'term_id', 'class_teacher_remark', 'principal_remark', 'next_term_begins_on',
        'height', 'weight', 'club',
    ];

    protected function casts(): array
    {
        return ['next_term_begins_on' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
