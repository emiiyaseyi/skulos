<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A house can have more than one sponsor (user directive 2026-07-12).
 * Either student_guardian_id (an existing parent) or name (free typed,
 * for a sponsor who is not a parent) is set, never both.
 */
class HouseSponsor extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['house_id', 'student_guardian_id', 'name'];

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(StudentGuardian::class, 'student_guardian_id');
    }

    public function displayName(): string
    {
        return $this->guardian?->name ?? $this->name ?? 'Unnamed sponsor';
    }
}
