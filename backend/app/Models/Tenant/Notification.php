<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * An in-app notification for one user. Powers the notification section on
 * every user's dashboard. Written by the system (not Auditable; these are
 * the record itself).
 */
class Notification extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'user_id', 'audience', 'title', 'body', 'category', 'announcement_id', 'read_at',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

    /**
     * The dashboard feed for a user: recent notifications + unread count.
     *
     * @return array{unread: int, items: \Illuminate\Support\Collection}
     */
    public static function feedFor(int $userId, int $limit = 20): array
    {
        return [
            'unread' => static::forUser($userId)->unread()->count(),
            'items' => static::forUser($userId)->latest()->limit($limit)->get(),
        ];
    }
}
