<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school house, e.g. "Red House". housemaster_staff_id/
 * assistant_housemaster_staff_id reference Staff rows (plain ids, no FK)
 * so a house can exist before staff are added. Membership is recorded on
 * the member's own record (Student.house_id, Staff.house_id,
 * StudentGuardian.house_id), grouped into Students/Teachers/Parents on
 * the house's own page (user directive 2026-07-12). "is_active" false
 * means retired (user request 2026-07-10): kept for history, still
 * listed and downloadable, but dropped from every assignment picker so
 * it cannot be assigned to anyone new.
 */
class House extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'color', 'housemaster_staff_id', 'assistant_housemaster_staff_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function guardianMembers(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    public function sponsors(): HasMany
    {
        return $this->hasMany(HouseSponsor::class);
    }

    public function housemaster(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'housemaster_staff_id');
    }

    public function assistantHousemaster(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assistant_housemaster_staff_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
