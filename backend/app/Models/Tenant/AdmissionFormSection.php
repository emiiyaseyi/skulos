<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One group of fields on the school's custom application form, shown
 * together on one step of the multi-step wizard.
 */
class AdmissionFormSection extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['label', 'step', 'sequence', 'is_active'];

    protected function casts(): array
    {
        return ['step' => 'integer', 'sequence' => 'integer', 'is_active' => 'boolean'];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(AdmissionFormField::class);
    }
}
