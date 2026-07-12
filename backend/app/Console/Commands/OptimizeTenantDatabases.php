<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly scheduled MySQL maintenance per school database (Dbase
 * recommendation: "optimize/analyze/repair tables"). Registered weekly in
 * routes/console.php. On MySQL this runs a real OPTIMIZE TABLE per table;
 * on SQLite (local dev) it runs VACUUM instead, since OPTIMIZE TABLE is a
 * MySQL-only statement — this keeps local dev harmless rather than erroring
 * on every run, without pretending SQLite needed the same treatment.
 */
class OptimizeTenantDatabases extends Command
{
    protected $signature = 'skulos:optimize-tables';

    protected $description = "Run weekly database maintenance (OPTIMIZE TABLE on MySQL, VACUUM on SQLite) against every school's own database";

    public function handle(TenantManager $tenants): int
    {
        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')->get();

        foreach ($schools as $school) {
            try {
                $tenants->use($school);
            } catch (\Throwable $e) {
                $this->error("Skipping {$school->slug}: {$e->getMessage()}");

                continue;
            }

            $driver = DB::connection('tenant')->getDriverName();

            if ($driver === 'mysql') {
                $tables = Schema::connection('tenant')->getTableListing();
                foreach ($tables as $table) {
                    $bareName = \Illuminate\Support\Str::afterLast($table, '.');
                    DB::connection('tenant')->statement("OPTIMIZE TABLE `{$bareName}`");
                }
                $this->info("{$school->slug}: optimized ".count($tables).' table(s).');
            } elseif ($driver === 'sqlite') {
                DB::connection('tenant')->statement('VACUUM');
                $this->info("{$school->slug}: vacuumed (sqlite, local dev stand in).");
            }

            $tenants->forget();
        }

        return self::SUCCESS;
    }
}
