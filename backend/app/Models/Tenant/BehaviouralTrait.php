<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school-configurable behavioural/psychomotor trait shown on the report
 * card (user request 2026-07-18: "teachers can enter the behavioural
 * traits or psychomotor traits... this section is only for the class
 * teachers to enter and what the remarks should be or if there should be
 * scores entered for it, the admin set it"). Seeded once from
 * config('skulos.default_behavioural_traits'), then this real tenant
 * table is the only thing ever read at runtime, an admin can add/edit/
 * retire/reorder freely (the same seed-then-DB-backed shape grading_
 * scales/comments already established, never read live from config).
 */
class BehaviouralTrait extends Model
{
    use Auditable;

    public const TYPE_REMARK = 'remark';
    public const TYPE_SCORE = 'score';

    protected $connection = 'tenant';

    protected $table = 'behavioural_traits';

    public const DOMAIN_PSYCHOMOTOR = 'psychomotor';
    public const DOMAIN_AFFECTIVE = 'affective';

    protected $fillable = ['name', 'type', 'domain', 'max_score', 'sequence', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(StudentBehaviouralRating::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
