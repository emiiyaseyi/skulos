<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school club, e.g. "Debate Club". Deliberately the same shape as
 * House (2026-08-16): patron_staff_id/assistant_patron_staff_id
 * reference Staff rows as plain ids with no foreign key, so a club can
 * exist before any staff are added, and membership is recorded on the
 * member's own record (Student.club_id, Staff.club_id,
 * StudentGuardian.club_id) rather than a pivot.
 *
 * One club per member, matching houses, because that is what the report
 * card's own single "Club" line has always shown. Multi-club membership
 * would need a pivot and a decision about which club prints on the
 * report card, so it is not guessed at here.
 *
 * "is_active" false means retired: kept for history and still listed,
 * but dropped from every assignment picker so nobody new can be put in
 * it — same rule houses already follow.
 */
class Club extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'description', 'patron_staff_id', 'assistant_patron_staff_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function guardianMembers(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    public function patron(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'patron_staff_id');
    }

    public function assistantPatron(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assistant_patron_staff_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
