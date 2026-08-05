<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One part of the assessment format, e.g. "First CA" (max 20) or "Exam"
 * (max 60). A school can define any set; their max_scores sum to the
 * subject total. "type" ('ca' or 'exam') is purely organizational, for
 * grouping the admin screen into "Test / Continuous Assessment" and
 * "Examinations" (user request 2026-07-10) — ResultService sums every
 * component regardless of type, it does not affect grading.
 *
 * Per-term structure (2026-08-05, user directive: "the assessment
 * structure can be changed per term so its not a fixed one across the
 * whole session or school life time"): term_id null is a UNIVERSAL
 * component applying to every term; a term with its own rows uses ONLY
 * those (see forTerm() below) — the same "fork replaces, never merges
 * with, the universal fallback" shape already proven for
 * TimetableEntry.term_id and TimetablePeriod.section_id.
 */
class AssessmentComponent extends Model
{
    use Auditable;

    public const TYPE_CA = 'ca';

    public const TYPE_EXAM = 'exam';

    protected $connection = 'tenant';

    protected $fillable = ['term_id', 'name', 'type', 'max_score', 'sequence'];

    protected function casts(): array
    {
        return ['max_score' => 'decimal:2', 'sequence' => 'integer'];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * The active structure for a given term: that term's OWN rows if it
     * has any, otherwise the universal (term_id null) set every school
     * already had before this feature existed. A null $term (or a term
     * with no id yet) always means "the universal set".
     */
    public static function forTerm(Term|int|null $term): Collection
    {
        $termId = $term instanceof Term ? $term->id : $term;
        if ($termId) {
            $own = static::where('term_id', $termId)->orderBy('sequence')->get();
            if ($own->isNotEmpty()) {
                return $own;
            }
        }

        return static::whereNull('term_id')->orderBy('sequence')->get();
    }

    public static function totalPossible(Term|int|null $term = null): float
    {
        return (float) static::forTerm($term)->sum('max_score');
    }

    public function scopeCa($query)
    {
        return $query->where('type', self::TYPE_CA);
    }

    public function scopeExam($query)
    {
        return $query->where('type', self::TYPE_EXAM);
    }
}
