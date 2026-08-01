<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chat while a taker waits on a face-approval decision — they
 * have no session yet, so this cannot hang off CbtChatMessage.
 */
class CbtFaceApprovalMessage extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['face_approval_id', 'sender_type', 'sender_user_id', 'body'];

    public function approval(): BelongsTo
    {
        return $this->belongsTo(CbtFaceApproval::class, 'face_approval_id');
    }
}
