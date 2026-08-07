<?php

namespace App\Mail;

use App\Models\TrialRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a trial applicant their request was approved or rejected, with
 * the admin's note (setup status, demo/training arrangements, or the
 * reason for rejection). On approval, $credentials carries the new
 * school's sign in details so the applicant can get in right away.
 */
class TrialRequestStatusMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param array{portal_url: string, email: string, password: string}|null $credentials
     */
    public function __construct(public TrialRequest $trial, public ?array $credentials = null)
    {
    }

    public function envelope(): Envelope
    {
        $subject = $this->trial->status === TrialRequest::STATUS_APPROVED
            ? "Your SKULOS trial for {$this->trial->school_name} is ready"
            : "About your SKULOS trial request for {$this->trial->school_name}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $approved = $this->trial->status === TrialRequest::STATUS_APPROVED;

        $intro = $approved
            ? "Good news, {$this->trial->contact_name}. Your trial for {$this->trial->school_name} has been approved and is ready to use."
            : "Hello {$this->trial->contact_name}, thank you for your interest in SKULOS for {$this->trial->school_name}.";

        $note = $this->trial->review_note ?: (
            $approved
                ? 'Our team will be in touch shortly with a demo and training for your staff.'
                : 'We were not able to move forward with this request at this time.'
        );

        $html = '<p style="margin:0 0 16px;">'.e($intro).'</p>'
            .'<p style="margin:0 0 16px;">'.nl2br(e($note)).'</p>';
        $text = "{$intro}\n\n{$note}";

        if ($approved && $this->credentials) {
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F7F8FA;border-radius:10px;margin:8px 0 20px;"><tr><td style="padding:18px 20px;">'
                .'<p style="margin:0 0 10px;font-size:12px;font-weight:bold;letter-spacing:0.4px;text-transform:uppercase;color:#7C8698;">Your sign in details</p>'
                .'<p style="margin:0 0 4px;"><strong>Portal:</strong> <a href="'.e($this->credentials['portal_url']).'" style="color:#0B1420;">'.e($this->credentials['portal_url']).'</a></p>'
                .'<p style="margin:0 0 4px;"><strong>Email:</strong> '.e($this->credentials['email']).'</p>'
                .'<p style="margin:0;"><strong>Temporary password:</strong> '.e($this->credentials['password']).'</p>'
                .'</td></tr></table>'
                .'<p style="margin:0 0 16px;color:#7C8698;font-size:13px;">Please sign in and change this password as soon as you can.</p>';

            $text .= "\n\nYour sign in details\nPortal: {$this->credentials['portal_url']}\nEmail: {$this->credentials['email']}\nTemporary password: {$this->credentials['password']}\n\nPlease sign in and change this password as soon as you can.";
        }

        // On approval this must be the school's OWN portal URl (the one
        // shown in the sign in details box just above), not the platform
        // marketing root — found live 2026-08-07 while verifying the
        // trial-approval flow end to end: the button silently sent a
        // brand new customer to the generic homepage instead of straight
        // to their own school's login page.
        $ctaUrl = $approved && $this->credentials ? $this->credentials['portal_url'] : config('app.url');
        $html .= '<a href="'.e($ctaUrl).'" style="display:inline-block;background-color:#E8A33D;color:#241503;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;">'
            .($approved ? 'Go to your school portal' : 'Visit SKULOS').'</a>';

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: ['subject' => $this->envelope()->subject, 'body' => $html, 'text' => $text],
        );
    }
}
