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
 * Tells a user their own chosen forgot-password request has been
 * approved and is now live (user directive 2026-07-13: "the user enter
 * a new password they want which the admin will have to approve which
 * will show approved on the user end once the admin approve and then
 * the user can make use of it").
 */
class PasswordResetApprovedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public User $user, public School $school)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your {$this->school->name} password reset was approved");
    }

    public function content(): Content
    {
        $portalUrl = $this->school->portalUrl('/login');
        $intro = "Hi {$this->user->name}, {$this->school->name}'s admin approved your password reset request. You can now sign in with the new password you chose.";

        $html = '<p style="margin:0 0 16px;">'.e($intro).'</p>'
            .'<a href="'.e($portalUrl).'" style="display:inline-block;background-color:#E8A33D;color:#241503;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;">Sign in now</a>';

        $text = "{$intro}\n\n{$portalUrl}";

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: ['subject' => $this->envelope()->subject, 'body' => $html, 'text' => $text],
        );
    }
}
