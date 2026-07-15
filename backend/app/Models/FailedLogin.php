<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A logged failed sign-in attempt. Never stores the attempted password,
 * only the identifier typed and why it failed (security: sensitive
 * attributes excluded from logs, matching the standing audit trail rule).
 * Write-once: no updated_at column at all.
 */
class FailedLogin extends Model
{
    public const UPDATED_AT = null;

    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_BAD_PASSWORD = 'bad_password';

    public const REASON_NOT_MEMBER = 'not_member';

    public const REASON_WRONG_PORTAL = 'wrong_portal';

    protected $fillable = [
        'school_id', 'identifier', 'reason', 'ip_address', 'user_agent',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            self::REASON_NOT_FOUND => 'No account found',
            self::REASON_BAD_PASSWORD => 'Wrong password',
            self::REASON_NOT_MEMBER => 'Not a member of this school',
            self::REASON_WRONG_PORTAL => 'Wrong portal (staff/parent/student) for this account',
            default => ucfirst(str_replace('_', ' ', $reason)),
        };
    }
}
