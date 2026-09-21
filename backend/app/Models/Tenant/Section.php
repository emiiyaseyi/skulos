<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A school division, e.g. "Nursery", "Primary", "Secondary". Fully
 * renameable and extensible per school (TD-007): the names are data.
 */
class Section extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'sequence', 'grading_scale_id', 'signer_name'];

    /**
     * The name shown under the signature image on this section's report
     * cards. Left unset, falls back to a Head Teacher/Principal
     * heuristic keyed off the section's own name (user request
     * 2026-07-27), admin-overridable per section under Report Settings.
     */
    public function signerLabel(): string
    {
        return $this->signer_name ?: (stripos($this->name, 'secondary') !== false ? 'Principal' : 'Head Teacher');
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
        ];
    }

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    /** Which grading scale a report card should use for a student in this section, unset falls back to GradingScale::default() (user request 2026-07-24). */
    public function gradingScale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class);
    }

    /** This section's own logo/colour override, if it has one (user request 2026-09-09). No row means it inherits the school's default entirely. */
    public function branding(): HasOne
    {
        return $this->hasOne(SchoolSectionBranding::class);
    }

    /** The logo actually used for this section: its own override, or the school's default. */
    public function resolvedLogoPath(\App\Models\School $school): ?string
    {
        return $this->branding?->logo_path ?: $school->logo_path;
    }

    /** The brand colour actually used for this section: its own override, or the school's default. */
    public function resolvedBrandColor(\App\Models\School $school): ?string
    {
        return $this->branding?->brand_color ?: $school->brand_color;
    }

    /**
     * Which base report card look this section's own report cards use.
     * Null means "inherit the school wide choice", the pre-existing,
     * only-ever behaviour before per-section overrides existed (user
     * directive 2026-09-21).
     */
    public function reportCardTemplate(): ?string
    {
        return $this->branding?->report_card_template ?: null;
    }

    /**
     * Only meaningful for a class that is already in Narrative
     * Evaluation mode for the term being rendered: 'comments' (the
     * default, unchanged pre-existing free text per subject table) or
     * 'skills' (a grouped category/skill remark table, built for a
     * Nursery/Early Years style rubric).
     */
    public function narrativeStyle(): string
    {
        return $this->branding?->narrative_style === 'skills' ? 'skills' : 'comments';
    }

    /** Whether this section's own report cards render its resolved logo as a background watermark. Off for every section until an admin turns it on. */
    public function watermarkEnabled(): bool
    {
        return (bool) ($this->branding?->watermark_enabled ?? false);
    }
}
