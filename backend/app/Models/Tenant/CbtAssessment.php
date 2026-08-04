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

    /**
     * Taken by an Applicant (Admissions module), not a School\User at
     * all — CbtSession.takeable_type/takeable_id are already
     * polymorphic for exactly this, see that model's own docblock.
     * Reached from the applicant's own dashboard
     * (App\Http\Controllers\Admissions\AdmissionAssessmentController),
     * never through this controller's own school-portal routes.
     */
    public const AUDIENCE_APPLICANT = 'applicant';

    /** Every question's own points, added together — the ordinary meaning of "out of how many marks". */
    public const SCORE_SUM = 'sum';

    /**
     * Each section (question bank) is marked out of its own total
     * first, then every section's percentage counts equally toward the
     * final score — a 5-question bank and a 50-question bank end up
     * weighted the same, so one large bank cannot quietly dominate the
     * result just by having more questions in it. Only differs from
     * SCORE_SUM once an assessment actually has more than one section.
     */
    public const SCORE_AVERAGE_SECTIONS = 'average_sections';

    protected $connection = 'tenant';

    protected $fillable = [
        'title', 'subject_id', 'term_id', 'instructions', 'duration_minutes', 'security_profile_id',
        'audience', 'applicant_school_class_id', 'requires_double_grading', 'is_active',
        'brand_color', 'brand_name', 'results_released',
        // Access and grading policy. These MUST be listed: the
        // controller validates them and passes them to create()/
        // update(), so leaving them out does not protect anything, it
        // just silently drops the admin's saved settings — which is
        // exactly what happened before this line existed, letting a
        // configured attempt limit be ignored.
        'max_attempts', 'grade_method', 'pass_mark', 'questions_per_page', 'shuffle_questions',
        'randomize', 'num_questions', 'opens_at', 'closes_at', 'access_pin',
        'results_mode', 'results_delay_days', 'timer_mode', 'score_aggregation',
        'pass_message', 'fail_message',
        // Gradebook linkage (audience=student only) — see
        // GradebookSyncService's own docblock. created_by_staff_id is
        // deliberately NOT here: it is set once, from the
        // authenticated user's own Staff row, never from request input
        // (the same self-mutation-guard shape as Payment::status and
        // Applicant::pipeline_stage elsewhere in this app).
        'assessment_component_id', 'gradebook_sync_mode',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'requires_double_grading' => 'boolean',
            'is_active' => 'boolean',
            'paused_at' => 'datetime',
            'results_released' => 'boolean',
            'max_attempts' => 'integer',
            'pass_mark' => 'decimal:2',
            'questions_per_page' => 'integer',
            'shuffle_questions' => 'boolean',
            'randomize' => 'boolean',
            'num_questions' => 'integer',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'results_delay_days' => 'integer',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CbtSession::class, 'assessment_id');
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    /** The colour the take screen actually uses — this assessment's own override, else the school's. */
    public function brandColor(?string $schoolColor): ?string
    {
        return $this->brand_color ?: $schoolColor;
    }

    public function displayName(): string
    {
        return $this->brand_name ?: $this->title;
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

    /** The applicant-audience equivalent of classArms() — see the adding migration's own docblock for why Applicant needs a different targeting shape entirely. */
    public function applicantSchoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'applicant_school_class_id');
    }

    public function createdByStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }

    /** The CA/Exam component this assessment's score syncs into, if the teacher linked one — see GradebookSyncService. */
    public function assessmentComponent(): BelongsTo
    {
        return $this->belongsTo(AssessmentComponent::class);
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

    public function proctors(): HasMany
    {
        return $this->hasMany(CbtProctor::class, 'assessment_id');
    }

    public function graders(): HasMany
    {
        return $this->hasMany(CbtGrader::class, 'assessment_id');
    }

    public function gradingConfig(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CbtGradingConfig::class, 'assessment_id');
    }

    public function pools(): HasMany
    {
        return $this->hasMany(CbtQuestionPool::class, 'assessment_id')->orderBy('sequence');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(CbtSchedule::class, 'assessment_id');
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
        // The applicant-audience equivalent of the class-arm/takers gate
        // below: an Applicant isn't enrolled in anything pre-admission,
        // only applied for one SchoolClass, so this is checked first
        // and independently, never falling through to the User-shaped
        // logic that follows.
        if ($taker instanceof Applicant) {
            return $this->applicant_school_class_id === null
                || $this->applicant_school_class_id === $taker->school_class_id;
        }

        if ($this->classArms()->exists() || $this->takers()->exists()) {
            // eligibleTakerIds() is a School\User id list (class-arm
            // enrolment has no meaning for an Applicant, who isn't
            // enrolled in anything yet) — checked ONLY for a real User,
            // never for another takeable type, since a bare numeric id
            // match across two unrelated model spaces (e.g. Applicant
            // #5 vs a User #5 who happens to be arm-eligible) would
            // otherwise be a real false positive.
            if ($taker instanceof \App\Models\User && in_array($taker->getKey(), $this->eligibleTakerIds(), true)) {
                return true;
            }

            return $this->takers()->where('takeable_type', $taker::class)->where('takeable_id', $taker->getKey())->exists();
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
