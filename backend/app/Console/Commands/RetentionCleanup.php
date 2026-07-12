<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\CommunicationLog;
use App\Models\Tenant\Notification;
use App\Services\Maintenance\RetentionService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Purges what the Dbase recommendation calls "anything that grows
 * unbounded": read notifications, communication (SMS/email/WhatsApp) logs,
 * and expired platform login sessions, each past its configured retention
 * window (RetentionService, admin editable, platform then school
 * precedence). Registered weekly in routes/console.php.
 *
 * Deliberately does NOT touch App\Models\AuditLog: that model is
 * immutable by design (throws on update/delete), so audit trail retention
 * needs an archive-not-delete approach, not a purge, and is out of scope
 * for this command — see the class docblock on AuditLog and the tracker's
 * Database & Storage Efficiency Rules section.
 */
class RetentionCleanup extends Command
{
    protected $signature = 'skulos:retention-cleanup';

    protected $description = 'Purge old notifications, communication logs, and expired sessions past their retention window';

    public function handle(RetentionService $retention, TenantManager $tenants): int
    {
        $this->cleanupSessions($retention);

        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')->get();
        foreach ($schools as $school) {
            try {
                $tenants->use($school);
            } catch (\Throwable $e) {
                $this->error("Skipping {$school->slug}: {$e->getMessage()}");

                continue;
            }

            $settings = $retention->effective();

            $notifDeleted = Notification::whereNotNull('read_at')
                ->where('read_at', '<', now()->subDays($settings['notifications_days']))
                ->delete();

            $commsDeleted = CommunicationLog::where('created_at', '<', now()->subDays($settings['communication_logs_days']))
                ->delete();

            $tenants->forget();

            if ($notifDeleted || $commsDeleted) {
                $this->info("{$school->slug}: purged {$notifDeleted} read notification(s), {$commsDeleted} communication log(s).");
            }
        }

        return self::SUCCESS;
    }

    /**
     * The platform `sessions` table (Laravel's own session driver storage)
     * has no built-in cleanup on this driver; a school-level retention
     * setting does not apply here since a login session is not scoped to
     * one school, so this always uses the platform default.
     */
    protected function cleanupSessions(RetentionService $retention): void
    {
        if (config('session.driver') !== 'database' || ! \Illuminate\Support\Facades\Schema::hasTable('sessions')) {
            return;
        }

        $days = (int) config('skulos.retention.sessions_days', 30);
        $platform = array_filter($retention->platformSettings(), fn ($v) => $v !== null && $v !== '');
        $days = $platform['sessions_days'] ?? $days;

        $deleted = DB::table('sessions')->where('last_activity', '<', now()->subDays($days)->timestamp)->delete();
        if ($deleted) {
            $this->info("Purged {$deleted} expired session(s).");
        }
    }
}
