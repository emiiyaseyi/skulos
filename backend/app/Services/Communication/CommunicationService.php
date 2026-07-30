<?php

namespace App\Services\Communication;

use App\Jobs\SendAnnouncementEmailJob;
use App\Models\Tenant\Announcement;
use App\Models\Tenant\CommunicationLog;
use App\Models\Tenant\Setting;
use App\Services\Auditing\Recorder;
use App\Services\Tenancy\TenantManager;

/**
 * Publishes announcements and sends notifications on enabled channels.
 * Every send is written to communication_logs with its outcome, so nothing
 * goes out untracked (Notification Center + delivery status).
 */
class CommunicationService
{
    /** Only 'email' has a real per-school Setting override so far (sms/whatsapp/push have no provider wired up yet, config('skulos.communication_channels') stays their only source until they do). */
    public const EMAIL_SETTING_KEY = 'communication_email_enabled';

    public function channelEnabled(string $channel): bool
    {
        if ($channel === 'email') {
            // Real DB-backed toggle (audit follow-up, 2026-07-18): this
            // used to read config('skulos.communication_channels.email.
            // enabled') directly with no way for a school to turn it off,
            // despite that config file's own comment already promising
            // "a school will toggle these in the admin CMS", matching the
            // standing "config is only ever a seed default, never the live
            // source of truth" rule. Config value is now only the
            // fallback for a school that has never touched the setting.
            return (bool) Setting::grab(self::EMAIL_SETTING_KEY, config('skulos.communication_channels.email.enabled', true));
        }

        return (bool) config("skulos.communication_channels.{$channel}.enabled", false);
    }

    public function setEmailChannelEnabled(bool $enabled): void
    {
        Setting::put(self::EMAIL_SETTING_KEY, $enabled);
    }

    /**
     * Mark an announcement published and queue an email for each recipient.
     *
     * @param list<string> $emailRecipients
     * @return array{queued: int}
     */
    public function publishAnnouncement(Announcement $announcement, array $emailRecipients = []): array
    {
        $announcement->forceFill([
            'status' => Announcement::STATUS_PUBLISHED,
            'published_at' => now(),
        ])->save();

        $outcome = ['queued' => 0];

        if ($this->channelEnabled('email')) {
            $outcome = $this->emailAnnouncement($announcement, $emailRecipients);
        }

        app(Recorder::class)->record(
            'announcement.published',
            $announcement,
            [],
            ['audience' => $announcement->audience, 'email' => $outcome],
            config('tenancy.connection'),
        );

        return $outcome;
    }

    /**
     * Logs each recipient as queued and hands the actual send to
     * SendAnnouncementEmailJob, off the request thread (audit follow-up
     * 2026-07-30: this used to Mail::send() every recipient inline here,
     * a real timeout risk for a large "all" audience broadcast).
     *
     * @param list<string> $recipients
     * @return array{queued: int}
     */
    public function emailAnnouncement(Announcement $announcement, array $recipients): array
    {
        $school = app(TenantManager::class)->current();
        $schoolName = $school?->name ?? 'Your School';
        $queued = 0;

        foreach (array_unique($recipients) as $email) {
            $log = CommunicationLog::create([
                'channel' => 'email',
                'recipient' => $email,
                'subject' => $announcement->title,
                'status' => CommunicationLog::STATUS_QUEUED,
                'announcement_id' => $announcement->id,
            ]);

            SendAnnouncementEmailJob::dispatch($school->id, $log->id, $email, $announcement->title, $announcement->body, $schoolName);
            $queued++;
        }

        return ['queued' => $queued];
    }

    /**
     * Queue a one off email to a list, logged, outside of an announcement
     * (fee reminders, result notices, ...).
     *
     * @param list<string> $recipients
     * @return array{queued: int}
     */
    public function sendEmail(array $recipients, string $subject, string $body): array
    {
        $school = app(TenantManager::class)->current();
        $schoolName = $school?->name ?? 'Your School';
        $queued = 0;

        foreach (array_unique($recipients) as $email) {
            $log = CommunicationLog::create([
                'channel' => 'email',
                'recipient' => $email,
                'subject' => $subject,
                'status' => CommunicationLog::STATUS_QUEUED,
            ]);

            SendAnnouncementEmailJob::dispatch($school->id, $log->id, $email, $subject, $body, $schoolName);
            $queued++;
        }

        return ['queued' => $queued];
    }
}
