<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school division, e.g. "Nursery", "Primary", "Secondary". Fully
 * renameable and extensible per school (TD-007): the names are data.
 */
class Section extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'sequence'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
        ];
    }

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }
}
