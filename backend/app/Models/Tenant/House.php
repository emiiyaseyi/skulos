<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * A school house, e.g. "Red House". housemaster_staff_id references a Staff
 * row (plain id, no FK) so a house can exist before staff are added.
 * Student membership is recorded on the student record (house_id).
 */
class House extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'color', 'housemaster_staff_id'];
}
