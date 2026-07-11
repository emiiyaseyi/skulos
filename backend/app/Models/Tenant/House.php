<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school house, e.g. "Red House". housemaster_staff_id references a Staff
 * row (plain id, no FK) so a house can exist before staff are added.
 * Student membership is recorded on the student record (house_id).
 * "is_active" false means retired (user request 2026-07-10): kept for
 * history, still listed and downloadable, but dropped from every
 * assignment picker so it cannot be assigned to anyone new.
 */
class House extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'color', 'housemaster_staff_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
