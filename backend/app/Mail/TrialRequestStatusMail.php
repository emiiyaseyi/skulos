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
 * reason for rejection). Plain text so it reads the same everywhere.
 */
class TrialRequestStatusMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public TrialRequest $trial)
    {
    }

    public function envelope(): Envelope
    {
        $subject = $this->trial->status === TrialRequest::STATUS_APPROVED
            ? "Your SKULOS trial for {$this->trial->school_name} is approved"
            : "About your SKULOS trial request for {$this->trial->school_name}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $intro = $this->trial->status === TrialRequest::STATUS_APPROVED
            ? "Good news, {$this->trial->contact_name}. Your trial request for {$this->trial->school_name} has been approved."
            : "Hello {$this->trial->contact_name}, thank you for your interest in SKULOS for {$this->trial->school_name}.";

        $note = $this->trial->review_note ?: (
            $this->trial->status === TrialRequest::STATUS_APPROVED
                ? 'Our team will be in touch shortly with your school setup, a demo, and training for your staff.'
                : 'We were not able to move forward with this request at this time.'
        );

        $body = "{$intro}\n\n{$note}\n\nQuestions any time: ".config('skulos.support.email').' or '.config('skulos.support.phone').'.';

        return new Content(htmlString: nl2br(e($body)));
    }
}
