<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\DB;

/**
 * The platform dashboard's "Server Health" card (Dashboard Design
 * Cross-Check, 2026-07-13: "Blocked, no infrastructure monitoring wired
 * in"). There genuinely is no uptime/CPU/memory monitoring service behind
 * this app, and inventing a fake "99.98% uptime" number would violate the
 * standing no-dummy-data rule. What CAN be checked honestly, with no new
 * infrastructure, is whether the platform database answers, how fast it
 * answered, and how much disk space is left on the volume storage/backups
 * actually write to, the same kind of self-check `HealthService` already
 * does per school. If real infrastructure monitoring (uptime tracking,
 * queue worker status, external pings) is ever wired in, this is the class
 * to extend, not replace.
 */
class ServerHealthService
{
    /**
     * @return array{database_ok: bool, database_ms: float, disk_free_pct: ?float, disk_free_gb: ?float, queue_driver: string}
     */
    public function check(): array
    {
        $start = microtime(true);
        try {
            DB::select('select 1');
            $databaseOk = true;
        } catch (\Throwable $e) {
            $databaseOk = false;
        }
        $databaseMs = round((microtime(true) - $start) * 1000, 1);

        $path = storage_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        $diskFreePct = ($total && $free) ? round(($free / $total) * 100, 1) : null;
        $diskFreeGb = $free ? round($free / 1024 / 1024 / 1024, 1) : null;

        return [
            'database_ok' => $databaseOk,
            'database_ms' => $databaseMs,
            'disk_free_pct' => $diskFreePct,
            'disk_free_gb' => $diskFreeGb,
            'queue_driver' => config('queue.default'),
        ];
    }
}
