<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A school member's requested change to their own name/email/phone,
 * held for a school admin to approve (user request: "a user profile
 * page... gated behind a school-admin approval workflow before any
 * change takes effect"). A member trusted with staff.update (an owner
 * or admin) applies their own changes immediately instead, see
 * ProfileController.
 */
class ProfileChangeRequest extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $connection = 'tenant';

    protected $fillable = [
        'user_id', 'original', 'requested', 'status',
        'reviewed_by_user_id', 'reviewed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'original' => 'array',
            'requested' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * The requesting platform user. Built manually, not a plain
     * belongsTo(): see the matching comment on Staff::user(), a bare
     * belongsTo() would inherit this model's tenant connection onto
     * User (a platform model), throwing "no such table: users".
     */
    public function applicant(): BelongsTo
    {
        $related = (new User())->setConnection(config('database.default'));

        return $this->newBelongsTo($related->newQuery(), $this, 'user_id', 'id', 'applicant');
    }

    public function reviewer(): BelongsTo
    {
        $related = (new User())->setConnection(config('database.default'));

        return $this->newBelongsTo($related->newQuery(), $this, 'reviewed_by_user_id', 'id', 'reviewer');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
