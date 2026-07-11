<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A state or province within an African country, for the reusable geo
 * picker (user request 2026-07-11). Platform level reference data, seeded
 * once by GeoSeeder. Only Nigeria's states carry any LGAs.
 */
class State extends Model
{
    protected $fillable = [
        'country_id', 'name',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function lgas(): HasMany
    {
        return $this->hasMany(Lga::class);
    }
}
