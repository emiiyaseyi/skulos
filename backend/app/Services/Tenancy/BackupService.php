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

    public function restore(Backup $backup): void
    {
        $school = $backup->school;
        abort_unless($backup->exists(), 404, 'That backup file is missing.');

        $driver = config('tenancy.driver');

        if ($this->tenants->current()?->is($school)) {
            $this->tenants->forget();
        }

        if ($driver === 'sqlite') {
            $targetPath = config('tenancy.sqlite_path').DIRECTORY_SEPARATOR.$school->database.'.sqlite';
            file_put_contents($targetPath, Storage::disk($backup->disk)->get($backup->file_path));
        } else {
            $this->restoreMysql($school, $backup);
        }

        $backup->update(['restored_at' => now()]);
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

    protected function restoreMysql(School $school, Backup $backup): void
    {
        $conn = config('database.connections.mysql');
        $absolutePath = Storage::disk($backup->disk)->path($backup->file_path);

        DB::statement(sprintf('DROP DATABASE IF EXISTS `%s`', $school->database));
        DB::statement(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $school->database
        ));

        $command = 'mysql -h '.escapeshellarg($conn['host']).' -P '.escapeshellarg((string) $conn['port']).
            ' -u '.escapeshellarg($conn['username']).' --password='.escapeshellarg($conn['password']).
            ' '.escapeshellarg($school->database).' < '.escapeshellarg($absolutePath);

        $result = Process::timeout(600)->run($command);

        if ($result->failed()) {
            throw new RuntimeException('mysql restore failed: '.$result->errorOutput());
        }
    }
}
