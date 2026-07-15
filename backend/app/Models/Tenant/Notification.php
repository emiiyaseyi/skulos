<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * An in-app notification for one user. Powers the notification section on
 * every user's dashboard and the Notification Center (categories, archive,
 * pin, added 2026-07-12). Written by the system (not Auditable; these are
 * the record itself).
 */
class Notification extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'user_id', 'audience', 'title', 'body', 'category', 'type', 'announcement_id', 'read_at', 'pinned_at', 'archived_at',
    ];

    public function announcement(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'pinned_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    /** The broad type (academic/finance/urgent/system) a free-text category belongs to, per config('skulos.notification_categories'). Falls back to "system" for a category with no catalog entry, never a hard error. */
    public static function typeFor(string $category): string
    {
        return config("skulos.notification_categories.{$category}.type", 'system');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopePinned($query)
    {
        return $query->whereNotNull('pinned_at');
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    public function scopeOfType($query, ?string $type)
    {
        return $type ? $query->where('type', $type) : $query;
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

    public function togglePin(): void
    {
        $this->forceFill(['pinned_at' => $this->pinned_at ? null : now()])->save();
    }

    public function archive(): void
    {
        $this->forceFill(['archived_at' => now()])->save();
    }

    public function unarchive(): void
    {
        $this->forceFill(['archived_at' => null])->save();
    }

    /**
     * The dashboard feed for a user: recent notifications + unread count.
     * Archived notifications are excluded, pinned ones lead the list, then
     * most recent first, so a pinned item stays visible even once older
     * than the rest of the limited window.
     *
     * @return array{unread: int, items: \Illuminate\Support\Collection}
     */
    public static function feedFor(int $userId, int $limit = 20): array
    {
        return [
            'unread' => static::forUser($userId)->unread()->notArchived()->count(),
            'items' => static::forUser($userId)->notArchived()
                ->orderByRaw('pinned_at is null')
                ->latest()
                ->limit($limit)->get(),
        ];
    }
}
