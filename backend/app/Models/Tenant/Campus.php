<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One of a school's physical sites. A school with a single site still has
 * exactly one campus row (marked is_main).
 */
class Campus extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'address', 'is_main'];

    protected function casts(): array
    {
        return ['is_main' => 'boolean'];
    }
}
