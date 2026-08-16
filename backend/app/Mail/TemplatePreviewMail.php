<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A test send of an edited template, to the admin editing it, with
 * placeholders filled by sample values (2026-08-16). Renders through
 * the same shared layout as every real mail, so what arrives looks
 * exactly like what a school would receive rather than a bare preview.
 */
class TemplatePreviewMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $renderedSubject,
        public string $renderedBody,
    ) {}

    public function envelope(): Envelope
    {
        // Marked as a test in the subject line so it can never be
        // mistaken for a real message sitting in an inbox.
        return new Envelope(subject: '[Test] '.$this->renderedSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: [
                'subject' => $this->renderedSubject,
                'body' => $this->renderedBody,
                'text' => strip_tags(str_replace(['<br />', '<br>'], "\n", $this->renderedBody)),
            ],
        );
    }
}
