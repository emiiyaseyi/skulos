<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which subject a section (or one specific class) sits, on one exam
 * date, for one paper slot. scope_id points into either sections or
 * school_classes depending on scope_type — a plain id, not a real FK,
 * the same "which table it points into depends on a sibling column"
 * shape CustomFieldValue's own entity_type/entity_id pair already uses.
 */
class ExamTimetableEntry extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public const SCOPE_SECTION = 'section';

    public const SCOPE_CLASS = 'class';

    protected $fillable = ['exam_date', 'exam_paper_id', 'scope_type', 'scope_id', 'subject_id'];

    protected function casts(): array
    {
        return ['exam_date' => 'date'];
    }

    public function paper(): BelongsTo
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
