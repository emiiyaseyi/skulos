<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    protected $connection = 'tenant';

    protected $fillable = ['name', 'amount', 'term_id', 'is_active', 'allow_installments', 'is_optional'];

    /**
     * approval_status/approved_by_user_id/approved_at are deliberately
     * NOT fillable (same self-approval footgun Payment::$fillable
     * already closes off for its own status fields): every write to
     * those goes through FeeItemController::applyApprovalDecision()'s
     * atomic query-builder update(), never through create()/fill().
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'is_active' => 'boolean', 'allow_installments' => 'boolean', 'is_optional' => 'boolean', 'approved_at' => 'datetime'];
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

    /**
     * Which classes this fee item applies to (user request 2026-07-25:
     * "the fees is not same for nursery, pry 1-3, pry 4 and 5, pry 6,
     * jss 1-3, sss 1-3"). Empty means every class, see appliesToClass().
     */
    public function schoolClasses(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'fee_item_school_class');
    }

    /** No rows in schoolClasses() means "applies to every class", the fee item's original, still-default behaviour. */
    public function appliesToClass(?int $schoolClassId): bool
    {
        $classes = $this->relationLoaded('schoolClasses') ? $this->schoolClasses : $this->schoolClasses()->get();

        return $classes->isEmpty() || ($schoolClassId !== null && $classes->contains('id', $schoolClassId));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
