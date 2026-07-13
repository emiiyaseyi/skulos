<?php

namespace App\Mail;

use App\Models\School;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The "email me a reset link" option (user directive 2026-07-13: "the
 * user make use of password reset link and get it as a mail"), the
 * second of two forgot-password paths alongside the admin-approved one
 * (see PasswordResetApprovedMail/ProfileController::requestPasswordReset()).
 * Proving access to the inbox is itself the identity check, so this
 * path applies immediately once the link is used, no admin approval.
 */
class PasswordResetLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public User $user, public School $school, public string $resetUrl)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Reset your {$this->school->name} password");
    }

    public function content(): Content
    {
        $intro = "Hi {$this->user->name}, you asked to reset your password at {$this->school->name} on SKULOS.";
        $advice = 'If this was not you, you can safely ignore this email, your password will not change. This link expires in 60 minutes.';

        $html = '<p style="margin:0 0 16px;">'.e($intro).'</p>'
            .'<a href="'.e($this->resetUrl).'" style="display:inline-block;background-color:#E8A33D;color:#241503;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;">Reset my password</a>'
            .'<p style="margin:16px 0 0;color:#7C8698;font-size:13px;">'.e($advice).'</p>';

        $text = "{$intro}\n\n{$this->resetUrl}\n\n{$advice}";

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: ['subject' => $this->envelope()->subject, 'body' => $html, 'text' => $text],
        );
    }
}
