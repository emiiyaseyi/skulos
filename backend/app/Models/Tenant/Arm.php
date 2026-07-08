<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable arm label, e.g. "A", "Gold". A class combines with an arm to
 * form a ClassArm (the enrollable unit).
 */
class Arm extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name'];
}
