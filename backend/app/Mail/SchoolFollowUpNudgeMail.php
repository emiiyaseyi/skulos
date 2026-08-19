<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEditableTemplate;
use App\Models\School;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A manual follow-up nudge (2026-08-19, direct instruction: "under the
 * trial and new schools created, let's add a nudge button there which
 * send them a reminder about our previous mail and if they have any
 * issue they should respond... ahead of the new term"). Deliberately a
 * SEPARATE Mailable from the welcome/trial-approval letter it refers
 * back to, rather than resending that one again: this is a short,
 * personal-feeling check-in, not a second copy of a long setup letter
 * someone may have already read once and set aside.
 *
 * Sent from School\admin\SchoolAdminController::nudge() and
 * Admin\TrialRequestAdminController::nudge(), both of which resolve the
 * real active admin recipient(s) via SchoolFollowUpService rather than
 * duplicating that lookup.
 */
class SchoolFollowUpNudgeMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEditableTemplate;

    public function __construct(public School $school, public string $contactName)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->templateSubject("Checking in on {$this->school->name}'s SKULOS setup"));
    }

    public function content(): Content
    {
        $portalUrl = $this->school->portalUrl('/login');

        $default = "Hello {$this->contactName},\n\n"
            ."We wanted to check in on {$this->school->name}'s SKULOS setup. We sent you an email earlier with your sign in details and the information we would need to get everything ready.\n\n"
            ."If you have run into any issue at all, or have any questions, just reply to this email and we will help you sort it out. We would love to get {$this->school->name} fully set up and ready to go ahead of the new term.";

        $html = $this->templateBody(
            '<p style="margin:0 0 16px;">'.nl2br(e($default)).'</p>'
        ).'<a href="'.e($portalUrl).'" style="display:inline-block;background-color:#E8A33D;color:#241503;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;">Go to your school portal</a>';

        $text = $this->templateText($default)."\n\n{$portalUrl}";

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: ['subject' => $this->envelope()->subject, 'body' => $html, 'text' => $text],
        );
    }

    protected function templateValues(): array
    {
        return [
            'school_name' => $this->school->name,
            'contact_name' => $this->contactName,
            'portal_url' => $this->school->portalUrl('/login'),
        ];
    }
}
