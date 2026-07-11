<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Local Government Area within a Nigerian state, for the reusable geo
 * picker (user request 2026-07-11). Platform level reference data, seeded
 * once by GeoSeeder from the real fetched 774 LGA dataset. No other
 * African country has rows here, no other country has LGA equivalent data
 * available.
 */
class Lga extends Model
{
    protected $fillable = [
        'state_id', 'name',
    ];

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }
}
