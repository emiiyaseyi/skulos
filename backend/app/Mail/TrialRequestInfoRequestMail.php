<?php

namespace App\Mail;

use App\Models\TrialRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Platform admin asking a trial requester for more detail before a
 * decision is made (2026-08-19, direct instruction: "admin should be
 * able to send a mail to each school requesting for more details about
 * their trial request").
 *
 * Deliberately free-form rather than an editable template: what is
 * missing is different for every request (a school's real address, how
 * many students, which term they want to start), so the admin writes
 * the actual question each time rather than filling placeholders into
 * fixed wording.
 */
class TrialRequestInfoRequestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public TrialRequest $trial, public string $message)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "A quick question about your SKULOS trial request for {$this->trial->school_name}",
            replyTo: [config('skulos.support.email')],
        );
    }

    public function content(): Content
    {
        $intro = "Hello {$this->trial->contact_name}, thank you again for your interest in SKULOS for {$this->trial->school_name}.";
        $advice = 'Just reply to this email with the details above and we will pick your request back up right away.';

        $html = '<p style="margin:0 0 16px;">'.e($intro).'</p>'
            .'<p style="margin:0 0 16px;">'.nl2br(e($this->message)).'</p>'
            .'<p style="margin:0 0 16px;color:#7C8698;font-size:13px;">'.e($advice).'</p>';

        $text = "{$intro}\n\n{$this->message}\n\n{$advice}";

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: ['subject' => $this->envelope()->subject, 'body' => $html, 'text' => $text],
        );
    }
}
