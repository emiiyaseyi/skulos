<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Maintenance\BackupRetentionService;
use App\Services\Maintenance\MaintenanceActionsService;
use App\Services\Maintenance\RetentionService;
use App\Services\PlatformBackupService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * Purges what the Dbase recommendation calls "anything that grows
 * unbounded": read notifications, communication (SMS/email/WhatsApp) logs,
 * expired platform login sessions, and (2026-08-12) backups past their
 * own retention window — the single item on this list that had NO
 * pruning at all before, per school and the platform's own alike, each
 * past its configured retention window (RetentionService/
 * BackupRetentionService, admin editable, platform then school
 * precedence). Registered weekly in routes/console.php. The actual work
 * lives in MaintenanceActionsService, shared with the Maintenance
 * Center's "Run retention cleanup now" button.
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

    public function handle(RetentionService $retention, MaintenanceActionsService $actions, TenantManager $tenants, BackupRetentionService $backupRetention, PlatformBackupService $platformBackups): int
    {
        $sessionsPurged = $actions->purgeExpiredSessions($retention);
        if ($sessionsPurged) {
            $this->info("Purged {$sessionsPurged} expired session(s).");
        }

        $platformPrune = $platformBackups->prune($backupRetention);
        if ($platformPrune['deleted']) {
            $this->info("Platform backups: pruned {$platformPrune['deleted']} backup(s).");
        }

        // Failed Logins (2026-07-15): a fixed 90 day window, platform
        // level (not per school Setting like notifications/communication
        // logs above, this table has no tenant connection at all), same
        // "anything unbounded gets a retention policy" rule.
        $failedLoginsPurged = \App\Models\FailedLogin::where('created_at', '<', now()->subDays(90))->delete();
        if ($failedLoginsPurged) {
            $this->info("Purged {$failedLoginsPurged} failed login record(s) older than 90 days.");
        }

        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')->get();
        foreach ($schools as $school) {
            try {
                $tenants->use($school);
            } catch (\Throwable $e) {
                $this->error("Skipping {$school->slug}: {$e->getMessage()}");

                continue;
            }

            $result = $actions->purgeRetention($school, $retention);
            $backupResult = $actions->pruneBackups($school, $backupRetention);
            $tenants->forget();

            if ($result['notifications'] || $result['communication_logs']) {
                $this->info("{$school->slug}: purged {$result['notifications']} read notification(s), {$result['communication_logs']} communication log(s).");
            }
            if ($backupResult['deleted']) {
                $this->info("{$school->slug}: pruned {$backupResult['deleted']} backup(s).");
            }
        }

        return self::SUCCESS;
    }
}
