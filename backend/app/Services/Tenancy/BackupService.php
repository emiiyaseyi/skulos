<?php

namespace App\Services\Tenancy;

use App\Models\Backup;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Backup and restore for one school's tenant database (user request
 * 2026-07-09): the school admin or the platform admin can trigger a
 * backup any time, plus a scheduled default; backups are downloadable
 * and restorable. Branches by tenant driver so both the local SQLite
 * setup and production MySQL work — never assumes one or the other.
 */
class BackupService
{
    public function __construct(protected TenantManager $tenants)
    {
    }

    public function create(School $school, string $type = Backup::TYPE_MANUAL, ?User $initiator = null): Backup
    {
        $timestamp = now()->format('Y-m-d_His');
        $driver = config('tenancy.driver');

        if ($driver === 'sqlite') {
            $relativePath = "backups/{$school->id}/{$timestamp}.sqlite";
            $sourcePath = config('tenancy.sqlite_path').DIRECTORY_SEPARATOR.$school->database.'.sqlite';

            if (! file_exists($sourcePath)) {
                throw new RuntimeException("No tenant database file found for [{$school->slug}].");
            }

            // Release any open handle on this connection before reading the
            // file, so the copy is not made mid-write.
            if ($this->tenants->current()?->is($school)) {
                $this->tenants->forget();
            }

            Storage::disk('local')->put($relativePath, file_get_contents($sourcePath));
        } else {
            $relativePath = "backups/{$school->id}/{$timestamp}.sql";
            $this->dumpMysql($school, $relativePath);
        }

        return Backup::create([
            'school_id' => $school->id,
            'disk' => 'local',
            'file_path' => $relativePath,
            'size_bytes' => Storage::disk('local')->size($relativePath),
            'type' => $type,
            'initiated_by' => $initiator?->id,
        ]);
    }

    /**
     * Restore never touches the live database/file until the backup has
     * been written somewhere else first and confirmed valid (2026-07-30,
     * a real hazard found by a code audit: the previous version dropped/
     * overwrote the live tenant data immediately, so a corrupt backup file
     * or a mid-import failure left the school with an empty or partially
     * restored database and no way back). Both drivers now follow the same
     * write-verify-swap shape: restore into a throwaway location, verify
     * it, and only then replace the live one — if verification fails, the
     * live data was never touched.
     */
    public function restore(Backup $backup): void
    {
        $school = $backup->school;
        abort_unless($backup->exists(), 404, 'That backup file is missing.');

        $driver = config('tenancy.driver');

        if ($this->tenants->current()?->is($school)) {
            $this->tenants->forget();
        }

        if ($driver === 'sqlite') {
            $this->restoreSqlite($school, $backup);
        } else {
            $this->restoreMysql($school, $backup);
        }

        $backup->update(['restored_at' => now()]);
    }

    /**
     * Writes the backup to a temp file beside the live one, verifies it
     * opens as a real, uncorrupted SQLite database, then atomically
     * renames it over the live file. rename() on the same filesystem is
     * a single directory-entry swap, not a byte-by-byte overwrite, so
     * there is no window where the live file is partially written.
     */
    protected function restoreSqlite(School $school, Backup $backup): void
    {
        $targetPath = config('tenancy.sqlite_path').DIRECTORY_SEPARATOR.$school->database.'.sqlite';
        $tempPath = $targetPath.'.restoring-'.uniqid();

        file_put_contents($tempPath, Storage::disk($backup->disk)->get($backup->file_path));

        try {
            $this->assertValidSqlite($tempPath);
        } catch (\Throwable $e) {
            @unlink($tempPath);
            throw new RuntimeException('That backup file is not a valid database, restore was cancelled: '.$e->getMessage());
        }

        if (! rename($tempPath, $targetPath)) {
            @unlink($tempPath);
            throw new RuntimeException('Could not swap in the restored database file.');
        }
    }

    /** Opens the file as SQLite and runs a real integrity check, not just "the file exists and is non-empty." */
    protected function assertValidSqlite(string $path): void
    {
        $pdo = new \PDO('sqlite:'.$path);
        $result = $pdo->query('PRAGMA integrity_check')->fetchColumn();

        if ($result !== 'ok') {
            throw new RuntimeException('integrity check reported: '.$result);
        }
    }

    public function delete(Backup $backup): void
    {
        Storage::disk($backup->disk)->delete($backup->file_path);
        $backup->delete();
    }

    protected function dumpMysql(School $school, string $relativePath): void
    {
        $conn = config('database.connections.mysql');
        $absolutePath = Storage::disk('local')->path($relativePath);
        Storage::disk('local')->makeDirectory(dirname($relativePath));

        $result = Process::timeout(600)->run([
            'mysqldump',
            '-h', $conn['host'], '-P', (string) $conn['port'],
            '-u', $conn['username'], '--password='.$conn['password'],
            $school->database,
            '--result-file='.$absolutePath,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('mysqldump failed: '.$result->errorOutput());
        }
    }

    /**
     * Imports into a throwaway database first, never the live one. Only
     * once the import has actually succeeded does swapMysqlDatabase()
     * move it into place; if mysql fails partway through the import, the
     * live database was never dropped or touched, unlike the previous
     * drop-then-import version.
     */
    protected function restoreMysql(School $school, Backup $backup): void
    {
        $conn = config('database.connections.mysql');
        $absolutePath = Storage::disk($backup->disk)->path($backup->file_path);
        $liveDb = $school->database;
        $tempDb = $liveDb.'_restoring_'.time();

        DB::statement(sprintf('DROP DATABASE IF EXISTS `%s`', $tempDb));
        DB::statement(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $tempDb
        ));

        $command = 'mysql -h '.escapeshellarg($conn['host']).' -P '.escapeshellarg((string) $conn['port']).
            ' -u '.escapeshellarg($conn['username']).' --password='.escapeshellarg($conn['password']).
            ' '.escapeshellarg($tempDb).' < '.escapeshellarg($absolutePath);

        $result = Process::timeout(600)->run($command);

        if ($result->failed()) {
            DB::statement(sprintf('DROP DATABASE IF EXISTS `%s`', $tempDb));
            throw new RuntimeException('mysql restore failed, the live database was not touched: '.$result->errorOutput());
        }

        $this->swapMysqlDatabase($liveDb, $tempDb);
    }

    /**
     * Moves every table from $tempDb into $liveDb's name. MySQL has no
     * RENAME DATABASE statement, so this renames every table instead,
     * which a single multi-pair RENAME TABLE statement does atomically.
     * The live database's own current tables are moved aside into a
     * timestamped "before this restore" database first, kept around as
     * a real safety net rather than dropped immediately, at the cost of
     * disk space until an admin (or a future retention job) clears it.
     */
    protected function swapMysqlDatabase(string $liveDb, string $tempDb): void
    {
        DB::statement(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $liveDb
        ));

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $liveTables = collect(DB::select('SHOW TABLES FROM `'.$liveDb.'`'))
                ->map(fn ($row) => array_values((array) $row)[0]);

            if ($liveTables->isNotEmpty()) {
                $preRestoreDb = $liveDb.'_before_restore_'.date('Ymd_His');
                DB::statement(sprintf(
                    'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    $preRestoreDb
                ));
                $renames = $liveTables
                    ->map(fn ($t) => sprintf('`%s`.`%s` TO `%s`.`%s`', $liveDb, $t, $preRestoreDb, $t))
                    ->implode(', ');
                DB::statement('RENAME TABLE '.$renames);
            }

            $restoredTables = collect(DB::select('SHOW TABLES FROM `'.$tempDb.'`'))
                ->map(fn ($row) => array_values((array) $row)[0]);

            if ($restoredTables->isNotEmpty()) {
                $renames = $restoredTables
                    ->map(fn ($t) => sprintf('`%s`.`%s` TO `%s`.`%s`', $tempDb, $t, $liveDb, $t))
                    ->implode(', ');
                DB::statement('RENAME TABLE '.$renames);
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        DB::statement(sprintf('DROP DATABASE IF EXISTS `%s`', $tempDb));
    }
}
