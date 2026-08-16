<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEditableTemplate;
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
    use UsesEditableTemplate;

    /**
     * @param array{portal_url: string, email: string, password: string}|null $credentials
     */
    public function __construct(public TrialRequest $trial, public ?array $credentials = null)
    {
    }

    public function envelope(): Envelope
    {
        $subject = $this->trial->status === TrialRequest::STATUS_APPROVED
            ? 'SKULOS Trial Access and Setup Requirements'
            : "About your SKULOS trial request for {$this->trial->school_name}";

        // Only the approval letter is editable; a rejection keeps its
        // own wording, since it is driven by the reviewer's note.
        return new Envelope(subject: $this->trial->status === TrialRequest::STATUS_APPROVED
            ? $this->templateSubject($subject)
            : $subject);
    }

    public function content(): Content
    {
        $approved = $this->trial->status === TrialRequest::STATUS_APPROVED;

        if ($approved) {
            // The full standard onboarding letter (2026-08-16), kept in
            // its own view because it is long and genuinely structured.
            // A reviewer's own note, when they leave one, renders ABOVE
            // it so anything specific to this school reads first without
            // displacing any of the standard content.
            $html = $this->trial->review_note
                ? '<p style="margin:0 0 18px;">'.nl2br(e($this->trial->review_note)).'</p>'
                : '';
            // An edited template replaces the letter's prose. The demo
            // account table still renders from config below it either
            // way, so editing the wording can never drop the accounts a
            // school needs to actually try the platform.
            $html .= $this->templateBody(view('emails.trial-approved', [
                'schoolName' => $this->trial->school_name,
                'contactName' => $this->trial->contact_name,
                'demoUrl' => config('skulos.demo.url'),
                'demoAccounts' => config('skulos.demo.accounts', []),
            ])->render());

            if ($this->template()) {
                $html .= view('emails.demo-accounts', [
                    'demoUrl' => config('skulos.demo.url'),
                    'demoAccounts' => config('skulos.demo.accounts', []),
                ])->render();
            }

            // A real plain text alternative, not a stripped copy: every
            // mail here sends both parts, and a message with no usable
            // text half scores worse with spam filters.
            $accountLines = collect(config('skulos.demo.accounts', []))
                ->map(fn ($a) => "{$a['role']}: {$a['email']} | Password: {$a['password']}")
                ->implode("\n");

            $text = ($this->trial->review_note ? $this->trial->review_note."\n\n" : '')
                ."Dear {$this->trial->school_name},\n\n"
                ."Thank you for your interest in SKULOS.\n\n"
                ."We are pleased to approve your school for the SKULOS trial. The trial will give your team the opportunity to explore the platform, test the different workflows, and provide feedback that will help us tailor the solution to your school's needs.\n\n"
                ."DEMO ACCESS\n"
                .'Demo School: '.config('skulos.demo.url')."\n\n"
                ."Test Accounts:\n{$accountLines}\n\n"
                ."INFORMATION TO PREPARE FOR YOUR SCHOOL SETUP\n"
                ."School information (name, logo, motto, address, email, phone, website), academic structure (sections, classes and arms, subjects, calendar, houses and clubs), student information, staff information, assessment and results structure, parent and communication preferences, and any admission, fee or timetable details you would like configured.\n\n"
                ."You do not need to prepare everything before the trial begins. We can work through the requirements together and prioritise what is needed for the initial setup.\n\n"
                ."We can also schedule a session to present SKULOS to your team, walk through the major features, answer your questions, and discuss how the platform can be configured to suit your school's operations.\n\n"
                ."SCHEDULING YOUR DEMO\n"
                ."Please reply with a preferred date and time (and an alternative if possible), the full address of the school if the session is to hold on site, roughly how many people will attend and their roles, and whether you would prefer to meet in person or online.\n\n"
                ."We look forward to working with you and receiving your feedback during the trial.\n\n"
                ."Kind regards,\nSKUL Operating System";

            if ($this->template()) {
                $text = ($this->trial->review_note ? $this->trial->review_note."\n\n" : '')
                    .$this->templateText('')
                    ."\n\nDEMO ACCESS\nDemo School: ".config('skulos.demo.url')
                    ."\n\nTest Accounts:\n{$accountLines}";
            }
        } else {
            $intro = "Hello {$this->trial->contact_name}, thank you for your interest in SKULOS for {$this->trial->school_name}.";
            $note = $this->trial->review_note ?: 'We were not able to move forward with this request at this time.';

            $html = '<p style="margin:0 0 16px;">'.e($intro).'</p>'
                .'<p style="margin:0 0 16px;">'.nl2br(e($note)).'</p>';
            $text = "{$intro}\n\n{$note}";
        }

        if ($approved && $this->credentials) {
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F7F8FA;border-radius:10px;margin:8px 0 20px;"><tr><td style="padding:18px 20px;">'
                .'<p style="margin:0 0 10px;font-size:12px;font-weight:bold;letter-spacing:0.4px;text-transform:uppercase;color:#7C8698;">Your sign in details</p>'
                .'<p style="margin:0 0 4px;"><strong>Portal:</strong> <a href="'.e($this->credentials['portal_url']).'" style="color:#0B1420;">'.e($this->credentials['portal_url']).'</a></p>'
                .'<p style="margin:0 0 4px;"><strong>Email:</strong> '.e($this->credentials['email']).'</p>'
                .'<p style="margin:0;"><strong>Temporary password:</strong> '.e($this->credentials['password']).'</p>'
                .'</td></tr></table>'
                .'<p style="margin:0 0 16px;color:#7C8698;font-size:13px;">Please sign in and change this password as soon as you can. This web address was chosen for your school automatically; if you would prefer a different one, reply to this email and we will change it for you.</p>';

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

    protected function templateValues(): array
    {
        return [
            'school_name' => $this->trial->school_name,
            'contact_name' => $this->trial->contact_name,
            'portal_url' => $this->credentials['portal_url'] ?? '',
            'login_email' => $this->credentials['email'] ?? '',
            'temporary_password' => $this->credentials['password'] ?? '',
            'demo_url' => (string) config('skulos.demo.url'),
        ];
    }
}
