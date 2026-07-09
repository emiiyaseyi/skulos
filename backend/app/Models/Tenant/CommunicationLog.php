<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message send on one channel to one recipient, with its delivery
 * status. Backs the Notification Center. Not Auditable: these rows ARE the
 * communication record, and are written by the system, not edited by users.
 */
class CommunicationLog extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $connection = 'tenant';

    protected $fillable = [
        'channel', 'recipient', 'subject', 'status', 'error',
        'announcement_id', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
