<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
