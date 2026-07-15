<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school announcement targeted at an audience. Publishing it can fan out
 * notifications on the enabled channels (email now).
 */
class Announcement extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public const TYPE_ANNOUNCEMENT = 'announcement';
    public const TYPE_BROADCAST = 'broadcast';

    protected $connection = 'tenant';

    protected $fillable = [
        'title', 'body', 'type', 'image_path', 'audience', 'class_arm_id', 'section_id', 'status',
        'published_at', 'created_by_user_id', 'platform_broadcast_id',
    ];

    public function imageUrl(): ?string
    {
        return $this->image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->image_path) : null;
    }

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CommunicationLog::class);
    }

    /** The per-recipient in-app notifications this announcement/broadcast produced, each carrying its own read_at (used for broadcast read receipts). */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function section(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function classArm(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ClassArm::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function scopeBroadcasts($query)
    {
        return $query->where('type', self::TYPE_BROADCAST);
    }

    public function scopeAnnouncements($query)
    {
        return $query->where('type', self::TYPE_ANNOUNCEMENT);
    }
}
