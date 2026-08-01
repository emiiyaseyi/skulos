<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A timed assessment assembled from the question bank. Named
 * "Assessment" (not "Paper" — an earlier, wrong name this class used
 * to have, per direct user correction: "I DID NOT HAVE PAPERS IN
 * LEARNGUARD, WHY AM I HAVING IT HERE NOW") to match what a student
 * actually sits, exactly as it does in the reference repo (there, the
 * thing IS a Moodle activity instance).
 *
 * `audience` (student|staff) decides who can see/take it — the same
 * underlying session/response engine serves both (see CbtSession's own
 * docblock), this is only a visibility filter, not a schema fork.
 *
 * Eligibility (see eligibleTakerIds()) is the SKULOS-native answer to
 * what Moodle gives LearnGuard for free via course enrolment: the
 * union of every enrolled student across `classArms()` and every
 * explicitly assigned row in `takers()` — attach no arms at all and
 * `takers()` alone defines who may attempt (the standalone case).
 */
class CbtAssessment extends Model
{
    use Auditable;

    public const AUDIENCE_STUDENT = 'student';

    public const AUDIENCE_STAFF = 'staff';

    protected $connection = 'tenant';

    protected $fillable = [
        'title', 'subject_id', 'term_id', 'instructions', 'duration_minutes', 'security_profile_id',
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

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function securityProfile(): BelongsTo
    {
        return $this->belongsTo(CbtSecurityProfile::class);
    }

    public function assessmentQuestions(): HasMany
    {
        return $this->hasMany(CbtAssessmentQuestion::class, 'assessment_id')->orderBy('sequence');
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(CbtQuestion::class, 'cbt_assessment_questions', 'assessment_id', 'question_id')
            ->withPivot(['id', 'sequence', 'points_override'])
            ->orderBy('cbt_assessment_questions.sequence');
    }

    public function classArms(): BelongsToMany
    {
        return $this->belongsToMany(ClassArm::class, 'cbt_assessment_class_arms', 'assessment_id', 'class_arm_id');
    }

    public function takers(): HasMany
    {
        return $this->hasMany(CbtAssessmentTaker::class, 'assessment_id');
    }

    /**
     * IDs of every School\User eligible to attempt this assessment: the
     * union of everyone enrolled in an attached class arm and everyone
     * explicitly assigned. Staff-audience assessments with no arms
     * attached rely on takers() alone, same as a student-audience one
     * with no arms — both are the "standalone" shape.
     *
     * "Enrolled in a class arm" is Student::current_class_arm_id (the
     * same direct-FK convention Attendance/Assignments already use for
     * "who's in this class right now"), mapped through Student::user_id
     * to the platform User id every CbtSession.takeable_id actually is.
     */
    public function eligibleTakerIds(): array
    {
        $armIds = $this->classArms()->pluck('class_arms.id');
        $fromArms = $armIds->isEmpty() ? [] : Student::whereIn('current_class_arm_id', $armIds)
            ->whereNotNull('user_id')->pluck('user_id')->all();

        $fromTakers = $this->takers()
            ->where('takeable_type', \App\Models\User::class)
            ->pluck('takeable_id')->all();

        return array_values(array_unique(array_merge($fromArms, $fromTakers)));
    }

    public function isEligible(\Illuminate\Database\Eloquent\Model $taker): bool
    {
        if ($this->classArms()->exists() || $this->takers()->exists()) {
            return in_array($taker->getKey(), $this->eligibleTakerIds(), true)
                || $this->takers()->where('takeable_type', $taker::class)->where('takeable_id', $taker->getKey())->exists();
        }

        // No arms and no explicit takers attached at all: falls open to
        // the whole audience, matching this feature's original (Phase
        // 2a) behaviour so an admin who never uses the new targeting
        // fields sees no regression.
        return true;
    }

    /** The points a question is actually worth on THIS assessment — its own override if set, else the question's own default. */
    public function pointsFor(CbtQuestion $question): float
    {
        $override = $this->assessmentQuestions()->where('question_id', $question->id)->value('points_override');

        return (float) ($override ?? $question->points);
    }

    public function totalPoints(): float
    {
        return $this->assessmentQuestions()->with('question')->get()
            ->sum(fn (CbtAssessmentQuestion $aq) => (float) ($aq->points_override ?? $aq->question->points));
    }
}
