<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row in a school's own "Key Assessment Areas" catalog, grouped by
 * `category` (e.g. "Literacy & Phonics") with a `name` per skill within
 * it (e.g. "Letter Recognition & Sound"). See the migration for the
 * full design reasoning and StudentSkillRemark for the per-student value.
 */
class ReportCardSkillArea extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['category', 'name', 'sequence', 'is_active'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function remarks(): HasMany
    {
        return $this->hasMany(StudentSkillRemark::class, 'skill_area_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
