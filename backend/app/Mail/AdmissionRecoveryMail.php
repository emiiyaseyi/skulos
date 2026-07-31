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
 * Sent once an admin approves a manual recovery request (user directive
 * 2026-07-31: "admin review and check if it aligns and then approve
 * which [sends] a mail with the reference number and password"). Same
 * layout/shape as AdmissionCredentialsMail, addressed to whatever email
 * the requester gave (not necessarily the applicant's own stored email —
 * the whole point of this fallback is the requester may not remember or
 * have access to it).
 */
class AdmissionRecoveryMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Applicant $applicant, public School $school)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your {$this->school->name} application details");
    }

    public function content(): Content
    {
        $portalUrl = $this->school->portalUrl('/admissions/login');
        $intro = "Hello, here are the details for the {$this->school->name} application you asked us to recover.";
        $credentialLine = $this->applicant->recoveryCredentialLine();

        $html = '<p style="margin:0 0 16px;">'.e($intro).'</p>'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F7F8FA;border-radius:10px;margin:8px 0 20px;"><tr><td style="padding:18px 20px;">'
            .'<p style="margin:0 0 10px;font-size:12px;font-weight:bold;letter-spacing:0.4px;text-transform:uppercase;color:#7C8698;">Your application details</p>'
            .'<p style="margin:0 0 4px;"><strong>Portal:</strong> <a href="'.e($portalUrl).'" style="color:#0B1420;">'.e($portalUrl).'</a></p>'
            .'<p style="margin:0 0 4px;"><strong>Reference number:</strong> '.e($this->applicant->admission_number).'</p>'
            .'<p style="margin:0;">'.e($credentialLine).'</p>'
            .'</td></tr></table>'
            .'<a href="'.e($portalUrl).'" style="display:inline-block;background-color:#E8A33D;color:#241503;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;">Continue your application</a>';

        $text = "{$intro}\n\nReference number: {$this->applicant->admission_number}\n{$credentialLine}\n\nPortal: {$portalUrl}";

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: ['subject' => $this->envelope()->subject, 'body' => $html, 'text' => $text],
        );
    }
}
