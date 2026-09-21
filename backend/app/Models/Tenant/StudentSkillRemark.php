<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One class teacher entered remark for one student, one term, one
 * ReportCardSkillArea -- the 'skills' narrative style's own analogue of
 * StudentSubjectComment.
 */
class StudentSkillRemark extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['student_id', 'term_id', 'skill_area_id', 'remark'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function skillArea(): BelongsTo
    {
        return $this->belongsTo(ReportCardSkillArea::class, 'skill_area_id');
    }
}
