<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A free-text, per-subject comment for one student in one term, the data
 * source for the comments-only report card mode (2026-07-18): unlike a
 * Score (a number) or a GradingBand's auto remark (a fixed grade-band
 * label like "Distinction"), this is the class teacher's own written
 * comment, used by report card templates that show no scores at all
 * (typically Nursery/Early Years).
 */
class StudentSubjectComment extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['student_id', 'subject_id', 'term_id', 'comment'];

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
