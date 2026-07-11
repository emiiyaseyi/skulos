<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An African country for the reusable country to state to LGA picker (user
 * request 2026-07-11). Platform level reference data, seeded once by
 * GeoSeeder from real fetched data, not editable through the app. Nigeria
 * carries sequence = 1 so it always sorts first; every other country is
 * null and sorts alphabetically.
 */
class Country extends Model
{
    protected $fillable = [
        'name', 'iso2', 'sequence',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
        ];
    }

    public function states(): HasMany
    {
        return $this->hasMany(State::class);
    }

    public function scopeOrdered($query)
    {
        return $query->orderByRaw('sequence is null')->orderBy('sequence')->orderBy('name');
    }
}
