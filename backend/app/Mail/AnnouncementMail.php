<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A school's announcement, delivered through the shared branded layout so
 * it reads well and does not look like a bare, spam-prone email.
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
        $html = '<p style="margin:0 0 8px;font-size:12px;font-weight:bold;letter-spacing:0.4px;text-transform:uppercase;color:#7C8698;">'.e($this->schoolName).'</p>'
            .'<p style="margin:0;white-space:pre-line;">'.e($this->bodyText).'</p>';

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: [
                'subject' => $this->subjectLine,
                'body' => $html,
                'text' => "{$this->schoolName}\n\n{$this->bodyText}",
            ],
        );
    }
}
