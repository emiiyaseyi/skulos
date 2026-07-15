<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A platform-wide message from the SKULOS team to a school's leadership
 * (Dashboard Design Cross-Check, 2026-07-13: "Send Broadcast, Blocked, no
 * platform-wide broadcast tool exists"). Delivered through the shared
 * branded layout, same as every other transactional email, addressed to
 * the school by name so it reads as a real message, not a mail merge.
 *
 * $bodyText is rich text (messaging increment 3, 2026-07-15) already
 * cleaned through App\Support\HtmlSanitizer by the caller before this
 * mailable is constructed, so it renders here unescaped, same trust
 * boundary as the in-app broadcast pop-up.
 */
class PlatformBroadcastMail extends Mailable
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
        $html = '<p style="margin:0 0 8px;font-size:12px;font-weight:bold;letter-spacing:0.4px;text-transform:uppercase;color:#7C8698;">SKULOS &middot; '.e($this->schoolName).'</p>'
            .'<div style="margin:0;">'.$this->bodyText.'</div>';

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: [
                'subject' => $this->subjectLine,
                'body' => $html,
                'text' => "SKULOS, {$this->schoolName}\n\n".strip_tags($this->bodyText),
            ],
        );
    }
}
