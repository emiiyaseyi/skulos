<?php

namespace App\Services\Maintenance;

use App\Models\PlatformSetting;
use App\Models\School;
use App\Models\Tenant\Setting as TenantSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Audit trail archive + prune (2026-08-15). The original design
 * deliberately refused to purge audit_logs at all ("immutable by
 * design"), which was right at the time but left the table growing
 * without limit — on a real school it reached 295k rows / 170MB, by far
 * the largest thing in that school's database.
 *
 * Pruning is only ever safe because a row must pass BOTH gates first:
 *
 *   1. It is already durably exported. AuditLogsExport ships every row
 *      to append-only NDJSON hourly and records the highest exported id
 *      as a watermark; nothing above that watermark is ever touched.
 *   2. It is older than the school's own retention window (7-30 days).
 *
 * On top of that, buildCsv() writes the school's own readable copy of
 * the period before anything is deleted, which is what the monthly mail
 * links to. So a deleted row still exists in two places, not zero.
 */
class AuditArchiveService
{
    /** Matches AuditLogsExport::WATERMARK_KEY — the highest audit id already shipped to durable storage. */
    protected const WATERMARK_KEY = 'audit_log_export_watermark';

    /**
     * Writes one period's audit rows to a CSV on the private local disk
     * and returns its path/size/row count. Streamed row by row rather
     * than loaded into memory, since this deliberately runs over the
     * largest table in the database.
     *
     * @return array{path: string, bytes: int, rows: int}
     */
    public function buildCsv(School $school, CarbonInterface $from, CarbonInterface $to): array
    {
        $path = "audit-archives/{$school->slug}/".$from->format('Y-m').'.csv';
        $absolute = Storage::disk('local')->path($path);
        Storage::disk('local')->makeDirectory(dirname($path));

        $handle = fopen($absolute, 'w');
        fputcsv($handle, ['id', 'occurred_at', 'user_id', 'action', 'auditable_type', 'auditable_id', 'ip_address', 'changes']);

        $rows = 0;
        DB::connection('tenant')->table('audit_logs')
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('id')
            ->chunk(1000, function ($chunk) use ($handle, &$rows) {
                foreach ($chunk as $row) {
                    fputcsv($handle, [
                        $row->id,
                        $row->created_at,
                        $row->user_id,
                        $row->action ?? '',
                        $row->auditable_type ?? '',
                        $row->auditable_id ?? '',
                        $row->ip_address ?? '',
                        // Already JSON in the column; kept as-is so the
                        // CSV stays a faithful copy rather than a lossy
                        // summary of what actually changed.
                        is_string($row->changes ?? null) ? $row->changes : json_encode($row->changes ?? null),
                    ]);
                    $rows++;
                }
            });

        fclose($handle);

        return ['path' => $path, 'bytes' => Storage::disk('local')->size($path), 'rows' => $rows];
    }

    /**
     * Deletes audit rows older than the window AND already exported.
     * Uses the query builder, not the AuditLog model, because that model
     * blocks deletes by design — the guard is intentional and stays; this
     * is the one archive-backed path allowed past it.
     */
    public function prune(int $days): int
    {
        $watermark = (int) TenantSetting::grab(self::WATERMARK_KEY, 0);

        // No watermark means nothing has ever been exported for this
        // school, so there is no safe copy anywhere and nothing may be
        // deleted, however old it is.
        if ($watermark <= 0) {
            return 0;
        }

        return DB::connection('tenant')->table('audit_logs')
            ->where('id', '<=', $watermark)
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    /** Same shape for the platform's own audit_logs table. */
    public function prunePlatform(int $days): int
    {
        $watermark = (int) PlatformSetting::grab(self::WATERMARK_KEY, 0);

        if ($watermark <= 0) {
            return 0;
        }

        return DB::table('audit_logs')
            ->where('id', '<=', $watermark)
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    /** Clamped to the configured 7-30 range so a stray setting can never wipe recent history. */
    public function effectiveDays(RetentionService $retention): int
    {
        $days = (int) ($retention->effective()['audit_logs_days'] ?? 30);

        return max(
            (int) config('skulos.audit_archive.min_days', 7),
            min((int) config('skulos.audit_archive.max_days', 30), $days)
        );
    }
}
