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
 * Automated admissions pipeline (user directive 2026-08-06): "when an
 * applicant gets to the enrolled stage, the applicant should get an
 * admission letter... the parents gets the notification and mail." Sent
 * the moment AdmissionPipelineController::offer() decides an applicant,
 * before any acceptance/Student row exists — Mail-only, no in-app
 * Notification is possible yet (no guardian User exists until the
 * offer is actually accepted, see AdmissionAcceptanceService).
 */
class AdmissionOfferMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Applicant $applicant, public School $school)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You have been offered admission to {$this->school->name}");
    }

    public function content(): Content
    {
        $portalUrl = $this->school->portalUrl('/admissions/login');
        $intro = "Congratulations {$this->applicant->name}, you have been offered admission to {$this->school->name}. Sign in to your application to view your admission letter, accept the offer, and pay school fees.";

        $html = '<p style="margin:0 0 16px;">'.e($intro).'</p>'
            .'<a href="'.e($portalUrl).'" style="display:inline-block;background-color:#E8A33D;color:#241503;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;">View your offer</a>';

        $text = "{$intro}\n\n{$portalUrl}";

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: ['subject' => $this->envelope()->subject, 'body' => $html, 'text' => $text],
        );
    }
}
