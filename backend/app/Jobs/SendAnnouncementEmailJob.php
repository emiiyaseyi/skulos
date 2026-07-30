<?php

namespace App\Jobs;

use App\Mail\AnnouncementMail;
use App\Models\School;
use App\Models\Tenant\CommunicationLog;
use App\Services\Mail\MailSettingsService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one announcement/broadcast email to one recipient and updates its
 * CommunicationLog row. Split out of CommunicationService (2026-07-30 audit
 * follow-up): a school wide "all" audience broadcast can mean hundreds to
 * low thousands of Mail::send() calls, each a real SMTP round trip, and
 * that used to run inline inside the HTTP request that triggered it, with
 * a real risk of hitting PHP's max_execution_time partway through on
 * shared hosting and leaving the rest of the recipients silently unsent.
 */
class SendAnnouncementEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $schoolId,
        public int $communicationLogId,
        public string $email,
        public string $subjectLine,
        public string $bodyText,
        public string $schoolName,
    ) {
    }

    public function handle(TenantManager $tenants, MailSettingsService $mailSettings): void
    {
        $school = School::findOrFail($this->schoolId);

        $tenants->connectTo($school, function () use ($mailSettings) {
            // A queue worker stays booted across many jobs for many
            // different schools; MailSettingsService mutates config('mail.*')
            // in place, so it must be reapplied fresh before every send
            // (platform default, then this school's own override), the same
            // order ResolveTenant runs at HTTP-request time. Skipping this
            // would let a school with no SMTP override of its own silently
            // inherit whatever the previous job in this worker last
            // configured, instead of the platform/.env default.
            $mailSettings->applyPlatformDefaults();
            $mailSettings->applySchoolOverride();

            $log = CommunicationLog::find($this->communicationLogId);

            try {
                Mail::to($this->email)->send(new AnnouncementMail($this->subjectLine, $this->bodyText, $this->schoolName));
                $log?->update(['status' => CommunicationLog::STATUS_SENT, 'sent_at' => now()]);
            } catch (Throwable $e) {
                $log?->update(['status' => CommunicationLog::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 250)]);

                throw $e;
            }
        });
    }
}
