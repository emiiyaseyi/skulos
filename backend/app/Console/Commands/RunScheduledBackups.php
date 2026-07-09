<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Models\School;
use App\Services\Tenancy\BackupService;
use Illuminate\Console\Command;

/**
 * Runs any school's backup whose configured schedule is due this hour
 * (user request 2026-07-09: a default backup time/date, not just manual).
 * Registered hourly in routes/console.php; a school with
 * backup_frequency "none" is never picked up.
 */
class RunScheduledBackups extends Command
{
    protected $signature = 'backups:run-scheduled';

    protected $description = "Back up every school whose backup schedule is due this hour";

    public function handle(BackupService $backups): int
    {
        $now = now();

        $due = School::where('status', School::STATUS_ACTIVE)
            ->where('backup_frequency', '!=', 'none')
            ->whereNotNull('backup_time')
            ->get()
            ->filter(fn (School $school) => $this->isDue($school, $now));

        foreach ($due as $school) {
            try {
                $backups->create($school, Backup::TYPE_SCHEDULED);
                $this->info("Backed up {$school->slug}.");
            } catch (\Throwable $e) {
                $this->error("Backup failed for {$school->slug}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    protected function isDue(School $school, \Illuminate\Support\Carbon $now): bool
    {
        if ($now->format('H:i') !== substr((string) $school->backup_time, 0, 5)) {
            return false;
        }

        return match ($school->backup_frequency) {
            'daily' => true,
            'weekly' => $now->dayOfWeekIso === 1, // Monday
            'monthly' => $now->day === 1,
            default => false,
        };
    }
}
