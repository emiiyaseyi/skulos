<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Basic Fee Records (Foundation Finance slice): a school's own fee
 * catalog for a term, e.g. "Tuition" or "PTA Levy" with an amount. No
 * per-section/per-class scoping in this minimal slice, a fee item
 * applies school wide for the term it belongs to.
 */
class FeeItem extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'amount', 'term_id', 'is_active', 'allow_installments'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'is_active' => 'boolean', 'allow_installments' => 'boolean'];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(FeeItemInstallment::class)->orderBy('sequence');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
