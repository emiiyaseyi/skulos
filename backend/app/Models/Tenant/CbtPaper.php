<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A timed assessment assembled from the question bank. `audience`
 * (student|staff) decides who can see/take it — the same underlying
 * session/response engine serves both (see CbtSession's own docblock),
 * this is only a visibility filter, not a schema fork.
 */
class CbtPaper extends Model
{
    use Auditable;

    public const AUDIENCE_STUDENT = 'student';

    public const AUDIENCE_STAFF = 'staff';

    protected $connection = 'tenant';

    protected $fillable = [
        'title', 'instructions', 'duration_minutes', 'security_profile_id',
        'audience', 'requires_double_grading', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'requires_double_grading' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function securityProfile(): BelongsTo
    {
        return $this->belongsTo(CbtSecurityProfile::class);
    }

    public function paperQuestions(): HasMany
    {
        // Explicit FK: Eloquent's default guess would be "cbt_paper_id"
        // (from the CbtPaper class name), but the real column is
        // "paper_id" (see the creating migration).
        return $this->hasMany(CbtPaperQuestion::class, 'paper_id')->orderBy('sequence');
    }

    public function questions(): BelongsToMany
    {
        // Same explicit-FK reasoning as paperQuestions() above.
        return $this->belongsToMany(CbtQuestion::class, 'cbt_paper_questions', 'paper_id', 'question_id')
            ->withPivot(['id', 'sequence', 'points_override'])
            ->orderBy('cbt_paper_questions.sequence');
    }

    /** The points a question is actually worth on THIS paper — the paper's own override if set, else the question's own default. */
    public function pointsFor(CbtQuestion $question): float
    {
        $override = $this->paperQuestions()->where('question_id', $question->id)->value('points_override');

        return (float) ($override ?? $question->points);
    }

    public function totalPoints(): float
    {
        return $this->paperQuestions()->with('question')->get()
            ->sum(fn (CbtPaperQuestion $pq) => (float) ($pq->points_override ?? $pq->question->points));
    }
}
