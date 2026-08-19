<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per email this platform sends. Written by
 * App\Listeners\RecordSentMail from Laravel's own mail events, never by
 * hand, so it stays a faithful record rather than something a feature
 * has to remember to write.
 *
 * Deliberately does NOT store the message body: these rows are a
 * delivery record, not an archive, and bodies routinely contain
 * credentials (a new school owner's temporary password, an applicant's
 * reference) that have no business sitting in a second table forever.
 */
class MailLog extends Model
{
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public const AUDIENCE_PARENT = 'parent';

    public const AUDIENCE_STUDENT = 'student';

    public const AUDIENCE_STAFF = 'staff';

    public const AUDIENCE_APPLICANT = 'applicant';

    /** @var array<string, string> audience value => display label, in report order */
    public const AUDIENCES = [
        self::AUDIENCE_PARENT => 'Parents',
        self::AUDIENCE_STUDENT => 'Students',
        self::AUDIENCE_STAFF => 'Staff',
        self::AUDIENCE_APPLICANT => 'Applicants',
    ];

    protected $fillable = [
        'recipient', 'subject', 'mailable', 'status', 'error', 'school_id', 'audience', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** A row left as "sending" never reached the sent event — treat it as failed for display. */
    public function effectiveStatus(): string
    {
        if ($this->status === self::STATUS_SENDING && $this->created_at?->lt(now()->subMinutes(10))) {
            return self::STATUS_FAILED;
        }

        return $this->status;
    }

    public function shortMailable(): string
    {
        return $this->mailable ? class_basename($this->mailable) : 'Unknown';
    }
}
