<?php

namespace App\Mail;

use App\Models\School;
use App\Models\Tenant\Applicant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Admissions module, Phase 1g: a status-appropriate nudge an admin
 * triggers from the pipeline board (user directive 2026-07-31: "trigger
 * reminder to those who are yet to complete... they can either continue,
 * make payment, or move to the next stage"). An applicant who has not
 * paid the application fee yet has no password to sign in with, so this
 * links to a fresh signed status link (same mechanism as the original
 * confirmation page) rather than the login page in that case; anyone
 * with real credentials already gets the normal applicant login link.
 */
class AdmissionReminderMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Applicant $applicant, public School $school, public string $actionMessage)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "A reminder about your {$this->school->name} application");
    }

    public function content(): Content
    {
        $url = $this->applicant->account_status === Applicant::STATUS_PENDING_FEE
            ? URL::temporarySignedRoute('school.admissions.apply.status', now()->addDays(30), ['school' => $this->school->slug, 'applicant' => $this->applicant->id])
            : $this->school->portalUrl('/admissions/login');

        $intro = "Hello {$this->applicant->name}, this is a reminder to {$this->actionMessage} for your application to {$this->school->name} (reference {$this->applicant->admission_number}).";

        $html = '<p style="margin:0 0 16px;">'.e($intro).'</p>'
            .'<a href="'.e($url).'" style="display:inline-block;background-color:#E8A33D;color:#241503;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;">Continue your application</a>';

        $text = "{$intro}\n\n{$url}";

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: ['subject' => $this->envelope()->subject, 'body' => $html, 'text' => $text],
        );
    }
}
