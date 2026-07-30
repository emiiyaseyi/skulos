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
 * Tells an applicant their application fee cleared and their sign in
 * details are ready (user directive 2026-07-30: "a generated password
 * they will see upon confirmation of admission application fee"), same
 * layout as every other "here are your sign in details" email
 * (AccountCreatedMail) but pointed at the applicant login, not the
 * platform's normal one, and framed around the application window
 * rather than a permanent account.
 */
class AdmissionCredentialsMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Applicant $applicant, public School $school, public string $password)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your {$this->school->name} application is ready to continue");
    }

    public function content(): Content
    {
        $portalUrl = $this->school->portalUrl('/admissions/login');
        $identifier = $this->applicant->admission_number ?: $this->applicant->phone;
        $windowNote = $this->applicant->expires_at
            ? "You have until {$this->applicant->expires_at->toFormattedDateString()} to finish your application."
            : '';

        $intro = "Hello {$this->applicant->name}, your application fee for {$this->school->name} has been confirmed. You can now sign in to finish your application.";

        $html = '<p style="margin:0 0 16px;">'.e($intro).'</p>'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F7F8FA;border-radius:10px;margin:8px 0 20px;"><tr><td style="padding:18px 20px;">'
            .'<p style="margin:0 0 10px;font-size:12px;font-weight:bold;letter-spacing:0.4px;text-transform:uppercase;color:#7C8698;">Your sign in details</p>'
            .'<p style="margin:0 0 4px;"><strong>Portal:</strong> <a href="'.e($portalUrl).'" style="color:#0B1420;">'.e($portalUrl).'</a></p>'
            .'<p style="margin:0 0 4px;"><strong>Admission number or phone:</strong> '.e($identifier).'</p>'
            .'<p style="margin:0;"><strong>Password:</strong> '.e($this->password).'</p>'
            .'</td></tr></table>'
            .'<p style="margin:0 0 16px;color:#7C8698;font-size:13px;">'.e($windowNote).'</p>'
            .'<a href="'.e($portalUrl).'" style="display:inline-block;background-color:#E8A33D;color:#241503;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;">Continue your application</a>';

        $text = "{$intro}\n\nYour sign in details\nPortal: {$portalUrl}\nAdmission number or phone: {$identifier}\nPassword: {$this->password}\n\n{$windowNote}";

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: ['subject' => $this->envelope()->subject, 'body' => $html, 'text' => $text],
        );
    }
}
