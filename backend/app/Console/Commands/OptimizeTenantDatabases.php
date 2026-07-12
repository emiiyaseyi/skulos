<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Maintenance\MaintenanceActionsService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * Weekly scheduled MySQL maintenance per school database (Dbase
 * recommendation: "optimize/analyze/repair tables"). Registered weekly in
 * routes/console.php. The actual per-school work lives in
 * MaintenanceActionsService::optimize(), shared with the Maintenance
 * Center's "Optimize now" button.
 */
class OptimizeTenantDatabases extends Command
{
    protected $signature = 'skulos:optimize-tables';

    protected $description = "Run weekly database maintenance (OPTIMIZE TABLE on MySQL, VACUUM on SQLite) against every school's own database";

    public function handle(TenantManager $tenants, MaintenanceActionsService $actions): int
    {
        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')->get();

        foreach ($schools as $school) {
            try {
                $tenants->use($school);
            } catch (\Throwable $e) {
                $this->error("Skipping {$school->slug}: {$e->getMessage()}");

                continue;
            }

            $result = $actions->optimize($school);
            $tenants->forget();

            $this->info($result['driver'] === 'mysql'
                ? "{$school->slug}: optimized {$result['tables_touched']} table(s)."
                : "{$school->slug}: vacuumed ({$result['driver']}, local dev stand in).");
        }

        return self::SUCCESS;
    }
}
