<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Plain, dependable announcement email. Body is passed as text so it works
 * without a mail theme and reads the same everywhere.
 */
class AnnouncementMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public string $schoolName,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            htmlString: nl2br(e($this->bodyText))
                ."<hr><p style=\"color:#64748B;font-size:12px\">Sent through SKULOS on behalf of {$this->schoolName}.</p>",
        );
    }
}
