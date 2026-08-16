<?php

namespace App\Mail;

use App\Mail\Concerns\UsesEditableTemplate;
use App\Models\School;
use App\Models\User;
use App\Services\Accounts\WelcomeEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a staff member or parent their SKULOS login was just created (Staff
 * and Parent Portal, user request 2026-07-12), same layout/style as
 * PasswordResetByAdminMail so every "here are your sign in details" email
 * looks the same regardless of who triggered it. Also doubles as the
 * "welcome email" for auto-provisioned accounts with a real email address
 * (a guardian, user request 2026-07-26: "new user accounts get a welcome
 * email with login details, portal link, and a role-specific brief"),
 * $roleSlug drives that brief via WelcomeEmailService, $roleLabel stays
 * the human-readable name shown in the intro sentence.
 */
class AccountCreatedMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesEditableTemplate;

    public function __construct(public User $user, public School $school, public string $password, public string $roleLabel, public string $roleSlug)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->templateSubject("Your {$this->school->name} account is ready"));
    }

    public function content(): Content
    {
        $portalUrl = $this->school->portalUrl('/login');
        $brief = app(WelcomeEmailService::class)->briefFor($this->roleSlug);

        $intro = "Hello {$this->user->name}, an account has been created for you at {$this->school->name} on SKULOS as {$this->roleLabel}.";
        $advice = 'Sign in and change your password when convenient. If you were not expecting this, contact the school right away.';

        $html = $this->templateBody('<p style="margin:0 0 16px;">'.e($intro).'</p>'
            .'<p style="margin:0 0 16px;color:#1A2333;">'.e($brief).'</p>')
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F7F8FA;border-radius:10px;margin:8px 0 20px;"><tr><td style="padding:18px 20px;">'
            .'<p style="margin:0 0 10px;font-size:12px;font-weight:bold;letter-spacing:0.4px;text-transform:uppercase;color:#7C8698;">Your sign in details</p>'
            .'<p style="margin:0 0 4px;"><strong>Portal:</strong> <a href="'.e($portalUrl).'" style="color:#0B1420;">'.e($portalUrl).'</a></p>'
            .'<p style="margin:0 0 4px;"><strong>Email:</strong> '.e($this->user->email).'</p>'
            .'<p style="margin:0;"><strong>Password:</strong> '.e($this->password).'</p>'
            .'</td></tr></table>'
            .'<p style="margin:0 0 16px;color:#7C8698;font-size:13px;">'.e($advice).'</p>'
            .'<a href="'.e($portalUrl).'" style="display:inline-block;background-color:#E8A33D;color:#241503;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;">Sign in now</a>';

        $text = $this->templateText("{$intro}\n\n{$brief}")."\n\nYour sign in details\nPortal: {$portalUrl}\nEmail: {$this->user->email}\nPassword: {$this->password}\n\n{$advice}";

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: ['subject' => $this->envelope()->subject, 'body' => $html, 'text' => $text],
        );
    }

    protected function templateValues(): array
    {
        return [
            'name' => $this->user->name,
            'school_name' => $this->school->name,
            'login_email' => (string) $this->user->email,
            'temporary_password' => $this->password,
            'portal_url' => $this->school->portalUrl('/login'),
        ];
    }
}
