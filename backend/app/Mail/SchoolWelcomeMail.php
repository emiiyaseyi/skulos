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
 * Sent when a school is created directly by the platform owner
 * (2026-08-16). A trial-approved school already received the full
 * onboarding letter; a school created by hand received nothing at all,
 * despite having exactly the same need for demo access, sign in details
 * and the list of information to prepare.
 *
 * Renders the SAME letter rather than a second, near-identical copy, so
 * the two cannot drift apart. Only the opening line and subject differ,
 * because this school did not ask for a trial: someone set it up for
 * them.
 */
class SchoolWelcomeMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEditableTemplate;

    /**
     * @param  array{portal_url: string, email: string, password: string}  $credentials
     */
    public function __construct(
        public School $school,
        public array $credentials,
        public ?string $planName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->templateSubject('Welcome to SKULOS: Your School Access and Setup'));
    }

    public function content(): Content
    {
        $html = $this->templateBody(view('emails.trial-approved', [
            'schoolName' => $this->school->name,
            'contactName' => $this->school->name,
            'demoUrl' => config('skulos.demo.url'),
            'demoAccounts' => config('skulos.demo.accounts', []),
            'planName' => $this->planName,
            'intro' => 'Your school has been set up on SKULOS and is ready to use.',
        ])->render());

        // The standard letter carries the demo accounts inline; an
        // edited one is free prose, so append them separately or a new
        // school has nothing to sign in to and try.
        if ($this->template()) {
            $html .= view('emails.demo-accounts', [
                'demoUrl' => config('skulos.demo.url'),
                'demoAccounts' => config('skulos.demo.accounts', []),
            ])->render();
        }

        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F7F8FA;border-radius:10px;margin:8px 0 20px;"><tr><td style="padding:18px 20px;">'
            .'<p style="margin:0 0 10px;font-size:12px;font-weight:bold;letter-spacing:0.4px;text-transform:uppercase;color:#7C8698;">Your sign in details</p>'
            .'<p style="margin:0 0 4px;"><strong>Portal:</strong> <a href="'.e($this->credentials['portal_url']).'" style="color:#0B1420;">'.e($this->credentials['portal_url']).'</a></p>'
            .'<p style="margin:0 0 4px;"><strong>Email:</strong> '.e($this->credentials['email']).'</p>'
            .'<p style="margin:0;"><strong>Temporary password:</strong> '.e($this->credentials['password']).'</p>'
            .'</td></tr></table>'
            .'<p style="margin:0 0 16px;color:#7C8698;font-size:13px;">You will be asked to change this password the first time you sign in. '
            .'This web address was chosen for your school automatically; if you would prefer a different one, reply to this email and we will change it for you.</p>';

        $html .= '<a href="'.e($this->credentials['portal_url']).'" style="display:inline-block;background-color:#E8A33D;color:#241503;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;">'
            .'Go to your school portal</a>';

        $accountLines = collect(config('skulos.demo.accounts', []))
            ->map(fn ($a) => "{$a['role']}: {$a['email']} | Password: {$a['password']}")
            ->implode("\n");

        $text = ($this->template() ? $this->templateText('')."\n\n" : "Dear {$this->school->name},\n\n")
            ."Your school has been set up on SKULOS and is ready to use.\n\n"
            .($this->planName ? "Plan: {$this->planName}\n\n" : '')
            ."DEMO ACCESS\nDemo School: ".config('skulos.demo.url')."\n\nTest Accounts:\n{$accountLines}\n\n"
            ."YOUR SIGN IN DETAILS\nPortal: {$this->credentials['portal_url']}\nEmail: {$this->credentials['email']}\n"
            ."Temporary password: {$this->credentials['password']}\n"
            ."You will be asked to change this password the first time you sign in.\n\n"
            ."Kind regards,\nSKUL Operating System";

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
            'portal_url' => $this->credentials['portal_url'],
            'login_email' => $this->credentials['email'],
            'temporary_password' => $this->credentials['password'],
            'plan_name' => (string) $this->planName,
            'demo_url' => (string) config('skulos.demo.url'),
        ];
    }
}
