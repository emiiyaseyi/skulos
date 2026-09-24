<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A subject, e.g. "Mathematics". Offered by classes via class_subject.
 */
class Subject extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'code', 'category', 'sequence'];

    protected function casts(): array
    {
        return ['sequence' => 'integer'];
    }

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'class_subject')
            ->withPivot(['is_mandatory', 'is_examinable'])
            ->withTimestamps();
    }
}
