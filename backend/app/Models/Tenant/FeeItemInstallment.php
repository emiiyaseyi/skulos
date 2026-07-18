<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One scheduled partial payment of a fee item, e.g. "1st Installment,
 * ₦20,000, due 15 Sep" (Advance Finance, 2026-07-18). Only meaningful
 * when its FeeItem::allow_installments is true; a Payment may optionally
 * reference one of these instead of (or alongside) the fee item itself.
 */
class FeeItemInstallment extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['fee_item_id', 'label', 'amount', 'due_date', 'sequence'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_date' => 'date'];
    }

    public function feeItem(): BelongsTo
    {
        return $this->belongsTo(FeeItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Sum of already-verified payments against this installment, for the collection-progress view. */
    public function verifiedTotal(): float
    {
        return (float) $this->payments()->where('status', Payment::STATUS_VERIFIED)->sum('amount');
    }
}
