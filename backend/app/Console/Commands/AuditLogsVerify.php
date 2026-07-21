<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Auditing\IntegrityChecker;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * Confirms the audit log hash chain is unbroken on the platform database
 * and every provisioned school's tenant database (2026-07-20 audit log
 * hardening). Non-zero exit on any failure, so cron/CI can alert on it;
 * scheduled daily in routes/console.php. Also backs the admin Audit Log
 * browser's "Verify integrity" action for an on-demand platform check.
 */
class AuditLogsVerify extends Command
{
    protected $signature = 'audit-logs:verify {--connection= : Only this connection (platform, or a school slug)}';

    protected $description = 'Verify the audit log tamper-evident hash chain is unbroken';

    public function handle(IntegrityChecker $checker, TenantManager $tenants): int
    {
        $only = $this->option('connection');
        $anyBroken = false;

        if (! $only || $only === 'platform') {
            $anyBroken = ! $this->reportOn($checker, config('database.default'), 'platform') || $anyBroken;
        }

        if ($only && $only !== 'platform') {
            $school = School::where('slug', $only)->whereNotNull('provisioned_at')->first();
            if (! $school) {
                $this->error("No provisioned school with slug [{$only}].");

                return self::FAILURE;
            }
            $ok = $tenants->connectTo($school, fn () => $this->reportOn($checker, 'tenant', $school->slug));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        if (! $only) {
            $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')->get();
            foreach ($schools as $school) {
                try {
                    $ok = $tenants->connectTo($school, fn () => $this->reportOn($checker, 'tenant', $school->slug));
                    $anyBroken = ! $ok || $anyBroken;
                } catch (\Throwable $e) {
                    $this->error("Skipping {$school->slug}: {$e->getMessage()}");
                    $anyBroken = true;
                }
            }
        }

        return $anyBroken ? self::FAILURE : self::SUCCESS;
    }

    protected function reportOn(IntegrityChecker $checker, string $connection, string $label): bool
    {
        $result = $checker->verify($connection);

        if ($result['ok']) {
            $this->info("{$label}: OK ({$result['checked']} row(s) verified).");
        } else {
            $this->error("{$label}: BROKEN at audit_logs.id={$result['broken_at_id']} ({$result['reason']}). Checked {$result['checked']} row(s) before stopping.");
        }

        return $result['ok'];
    }
}
