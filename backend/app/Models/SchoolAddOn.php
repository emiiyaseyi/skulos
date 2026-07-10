<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One add on a school has been granted, purchasable on any plan
 * regardless of tier (tracker section 14). "add_on_code" is a key into
 * config('skulos.add_on_catalog'); the catalog is the source of truth for
 * display metadata (name, category, which RBAC module it maps to, if
 * any), this row only records that a school has it.
 */
class SchoolAddOn extends Model
{
    use Auditable;

    protected $fillable = ['school_id', 'add_on_code', 'note', 'granted_by'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function catalogEntry(): ?array
    {
        foreach (config('skulos.add_on_catalog') as $category) {
            if (isset($category['items'][$this->add_on_code])) {
                return $category['items'][$this->add_on_code];
            }
        }

        return null;
    }
}
