<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class within a section, e.g. "Basic 1" under "Primary". The physical
 * teachable units (Basic 1 A, Basic 1 B) are ClassArm rows.
 */
class SchoolClass extends Model
{
    use Auditable;

    public const EVALUATION_STYLE_SCORES = 'scores';

    public const EVALUATION_STYLE_NARRATIVE = 'narrative';

    protected $connection = 'tenant';

    protected $table = 'school_classes';

    protected $fillable = ['section_id', 'name', 'sequence', 'is_active', 'default_reviewer_staff_id', 'evaluation_style', 'grading_scale_id'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'is_active' => 'boolean'];
    }

    /** Narrative Evaluation (2026-07-18): comment based, no scores, typically Creche/Reception. */
    public function isNarrativeEvaluation(): bool
    {
        return $this->evaluation_style === self::EVALUATION_STYLE_NARRATIVE;
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function defaultReviewer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'default_reviewer_staff_id');
    }

    /**
     * A class's own grading scale, overriding its section's own scale
     * (real bug found live on greenfield, 2026-07-24: JSS and SSS both
     * sit under one shared "Secondary" section, so a section level
     * scale alone cannot tell WAEC graded SSS apart from NECO/BECE
     * graded JSS). Unset falls back to the section's own scale, then
     * the school wide default, exactly as before this existed.
     */
    public function gradingScale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class);
    }

    public function arms(): HasMany
    {
        return $this->hasMany(ClassArm::class);
    }

    /**
     * Ordered by the subject's own curriculum position, not alphabetically
     * (found live 2026-07-12, "Class Order & Structure.docx"), so every
     * caller of $class->subjects (eager loaded or not) gets the real
     * order for free without needing to remember to sort it themselves.
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'class_subject')
            ->withPivot(['is_mandatory', 'is_examinable'])
            ->withTimestamps()
            ->orderBy('subjects.sequence')
            ->orderBy('subjects.name');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
