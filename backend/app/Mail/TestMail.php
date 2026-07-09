<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by the "Send test email" button on the SMTP settings screens
 * (platform and per-school) so an admin can confirm their configuration
 * actually delivers before relying on it.
 */
class TestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'SKULOS test email');
    }

    public function content(): Content
    {
        $body = '<p style="margin:0 0 16px;">This is a test email from your SKULOS mail settings.</p>'
            .'<p style="margin:0;color:#7C8698;font-size:13px;">If you received this, your outgoing mail settings are working correctly.</p>';

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: [
                'subject' => 'SKULOS test email',
                'body' => $body,
                'text' => "This is a test email from your SKULOS mail settings.\n\nIf you received this, your outgoing mail settings are working correctly.",
            ],
        );
    }
}
