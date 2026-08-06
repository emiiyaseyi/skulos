<?php

namespace App\Mail;

use App\Models\School;
use App\Models\Tenant\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Automated admissions pipeline (2026-08-06): sent the moment
 * ClassTeacherOnboardingService::onboard() flips a dormant admission to
 * a real active student — the final step of the whole offer -> accept ->
 * pay -> onboard flow, and the first time the family is told enrollment
 * is actually complete, not just paid for.
 */
class AdmissionOnboardedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Student $student, public School $school)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->student->fullName()} is now enrolled at {$this->school->name}");
    }

    public function content(): Content
    {
        $portalUrl = $this->school->portalUrl('/login');
        $className = $this->student->currentClassArm?->label() ?? 'their class';
        $intro = "{$this->student->fullName()}'s enrollment at {$this->school->name} is now complete. They have been added to {$className} (admission number {$this->student->admission_number}). Sign in to your parent portal to see their class, timetable, and more.";

        $html = '<p style="margin:0 0 16px;">'.e($intro).'</p>'
            .'<a href="'.e($portalUrl).'" style="display:inline-block;background-color:#E8A33D;color:#241503;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;">Go to parent portal</a>';

        $text = "{$intro}\n\n{$portalUrl}";

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: ['subject' => $this->envelope()->subject, 'body' => $html, 'text' => $text],
        );
    }
}
