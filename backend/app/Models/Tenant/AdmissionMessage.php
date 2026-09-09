<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in the thread between school staff and an applicant. See
 * the creating migration for why this exists separately from the
 * one-way AdmissionReminderMail nudge.
 */
class AdmissionMessage extends Model
{
    use Auditable;

    public const SENDER_STAFF = 'staff';

    public const SENDER_APPLICANT = 'applicant';

    protected $connection = 'tenant';

    protected $fillable = ['applicant_id', 'sender', 'sender_user_id', 'body'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }
}
