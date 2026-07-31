<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One class/session cycle that is (or was) open to new applicants, with
 * its own application fee. See the creating migration's docblock.
 */
class AdmissionWindow extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = [
        'session_id', 'school_class_id', 'campus_id', 'opens_at', 'closes_at',
        'application_fee_amount', 'acceptance_fee_amount', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'application_fee_amount' => 'decimal:2',
            'acceptance_fee_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function isOpen(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->opens_at && $this->opens_at->isFuture()) {
            return false;
        }
        if ($this->closes_at && $this->closes_at->isPast()) {
            return false;
        }

        return true;
    }
}
