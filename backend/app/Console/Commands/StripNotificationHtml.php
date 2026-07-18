<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\Notification;
use App\Services\Communication\NotificationService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * One-off backfill (user report 2026-07-18, a real notification showing
 * literal "<div style="text-align:center">..." markup): every Notification
 * created from an Announcement before NotificationService::
 * dispatchAnnouncement() started stripping HTML to a plain-text preview
 * still has the old raw-HTML body sitting in the database, since that fix
 * only changed behaviour going forward. This rewrites any existing
 * announcement/broadcast notification whose body still contains a tag.
 */
class StripNotificationHtml extends Command
{
    protected $signature = 'notifications:strip-html';

    protected $description = 'Convert any existing announcement/broadcast notification body that still contains raw HTML to plain text';

    public function handle(TenantManager $tenants, NotificationService $notifications): int
    {
        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')->get();
        $totalFixed = 0;

        foreach ($schools as $school) {
            $tenants->use($school);

            $affected = Notification::whereIn('category', ['announcement', 'broadcast'])
                ->whereNotNull('body')
                ->where('body', 'like', '%<%')
                ->get();

            foreach ($affected as $notification) {
                $notification->update(['body' => $notifications->plainTextPreview($notification->body)]);
            }

            if ($affected->isNotEmpty()) {
                $this->line("{$school->slug}: fixed {$affected->count()} notification(s).");
            }
            $totalFixed += $affected->count();

            $tenants->forget();
        }

        $this->info("Done: {$totalFixed} notification(s) fixed across {$schools->count()} school(s).");

        return self::SUCCESS;
    }
}
