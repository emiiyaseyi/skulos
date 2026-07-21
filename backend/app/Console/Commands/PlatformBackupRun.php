<?php

namespace App\Console\Commands;

use App\Models\PlatformBackup;
use App\Services\PlatformBackupService;
use Illuminate\Console\Command;

/**
 * Daily backup of the shared platform database — see PlatformBackup's
 * migration docblock for why this exists. Registered daily in
 * routes/console.php, off peak hours, same as the other maintenance jobs.
 */
class PlatformBackupRun extends Command
{
    protected $signature = 'platform:backup';

    protected $description = 'Back up the shared platform database';

    public function handle(PlatformBackupService $backups): int
    {
        try {
            $backup = $backups->create(PlatformBackup::TYPE_SCHEDULED);
            $this->info("Platform database backed up ({$backup->humanSize()}).");
        } catch (\Throwable $e) {
            $this->error("Platform backup failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
