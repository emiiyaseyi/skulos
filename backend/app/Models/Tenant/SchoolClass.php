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

    protected $connection = 'tenant';

    protected $table = 'school_classes';

    protected $fillable = ['section_id', 'name', 'sequence', 'is_active'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'is_active' => 'boolean'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function arms(): HasMany
    {
        return $this->hasMany(ClassArm::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'class_subject')
            ->withPivot(['is_mandatory', 'is_examinable'])
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
