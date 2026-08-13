<?php

namespace App\Services\Maintenance;

use App\Models\Backup;
use App\Models\School;
use App\Models\Tenant\CommunicationLog;
use App\Models\Tenant\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The actual work behind both the scheduled maintenance commands
 * (skulos:optimize-tables, skulos:retention-cleanup) and the Maintenance
 * Center's on-demand "run now" buttons, so there is exactly one place
 * that knows how to optimize a school's database or purge what has grown
 * unbounded — the command classes just loop every school and call this,
 * the controller calls it for the one school currently in view.
 */
class MaintenanceActionsService
{
    /**
     * Assumes the tenant connection already points at $school (the caller
     * is responsible for TenantManager::use()/forget()).
     *
     * @return array{driver: string, tables_touched: int}
     */
    public function optimize(School $school): array
    {
        $driver = DB::connection('tenant')->getDriverName();

        if ($driver === 'mysql') {
            $tables = Schema::connection('tenant')->getTableListing();
            foreach ($tables as $table) {
                $bareName = Str::afterLast($table, '.');
                DB::connection('tenant')->statement("OPTIMIZE TABLE `{$bareName}`");
            }

            return ['driver' => $driver, 'tables_touched' => count($tables)];
        }

        if ($driver === 'sqlite') {
            DB::connection('tenant')->statement('VACUUM');

            return ['driver' => $driver, 'tables_touched' => 0];
        }

        return ['driver' => $driver, 'tables_touched' => 0];
    }

    /**
     * Assumes the tenant connection already points at $school.
     *
     * @return array{notifications: int, communication_logs: int}
     */
    public function purgeRetention(School $school, RetentionService $retention): array
    {
        $settings = $retention->effective();

        $notifDeleted = Notification::whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays($settings['notifications_days']))
            ->delete();

        $commsDeleted = CommunicationLog::where('created_at', '<', now()->subDays($settings['communication_logs_days']))
            ->delete();

        return ['notifications' => $notifDeleted, 'communication_logs' => $commsDeleted];
    }

    /**
     * Prunes $school's own backups past BackupRetentionService's
     * effective() window (2026-08-12) — a backup is deleted only once
     * it fails BOTH keep_count and keep_days, matching the "keep if
     * either rule says keep" choice. When prune_manual is false (the
     * default), a manually-triggered backup is left out of the query
     * entirely rather than merely favoured, so it truly never gets
     * caught by keep_count pushing it out either.
     *
     * @return array{deleted: int, freed_bytes: int}
     */
    public function pruneBackups(School $school, BackupRetentionService $retention): array
    {
        $settings = $retention->effective();

        $query = Backup::where('school_id', $school->id);
        if (! $settings['prune_manual']) {
            $query->where('type', Backup::TYPE_SCHEDULED);
        }

        $candidates = $query->orderByDesc('created_at')->get();
        $toDelete = $candidates->slice($settings['keep_count'])
            ->filter(fn (Backup $b) => $b->created_at->lt(now()->subDays($settings['keep_days'])));

        $freedBytes = 0;
        foreach ($toDelete as $backup) {
            if ($backup->exists()) {
                Storage::disk($backup->disk)->delete($backup->file_path);
            }
            $freedBytes += (int) $backup->size_bytes;
            $backup->delete();
        }

        return ['deleted' => $toDelete->count(), 'freed_bytes' => $freedBytes];
    }

    /** Platform wide, not scoped to one school (a login session is not scoped to one school). */
    public function purgeExpiredSessions(RetentionService $retention): int
    {
        if (config('session.driver') !== 'database' || ! Schema::hasTable('sessions')) {
            return 0;
        }

        $days = (int) config('skulos.retention.sessions_days', 30);
        $platform = array_filter($retention->platformSettings(), fn ($v) => $v !== null && $v !== '');
        $days = $platform['sessions_days'] ?? $days;

        return DB::table('sessions')->where('last_activity', '<', now()->subDays($days)->timestamp)->delete();
    }
}
