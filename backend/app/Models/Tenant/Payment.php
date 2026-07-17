<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bank transfer payment submission against one student (Foundation
 * Finance slice: Bank Transfer Payment Option, Payment Evidence Upload,
 * Payment Verification). evidence_path is only ever a storage path
 * (standing "never file bytes in the database" rule), verified by a
 * human (Bursar/Accountant/admin), no payment gateway involved.
 * submitted_by_user_id/verified_by_user_id reference a platform user,
 * no cross database FK (same pattern as Notification::user_id).
 */
class Payment extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    protected $connection = 'tenant';

    protected $fillable = [
        'student_id', 'fee_item_id', 'amount', 'method', 'bank_name', 'reference',
        'evidence_path', 'status', 'submitted_by_user_id', 'verified_by_user_id', 'verified_at', 'notes',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'verified_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function feeItem(): BelongsTo
    {
        return $this->belongsTo(FeeItem::class);
    }

    public function evidenceUrl(): ?string
    {
        return $this->evidence_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->evidence_path) : null;
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
