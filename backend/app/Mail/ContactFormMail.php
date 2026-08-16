<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEditableTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the platform support address when a visitor submits the
 * marketing site's Contact page (2026-08-14). Replaces the old
 * `action="mailto:..."` form, which Chrome flags as "not secure" (any
 * non-HTTPS form target gets that warning) and which depended on the
 * visitor having an email client configured at all.
 */
class ContactFormMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEditableTemplate;

    public function __construct(
        protected string $senderName,
        protected string $senderSchool,
        protected string $senderEmail,
        protected ?string $senderMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->templateSubject("New contact form message from {$this->senderName}"),
            replyTo: [$this->senderEmail],
        );
    }

    public function content(): Content
    {
        $messageHtml = $this->senderMessage
            ? nl2br(e($this->senderMessage))
            : '<em>No message provided.</em>';

        $body = $this->templateBody('<p style="margin:0 0 12px;"><strong>Name:</strong> '.e($this->senderName).'</p>'
            .'<p style="margin:0 0 12px;"><strong>School:</strong> '.e($this->senderSchool).'</p>'
            .'<p style="margin:0 0 12px;"><strong>Email:</strong> '.e($this->senderEmail).'</p>'
            .'<p style="margin:0 0 4px;"><strong>Message:</strong></p>'
            .'<p style="margin:0;">'.$messageHtml.'</p>');

        $text = $this->templateText("Name: {$this->senderName}\nSchool: {$this->senderSchool}\nEmail: {$this->senderEmail}\n\nMessage:\n"
            .($this->senderMessage ?: 'No message provided.'));

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: [
                'subject' => $this->envelope()->subject,
                'body' => $body,
                'text' => $text,
            ],
        );
    }

    protected function templateValues(): array
    {
        return [
            'sender_name' => $this->senderName,
            'sender_school' => $this->senderSchool,
            'sender_email' => $this->senderEmail,
            'sender_message' => $this->senderMessage ?: 'No message provided.',
        ];
    }
}
