<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\School;
use App\Models\Tenant\Setting;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Durable export / external shipping leg of the 2026-07-20 audit log
 * hardening: batches every audit_logs row written since the last run into
 * an append-only NDJSON file (one JSON object per line, easy for any
 * external log shipper or SIEM to tail) on config('skulos.audit_export_disk'),
 * never overwriting a previous batch. Scheduled hourly in routes/console.php.
 *
 * A watermark (the highest exported audit_logs.id) is kept in
 * PlatformSetting for the platform connection and in each school's own
 * tenant Setting, so re-running never re-exports a row already shipped and
 * a school provisioned after this feature shipped starts exporting from
 * its own row 1 the first time this command reaches it.
 */
class AuditLogsExport extends Command
{
    protected $signature = 'audit-logs:export {--connection= : Only this connection (platform, or a school slug)} {--chunk=1000 : Rows per exported file}';

    protected $description = 'Export new audit_logs rows to durable, append-only NDJSON batches';

    protected const WATERMARK_KEY = 'audit_log_export_watermark';

    public function handle(TenantManager $tenants): int
    {
        $only = $this->option('connection');
        $chunkSize = max(1, (int) $this->option('chunk'));

        if (! $only || $only === 'platform') {
            $this->exportConnection(config('database.default'), 'platform', $chunkSize,
                fn () => (int) (PlatformSetting::grab(self::WATERMARK_KEY, 0)),
                fn (int $id) => PlatformSetting::put(self::WATERMARK_KEY, $id),
            );
        }

        if ($only && $only !== 'platform') {
            $school = School::where('slug', $only)->whereNotNull('provisioned_at')->first();
            if (! $school) {
                $this->error("No provisioned school with slug [{$only}].");

                return self::FAILURE;
            }
            $tenants->connectTo($school, fn () => $this->exportTenant($school, $chunkSize));

            return self::SUCCESS;
        }

        if (! $only) {
            $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')->get();
            foreach ($schools as $school) {
                try {
                    $tenants->connectTo($school, fn () => $this->exportTenant($school, $chunkSize));
                } catch (\Throwable $e) {
                    $this->error("Skipping {$school->slug}: {$e->getMessage()}");
                }
            }
        }

        return self::SUCCESS;
    }

    protected function exportTenant(School $school, int $chunkSize): void
    {
        $this->exportConnection('tenant', $school->slug, $chunkSize,
            fn () => (int) (Setting::grab(self::WATERMARK_KEY, 0)),
            fn (int $id) => Setting::put(self::WATERMARK_KEY, $id),
        );
    }

    protected function exportConnection(string $connection, string $label, int $chunkSize, callable $getWatermark, callable $putWatermark): void
    {
        $disk = Storage::disk(config('skulos.audit_export_disk'));
        $watermark = $getWatermark();
        $exported = 0;

        AuditLog::on($connection)
            ->where('id', '>', $watermark)
            ->orderBy('id')
            ->chunk($chunkSize, function ($rows) use (&$watermark, &$exported, $disk, $label, $putWatermark) {
                $lines = $rows->map(fn (AuditLog $row) => json_encode([
                    'id' => $row->id,
                    'user_id' => $row->user_id,
                    'action' => $row->action,
                    'auditable_type' => $row->auditable_type,
                    'auditable_id' => $row->auditable_id,
                    'old_values' => $row->old_values,
                    'new_values' => $row->new_values,
                    'ip_address' => $row->ip_address,
                    'user_agent' => $row->user_agent,
                    'url' => $row->url,
                    'created_at' => optional($row->created_at)->toIso8601String(),
                    'previous_hash' => $row->previous_hash,
                    'hash' => $row->hash,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->implode("\n");

                $fromId = $rows->first()->id;
                $toId = $rows->last()->id;
                $path = "audit-exports/{$label}/".now()->format('Y/m')."/{$fromId}-{$toId}-".now()->format('YmdHis').'.ndjson';

                $disk->put($path, $lines);

                $watermark = $toId;
                $putWatermark($watermark);
                $exported += $rows->count();
            });

        if ($exported) {
            $this->info("{$label}: exported {$exported} row(s), watermark now {$watermark}.");
        }
    }
}
