<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bank transfer payment a school submitted toward its own SKULOS
 * subscription (user request 2026-07-17), verified by a platform admin.
 * Platform scoped: unlike the tenant-side Payment model, School and
 * User both already live in this database, so submitted_by_user_id/
 * verified_by_user_id are real foreign keys here.
 */
class SubscriptionPayment extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'school_id', 'amount', 'bank_name', 'reference', 'evidence_path',
        'status', 'submitted_by_user_id', 'verified_by_user_id', 'verified_at', 'notes',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'verified_at' => 'datetime'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function evidenceUrl(): ?string
    {
        return $this->evidence_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->evidence_path) : null;
    }
}
