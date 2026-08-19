<?php

namespace App\Listeners;

use App\Models\MailLog;
use App\Models\School;
use App\Models\Tenant\Applicant;
use App\Models\User;
use App\Services\Tenancy\TenantManager;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;

/**
 * Records every outgoing email by listening to Laravel's own mail
 * events (2026-08-16), rather than asking each Mailable to log itself.
 * That matters: an email added six months from now is captured with no
 * extra work, and nothing can quietly go unlogged because someone
 * forgot a line.
 *
 * Two events, on purpose. MessageSending writes the row before the send
 * is attempted, so a message that dies mid-transport still leaves a
 * trace instead of vanishing; MessageSent then marks it delivered.
 * Laravel emits no "failed" event, so a row still sitting at "sending"
 * well after the fact is what a failure looks like — MailLog reports
 * that as failed rather than pretending it is still in flight.
 */
class RecordSentMail
{
    public function sending(MessageSending $event): void
    {
        try {
            $log = MailLog::create([
                'recipient' => $this->recipients($event->message->getTo()),
                'subject' => $event->message->getSubject(),
                'mailable' => $event->data['__laravel_mailable'] ?? null,
                'status' => MailLog::STATUS_SENDING,
                'school_id' => $this->deriveSchoolId($event->data),
                'audience' => $this->deriveAudience($event->data),
            ]);

            // Carried on the message itself so the paired MessageSent
            // event can find this exact row again. Matching on recipient
            // and subject instead would pick the wrong row whenever the
            // same message goes to the same person twice.
            $event->message->getHeaders()->addTextHeader('X-Skulos-Log-Id', (string) $log->id);

            $this->injectTrackingPixel($event, $log);
        } catch (\Throwable $e) {
            // Logging an email must never be the reason the email fails
            // to go out.
            Log::warning('Could not record outgoing mail: '.$e->getMessage());
        }
    }

    public function sent(MessageSent $event): void
    {
        try {
            // Depending on the Laravel version this event carries either
            // the Symfony Email itself or a SentMessage wrapping it, so
            // unwrap only when there is something to unwrap. Getting
            // this wrong fails silently into the catch below and leaves
            // every row stuck at "sending".
            $message = method_exists($event->message, 'getOriginalMessage')
                ? $event->message->getOriginalMessage()
                : $event->message;

            $header = $message->getHeaders()->get('X-Skulos-Log-Id');
            if (! $header) {
                return;
            }

            // getBody(), not getBodyAsString(): the latter returns the
            // header's encoded form, which does not cast back to the id.
            $id = (int) (method_exists($header, 'getBody') ? $header->getBody() : $header->getBodyAsString());
            if ($id <= 0) {
                return;
            }

            MailLog::where('id', $id)->update([
                'status' => MailLog::STATUS_SENT,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not mark outgoing mail as sent: '.$e->getMessage());
        }
    }

    /**
     * Which school a mail concerned, when knowable (2026-08-17, needed
     * for the platform mail report's per-school counts).
     *
     * Prefers whatever the Mailable itself carries — Laravel's own mail
     * events expose every PUBLIC property of the Mailable by name in
     * $data (see Mailable::buildViewData()), so a Mailable with a
     * `public School $school` property (most of them) already tells us
     * directly, no lookup needed. This matters because several of the
     * biggest mails a school ever receives — SchoolWelcomeMail,
     * TrialRequestStatusMail on approval — are sent from plain platform
     * admin controllers with no tenant connection active at all, so the
     * previous TenantManager::current()-only approach silently recorded
     * school_id as null for every one of them, undercounting exactly
     * the schools this report most needs to get right.
     *
     * Falls back to TenantManager::current() only when the Mailable
     * carries no school reference of its own (ContactFormMail,
     * PlatformBroadcastMail) — the same ambient-tenant guess this
     * always used, kept as a safety net rather than the primary source.
     *
     * @param  array<string, mixed>  $data
     */
    protected function deriveSchoolId(array $data): ?int
    {
        if (($data['school'] ?? null) instanceof School) {
            return $data['school']->id;
        }

        if (($data['applicant'] ?? null) instanceof Applicant) {
            // Applicant lives on the tenant connection itself (one
            // database per school), so the only school it could
            // possibly belong to is whichever one is currently active.
            return app(TenantManager::class)->current()?->id;
        }

        if (($data['trial'] ?? null) instanceof \App\Models\TrialRequest) {
            return $data['trial']->school_id;
        }

        return app(TenantManager::class)->current()?->id;
    }

    /**
     * Which of the four audiences (parent/student/staff/applicant) this
     * mail actually went to, or null when it genuinely is not one of
     * them — a trial applicant who has no school yet, a school's own
     * welcome letter to its owner, a contact-form enquiry. Guessing
     * those into one of the four would misrepresent the report rather
     * than inform it.
     *
     * Several Mailables can go to any of the four depending on who is
     * actually being emailed (AccountCreatedMail provisions a login for
     * a parent, student, or staff member alike; every password-reset
     * mail can concern any role), so this cannot be a fixed per-class
     * lookup — it reads whatever the Mailable actually carries.
     *
     * @param  array<string, mixed>  $data
     */
    protected function deriveAudience(array $data): ?string
    {
        // The admissions pipeline (offer, credentials, reminder,
        // recovery, acceptance, onboarding) is always communication
        // with a family still on the admissions journey, whether the
        // Mailable carries the Applicant directly or, past acceptance,
        // the newly-created Student record for the same child.
        if (($data['applicant'] ?? null) instanceof Applicant) {
            return MailLog::AUDIENCE_APPLICANT;
        }
        if (($data['student'] ?? null) instanceof \App\Models\Tenant\Student
            && str_starts_with((string) ($data['__laravel_mailable'] ?? ''), 'App\\Mail\\Admission')) {
            return MailLog::AUDIENCE_APPLICANT;
        }

        // AccountCreatedMail already knows exactly which role it
        // provisioned a login for — no lookup needed.
        if (isset($data['roleSlug']) && is_string($data['roleSlug'])) {
            return $this->audienceForRoleSlug($data['roleSlug']);
        }

        // Password-reset mail carries the real User plus the School it
        // concerns; their actual membership role in that school (a
        // cheap, already-memoized lookup on the platform connection,
        // school_user/roles live there, not on the tenant database) is
        // the honest source, not a guess from the Mailable's name.
        if (($data['user'] ?? null) instanceof User && ($data['school'] ?? null) instanceof School) {
            $role = $data['user']->roleIn($data['school']);

            return $role ? $this->audienceForRoleSlug($role->slug) : null;
        }

        return null;
    }

    protected function audienceForRoleSlug(string $slug): string
    {
        return match ($slug) {
            'parent' => MailLog::AUDIENCE_PARENT,
            'student' => MailLog::AUDIENCE_STUDENT,
            default => MailLog::AUDIENCE_STAFF,
        };
    }

    /**
     * Adds the open-tracking pixel to an HTML message, automatically,
     * for every mail that has one (2026-08-19). This is what makes
     * MailLog::readStatus()'s "opened" state possible at all: nothing
     * about this app's actual mail-sending flow otherwise learns
     * whether a message was opened.
     *
     * Only possible here, not in a Mailable or the shared layout view:
     * the row this pixel needs to reference does not exist until the
     * line just above created it, but the HTML has already been fully
     * rendered by the time this event fires (Laravel builds a
     * Mailable's content before firing MessageSending). Mutating the
     * already-rendered body via $event->message->html() is the only
     * point in the whole send pipeline where both the finished HTML and
     * the row's real id exist together -- the same reasoning already
     * used two lines up to add the X-Skulos-Log-Id header after the
     * fact rather than during rendering.
     *
     * Plain text mail is left untouched: a hidden image makes no sense
     * outside HTML, and every Mailable in this app already sends both
     * parts, so plain-text-only mail should not exist here regardless.
     */
    protected function injectTrackingPixel(MessageSending $event, MailLog $log): void
    {
        $html = $event->message->getHtmlBody();
        if (! $html) {
            return;
        }

        $url = \Illuminate\Support\Facades\URL::signedRoute('mail.track', ['mailLog' => $log->id]);
        $pixel = '<img src="'.e($url).'" width="1" height="1" alt="" style="display:block;border:0" />';

        $withPixel = str_contains($html, '</body>')
            ? str_replace('</body>', $pixel.'</body>', $html)
            : $html.$pixel;

        $event->message->html($withPixel);
    }

    /** @param  array<string, string>|iterable  $addresses */
    protected function recipients(iterable $addresses): string
    {
        $out = [];
        foreach ($addresses as $address) {
            $out[] = is_object($address) && method_exists($address, 'getAddress')
                ? $address->getAddress()
                : (string) $address;
        }

        return mb_substr(implode(', ', $out) ?: 'unknown', 0, 250);
    }
}
