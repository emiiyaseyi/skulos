<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform-owner broadcast (messaging increment 3, 2026-07-15). Lives in
 * the platform database since a super admin authored it, not any one
 * school. Delivery itself is fanned out into every matched school's own
 * tenant database, see App\Services\Communication\PlatformBroadcastService.
 */
class PlatformBroadcast extends Model
{
    protected $fillable = [
        'title', 'body', 'image_path', 'recipient_group', 'school_scope',
        'plan_id', 'created_by_user_id', 'schools_reached', 'recipients_reached',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->image_path) : null;
    }
}
