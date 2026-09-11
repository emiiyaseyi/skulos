<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role a user holds at a school BEYOND their primary school_user.role_id
 * (2026-09-11, multi-role accounts). See the migration's own docblock for
 * the full reasoning; this model is intentionally thin, the same shape as
 * SchoolAddOn.
 */
class UserAdditionalRole extends Model
{
    use Auditable;

    protected $fillable = ['school_id', 'user_id', 'role_id', 'granted_by_user_id', 'granted_at'];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
