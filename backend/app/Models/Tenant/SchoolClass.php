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

    protected $fillable = [
        'section_id', 'name', 'sequence', 'is_active', 'default_reviewer_staff_id', 'evaluation_style', 'grading_scale_id',
        'report_card_template', 'narrative_style', 'watermark_enabled', 'layout_override', 'tracks_physical_stats_start',
        'field_overrides',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'is_active' => 'boolean',
            'watermark_enabled' => 'boolean',
            'layout_override' => 'array',
            'tracks_physical_stats_start' => 'boolean',
            'field_overrides' => 'array',
        ];
    }

    /**
     * Narrative Evaluation (2026-07-18): comment based, no scores,
     * typically Creche/Reception. Per-class-per-term (2026-07-26, user
     * request: "re-toggleable across terms"): evaluation_style itself is
     * no longer read live, ClassNarrativeTerm rows are the source of
     * truth, this column only remains as the pre-migration value a
     * schema backfill (see the class_narrative_terms migration) copied
     * forward into the term that was current at the time.
     */
    public function isNarrativeEvaluationForTerm(?int $termId): bool
    {
        if (! $termId) {
            return false;
        }

        return $this->relationLoaded('narrativeTerms')
            ? $this->narrativeTerms->contains('term_id', $termId)
            : $this->narrativeTerms()->where('term_id', $termId)->exists();
    }

    public function narrativeTerms(): HasMany
    {
        return $this->hasMany(ClassNarrativeTerm::class);
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

    /**
     * This class's own explicit report card template choice, or null to
     * inherit its section's (which itself may inherit the school wide
     * default). Resolving the school wide fallback needs
     * ReportCardPreferences, a service -- see
     * ReportCardPreferences::templateForClass().
     */
    public function reportCardTemplate(): ?string
    {
        return $this->report_card_template ?: null;
    }

    /**
     * Only meaningful for a class that is already in Narrative
     * Evaluation mode for the term being rendered: this class's own
     * explicit choice, falling back to its section's (SchoolSectionBranding
     * once again resolves), 'comments' if neither ever set one.
     */
    public function resolvedNarrativeStyle(): string
    {
        if ($this->narrative_style === 'skills' || $this->narrative_style === 'comments') {
            return $this->narrative_style;
        }

        return $this->section?->narrativeStyle() ?? 'comments';
    }

    /** This class's own watermark choice, falling back to its section's, off if neither ever set one. */
    public function resolvedWatermarkEnabled(): bool
    {
        if ($this->watermark_enabled !== null) {
            return (bool) $this->watermark_enabled;
        }

        return $this->section?->watermarkEnabled() ?? false;
    }

    /**
     * This class's own report card block arrangement, or its section's,
     * or null to inherit the school wide layout (the pre-existing flat
     * Setting rows ReportCardLayout already reads). See
     * ReportCardLayout::resolvedFor() for the actual resolution used at
     * render time.
     */
    public function resolvedLayoutOverride(): ?array
    {
        return $this->layout_override ?: $this->section?->branding?->layout_override ?: null;
    }

    /**
     * This class's own Beginning/End of Term tracking choice, falling
     * back to its section's, falling back to null (meaning "inherit the
     * school wide Setting", ReportCardPreferences's own job to resolve
     * -- this class has no idea what that ultimate default is). Per
     * section/class (user directive 2026-09-22, confirmed via
     * AskUserQuestion: "Make it per-section... a real schema change"),
     * since PPMS only wants this on for Nursery, not Primary/Secondary.
     */
    public function resolvedTracksPhysicalStatsStart(): ?bool
    {
        if ($this->tracks_physical_stats_start !== null) {
            return (bool) $this->tracks_physical_stats_start;
        }

        return $this->section?->tracksPhysicalStatsStart();
    }
}
