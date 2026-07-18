<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's submission for one assignment (user report 2026-07-18:
 * "a class teacher can see all the details of the assignment for her
 * class, submission, who has and not"). A student with no row here for a
 * given assignment simply has not submitted yet, no separate status
 * column needed.
 */
class AssignmentSubmission extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['assignment_id', 'student_id', 'content', 'file_path', 'submitted_at'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
