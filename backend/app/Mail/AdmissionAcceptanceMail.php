<?php

namespace App\Mail;

use App\Models\School;
use App\Models\Tenant\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Automated admissions pipeline (2026-08-06): sent the moment
 * AdmissionAcceptanceService::accept() creates the dormant Student and
 * provisions the guardian's login, regardless of whether that login is
 * brand new or reused from another child (AccountCreatedMail only ever
 * fires for a genuinely new login, so a guardian with an existing
 * account would otherwise hear nothing about this specific child).
 */
class AdmissionAcceptanceMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Student $student, public School $school)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Offer accepted — next step: school fees for {$this->student->fullName()}");
    }

    public function content(): Content
    {
        $portalUrl = $this->school->portalUrl('/login');
        $intro = "Thank you for accepting the offer of admission for {$this->student->fullName()} at {$this->school->name} (admission number {$this->student->admission_number}). To complete enrollment, please sign in to your parent portal and pay the applicable school fees.";

        $html = '<p style="margin:0 0 16px;">'.e($intro).'</p>'
            .'<a href="'.e($portalUrl).'" style="display:inline-block;background-color:#E8A33D;color:#241503;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;">Sign in to pay fees</a>';

        $text = "{$intro}\n\n{$portalUrl}";

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: ['subject' => $this->envelope()->subject, 'body' => $html, 'text' => $text],
        );
    }
}
