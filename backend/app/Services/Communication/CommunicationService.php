<?php

namespace App\Services\Communication;

use App\Mail\AnnouncementMail;
use App\Models\Tenant\Announcement;
use App\Models\Tenant\CommunicationLog;
use App\Models\Tenant\Setting;
use App\Services\Auditing\Recorder;
use Illuminate\Support\Facades\Mail;
use Throwable;

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
     * Mark an announcement published and email the given recipients.
     *
     * @param list<string> $emailRecipients
     * @return array{sent: int, failed: int}
     */
    public function publishAnnouncement(Announcement $announcement, array $emailRecipients = []): array
    {
        $announcement->forceFill([
            'status' => Announcement::STATUS_PUBLISHED,
            'published_at' => now(),
        ])->save();

        $outcome = ['sent' => 0, 'failed' => 0];

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
     * @param list<string> $recipients
     * @return array{sent: int, failed: int}
     */
    public function emailAnnouncement(Announcement $announcement, array $recipients): array
    {
        $schoolName = optional(app(\App\Services\Tenancy\TenantManager::class)->current())->name ?? 'Your School';
        $sent = 0;
        $failed = 0;

        foreach (array_unique($recipients) as $email) {
            $log = CommunicationLog::create([
                'channel' => 'email',
                'recipient' => $email,
                'subject' => $announcement->title,
                'status' => CommunicationLog::STATUS_QUEUED,
                'announcement_id' => $announcement->id,
            ]);

            try {
                Mail::to($email)->send(new AnnouncementMail($announcement->title, $announcement->body, $schoolName));
                $log->update(['status' => CommunicationLog::STATUS_SENT, 'sent_at' => now()]);
                $sent++;
            } catch (Throwable $e) {
                $log->update(['status' => CommunicationLog::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 250)]);
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Send a one off email to a list, logged, outside of an announcement
     * (fee reminders, result notices, ...).
     *
     * @param list<string> $recipients
     * @return array{sent: int, failed: int}
     */
    public function sendEmail(array $recipients, string $subject, string $body): array
    {
        $announcement = new Announcement(['title' => $subject, 'body' => $body]);
        // reuse the mailable without persisting an announcement
        $schoolName = optional(app(\App\Services\Tenancy\TenantManager::class)->current())->name ?? 'Your School';
        $sent = 0;
        $failed = 0;

        foreach (array_unique($recipients) as $email) {
            $log = CommunicationLog::create([
                'channel' => 'email',
                'recipient' => $email,
                'subject' => $subject,
                'status' => CommunicationLog::STATUS_QUEUED,
            ]);
            try {
                Mail::to($email)->send(new AnnouncementMail($subject, $body, $schoolName));
                $log->update(['status' => CommunicationLog::STATUS_SENT, 'sent_at' => now()]);
                $sent++;
            } catch (Throwable $e) {
                $log->update(['status' => CommunicationLog::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 250)]);
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
