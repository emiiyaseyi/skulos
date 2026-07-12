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
     * @return array{in_app: int, email_sent: int, email_failed: int}
     */
    public function dispatchAnnouncement(Announcement $announcement, array $recipients): array
    {
        $announcement->forceFill([
            'status' => Announcement::STATUS_PUBLISHED,
            'published_at' => now(),
        ])->save();

        $inApp = 0;
        $emailTargets = [];

        foreach ($recipients as $r) {
            // Everyone gets an in-app notification.
            Notification::create([
                'user_id' => $r['user_id'],
                'audience' => $r['audience'] ?? null,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'category' => 'announcement',
                'type' => Notification::typeFor('announcement'),
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

        $email = ['sent' => 0, 'failed' => 0];
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

        return ['in_app' => $inApp, 'email_sent' => $email['sent'], 'email_failed' => $email['failed']];
    }
}
