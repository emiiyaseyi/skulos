<?php

namespace App\Jobs;

use App\Mail\PlatformBroadcastMail;
use App\Services\Mail\MailSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one platform broadcast email to one school's leadership recipient.
 * Split out of Admin\BroadcastController (2026-07-30 audit follow-up): the
 * platform admin's "Send Broadcast" tool can loop across every provisioned
 * school at once, each with its own leadership tier, all synchronous inside
 * the request that triggered it, the same timeout hazard as the school
 * level announcement send this mirrors.
 */
class SendPlatformBroadcastEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $email,
        public string $subjectLine,
        public string $bodyText,
        public string $schoolName,
    ) {
    }

    public function handle(MailSettingsService $mailSettings): void
    {
        // Platform broadcasts are not sent within a school's tenant
        // context, only the platform's own SMTP settings apply. Reapplied
        // fresh here for the same reason as SendAnnouncementEmailJob: a
        // persistent worker process can have a school's override still
        // sitting in config('mail.*') from an earlier, unrelated job.
        $mailSettings->applyPlatformDefaults();

        Mail::to($this->email)->send(new PlatformBroadcastMail($this->subjectLine, $this->bodyText, $this->schoolName));
    }
}
