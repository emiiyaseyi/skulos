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

    protected $connection = 'tenant';

    protected $fillable = [
        'title', 'body', 'audience', 'class_arm_id', 'status',
        'published_at', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CommunicationLog::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
