<?php

namespace App\Mail;

use App\Models\School;
use App\Models\Tenant\Applicant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to an applicant the moment school staff sends them a message on
 * the admissions pipeline (user directive 2026-09-09). Not built with
 * UsesEditableTemplate like the other admissions mails: those wrap a
 * fixed, admin-authored letter, where this one's whole body IS the
 * message a staff member just typed, fresh every time, so there is no
 * reusable template text to make editable — carrying the message
 * verbatim is the correct behaviour, not a gap.
 */
class AdmissionMessageMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Applicant $applicant, public School $school, public string $messageBody)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "A message from {$this->school->name}");
    }

    public function content(): Content
    {
        $intro = "Hello {$this->applicant->name}, {$this->school->name} sent you a message about your application (reference {$this->applicant->admission_number}):";
        $url = $this->school->portalUrl('/admissions/login');

        $html = '<p style="margin:0 0 16px;">'.e($intro).'</p>'
            .'<p style="margin:0 0 16px;padding:12px 16px;background:#F6F3EC;border-radius:8px;white-space:pre-wrap;">'.e($this->messageBody).'</p>'
            .'<a href="'.e($url).'" style="display:inline-block;background-color:#E8A33D;color:#241503;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;">Sign in to reply</a>';

        $text = "{$intro}\n\n{$this->messageBody}\n\n{$url}";

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: ['subject' => $this->envelope()->subject, 'body' => $html, 'text' => $text],
        );
    }
}
