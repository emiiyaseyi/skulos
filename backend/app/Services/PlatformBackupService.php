<?php

namespace App\Services;

use App\Models\PlatformBackup;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Backup for the shared platform database itself — see PlatformBackup's
 * migration docblock for why this exists as its own service alongside
 * Tenancy\BackupService rather than folding into it. Deliberately create
 * only, no restore: restoring the one database the running app itself
 * reads from while it is still serving requests is a fundamentally
 * different, much higher risk operation than restoring one school's
 * tenant database (which the app can safely disconnect from mid request).
 * A real platform restore is a manual, app-offline operation for whoever
 * is doing the deploy, not a button in this UI — see DEPLOYMENT.md.
 */
class PlatformBackupService
{
    public function create(string $type = PlatformBackup::TYPE_MANUAL, ?User $initiator = null): PlatformBackup
    {
        $timestamp = now()->format('Y-m-d_His');
        $driver = config('database.default');

        if ($driver === 'sqlite') {
            $relativePath = "platform-backups/{$timestamp}.sqlite";
            $sourcePath = config('database.connections.sqlite.database');

            if (! file_exists($sourcePath)) {
                throw new RuntimeException('No platform database file found.');
            }

            Storage::disk('local')->put($relativePath, file_get_contents($sourcePath));
        } else {
            $relativePath = "platform-backups/{$timestamp}.sql";
            $this->dumpMysql($relativePath);
        }

        return PlatformBackup::create([
            'disk' => 'local',
            'file_path' => $relativePath,
            'size_bytes' => Storage::disk('local')->size($relativePath),
            'type' => $type,
            'initiated_by' => $initiator?->id,
        ]);
    }

    public function delete(PlatformBackup $backup): void
    {
        Storage::disk($backup->disk)->delete($backup->file_path);
        $backup->delete();
    }

    protected function dumpMysql(string $relativePath): void
    {
        $conn = config('database.connections.mysql');
        $absolutePath = Storage::disk('local')->path($relativePath);
        Storage::disk('local')->makeDirectory(dirname($relativePath));

        $result = Process::timeout(600)->run([
            'mysqldump',
            '-h', $conn['host'], '-P', (string) $conn['port'],
            '-u', $conn['username'], '--password='.$conn['password'],
            $conn['database'],
            '--result-file='.$absolutePath,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('mysqldump failed: '.$result->errorOutput());
        }
    }
}
