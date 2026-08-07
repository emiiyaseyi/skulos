<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reminder/extension/demand/reinstated action a platform admin took
 * on a school's subscription (user request 2026-07-17), a real audit
 * trail behind the countdown/reminder UI, not just the current
 * next_billing_date value.
 */
class SubscriptionEvent extends Model
{
    public const TYPE_REMINDER = 'reminder';

    public const TYPE_EXTENSION = 'extension';

    public const TYPE_DEMAND = 'demand';

    public const TYPE_REINSTATED = 'reinstated';

    /** Confirm & Renew (user request 2026-07-19): an admin confirmed the verified payments received and advanced the school's next_billing_date. */
    public const TYPE_RENEWED = 'renewed';

    /** A free-term waiver (2026-08-07, user directive: "fix this so that the platform owner/admin can give it to a school") — the same next_billing_date push extend() does, but with no payment required, tied to a real BillingWaiver record explaining why. */
    public const TYPE_WAIVER = 'waiver';

    protected $fillable = ['school_id', 'type', 'note', 'extended_to', 'actioned_by_user_id'];

    protected function casts(): array
    {
        return ['extended_to' => 'date'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by_user_id');
    }
}
