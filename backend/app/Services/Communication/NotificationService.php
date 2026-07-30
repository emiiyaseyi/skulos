<?php

namespace App\Services\Communication;

use App\Models\Tenant\Announcement;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Setting;
use App\Services\Auditing\Recorder;

/**
 * Fans an announcement out to recipients:
 *   - EVERYONE gets an in-app/web notification (dashboard feed).
 *   - Email goes only to audiences the admin has enabled for email.
 *
 * The per-audience email switches live in tenant settings (admin editable),
 * defaulting from config. Nothing about who gets what is hardcoded.
 *
 * A recipient is: ['user_id' => int, 'email' => ?string, 'audience' => string]
 * where audience is one of parents|teachers|staff|students.
 */
class NotificationService
{
    public function __construct(protected CommunicationService $comms)
    {
    }

    public const SETTINGS_KEY = 'announcement_email_audiences';

    /**
     * @return array<string, bool>
     */
    public function emailAudiences(): array
    {
        $default = config('skulos.announcement_email_audiences', []);
        $override = Setting::grab(self::SETTINGS_KEY);

        return is_array($override) ? array_merge($default, $override) : $default;
    }

    public function audienceGetsEmail(string $audience): bool
    {
        return (bool) ($this->emailAudiences()[$audience] ?? false);
    }

    /**
     * Admin toggle for one audience's email.
     */
    public function setAudienceEmail(string $audience, bool $enabled): void
    {
        $current = $this->emailAudiences();
        $current[$audience] = $enabled;
        Setting::put(self::SETTINGS_KEY, $current);
    }

    /**
     * Publish an announcement and deliver it.
     *
     * @param list<array{user_id:int, email?:?string, audience:string}> $recipients
     * @return array{in_app: int, email_queued: int}
     */
    public function dispatchAnnouncement(Announcement $announcement, array $recipients): array
    {
        $announcement->forceFill([
            'status' => Announcement::STATUS_PUBLISHED,
            'published_at' => now(),
        ])->save();

        $inApp = 0;
        $emailTargets = [];

        // A broadcast (type='broadcast') is delivered as a sign-in pop-up
        // the recipient acknowledges; its notifications carry the
        // 'broadcast' category so the pop-up can find the unacknowledged
        // ones. A plain announcement keeps its 'announcement' category and
        // stays in the quieter notifications list, no pop-up.
        $category = $announcement->type === Announcement::TYPE_BROADCAST ? 'broadcast' : 'announcement';

        // The notification feed (this widget and the Notification Center
        // list) is a plain-text preview, not a rich-content renderer, so it
        // always echoes body through plain {{ }} text, never {!! !!}. An
        // announcement/broadcast composed with the rich-text editor stores
        // real HTML in Announcement::body (needed as-is for the email
        // render and is deliberately never sanitized there, see
        // App\Http\Controllers\School\AnnouncementController); reusing
        // that HTML verbatim as a Notification's own body showed the raw
        // markup as visible text instead of rendering it (user report
        // 2026-07-18, a notification literally showing "<div style=...>").
        // Stripped to plain text here, once, at the point a Notification
        // is created from an Announcement, rather than in every view that
        // reads Notification::body.
        $plainBody = $this->plainTextPreview($announcement->body);

        foreach ($recipients as $r) {
            // Everyone gets an in-app notification.
            Notification::create([
                'user_id' => $r['user_id'],
                'audience' => $r['audience'] ?? null,
                'title' => $announcement->title,
                'body' => $plainBody,
                'category' => $category,
                'type' => Notification::typeFor($category),
                'announcement_id' => $announcement->id,
            ]);
            $inApp++;

            // Email only if the channel is on AND this audience is enabled.
            if (! empty($r['email'])
                && $this->comms->channelEnabled('email')
                && $this->audienceGetsEmail($r['audience'] ?? '')) {
                $emailTargets[] = $r['email'];
            }
        }

        $email = ['queued' => 0];
        if ($emailTargets) {
            $email = $this->comms->emailAnnouncement($announcement, $emailTargets);
        }

        app(Recorder::class)->record(
            'announcement.dispatched',
            $announcement,
            [],
            ['in_app' => $inApp, 'email' => $email, 'audiences' => $this->emailAudiences()],
            config('tenancy.connection'),
        );

        return ['in_app' => $inApp, 'email_queued' => $email['queued']];
    }

    /**
     * Converts rich-text HTML (the announcement composer's output) to a
     * flat, readable plain-text preview: block-level boundaries become a
     * space first (so "</div><div>" does not mash two words together),
     * then every tag is stripped, then repeated whitespace collapses to
     * one space.
     */
    public function plainTextPreview(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        $spaced = preg_replace('/<\/(div|p|li|h[1-6]|tr)>|<br\s*\/?>/i', ' ', $html);
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($spaced)));

        return $text === '' ? null : $text;
    }
}
