<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone allowed to grade written answers.
 *
 * Two things make this more than a permission row:
 *
 * - `grade_layer` — 1 is the first grader, 2 is a second review that
 *   can override layer 1. Both layers' figures are kept forever in
 *   cbt_essay_grades, so an override never destroys the original.
 * - `anonymous` — an anonymous grader is never shown who wrote the
 *   answer, only "Candidate #<session id>", matching the reference
 *   repo's own scheme. It is per-GRADER, not per-assessment, so one
 *   school can run a blind first marker and a named moderator on the
 *   same paper.
 *
 * A null assessment_id means the person grades every assessment.
 */
class CbtGrader extends Model
{
    use Auditable;

    public const LAYER_FIRST = 1;

    public const LAYER_REVIEW = 2;

    protected $connection = 'tenant';

    protected $fillable = ['assessment_id', 'user_id', 'anonymous', 'grade_layer', 'deadline'];

    protected function casts(): array
    {
        return [
            'anonymous' => 'boolean',
            'grade_layer' => 'integer',
            'deadline' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CbtAssessment::class, 'assessment_id');
    }

    public function isReviewer(): bool
    {
        return $this->grade_layer === self::LAYER_REVIEW;
    }

    public function isOverdue(): bool
    {
        return $this->deadline !== null && $this->deadline->isPast();
    }

    /** What this grader is allowed to see in place of the taker's real name. */
    public function labelFor(CbtSession $session): string
    {
        return $this->anonymous
            ? 'Candidate #'.$session->id
            : ($session->takeable?->name ?? 'Unknown');
    }
}
