<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in the in-exam conversation between a taker and
 * whoever is invigilating them.
 */
class CbtChatMessage extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['session_id', 'sender_type', 'sender_user_id', 'body'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public const SENDER_TAKER = 'taker';

    public const SENDER_PROCTOR = 'proctor';

    public function session(): BelongsTo
    {
        return $this->belongsTo(CbtSession::class, 'session_id');
    }
}
