<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One grader's own figure for one written answer.
 *
 * The score that actually counts lives on cbt_responses; this table is
 * the append-only history behind it, so a layer-2 review that changes
 * a mark never erases what layer 1 recorded. That history is what the
 * grade-history popup shows, and it is the only way a school can
 * answer "who gave this mark, and what did the first marker say?"
 * after the fact.
 *
 * The reference repo stores the same rows but lets the live score be
 * plain last-write-wins across both layers, with no reconciliation at
 * all. SKULOS keeps the history identically but resolves the live
 * score deliberately — see CbtResponse and the grading service — so a
 * layer-2 figure wins because it is a REVIEW, not merely because it
 * happened to be saved second.
 */
class CbtEssayGrade extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['response_id', 'grader_user_id', 'grade_layer', 'score', 'feedback'];

    protected function casts(): array
    {
        return [
            'grade_layer' => 'integer',
            'score' => 'decimal:2',
        ];
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(CbtResponse::class, 'response_id');
    }
}
