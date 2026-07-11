<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * Every provisioned school has its own database, migrated once at
 * provisioning time (TenantManager::provision()). A tenant migration
 * added after a school already exists never reaches that school's
 * database on its own, it needs this run against it explicitly (found
 * the hard way 2026-07-10/2026-07-11: `houses.is_active` existed in the
 * new migration file but not in any already-provisioned school's real
 * database, a live 500 on an existing school). Run this after adding
 * any new file under database/migrations/tenant/.
 */
class MigrateTenants extends Command
{
    protected $signature = 'tenants:migrate';

    protected $description = 'Run pending tenant migrations against every provisioned school';

    public function handle(TenantManager $tenants): int
    {
        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')->get();

        if ($schools->isEmpty()) {
            $this->info('No provisioned schools to migrate.');

            return self::SUCCESS;
        }

        foreach ($schools as $school) {
            $this->line("Migrating {$school->slug}...");
            try {
                $tenants->migrate($school);
            } catch (\Throwable $e) {
                $this->error("Failed for {$school->slug}: {$e->getMessage()}");
            }
        }

        $this->info("Done: {$schools->count()} school(s) checked.");

        return self::SUCCESS;
    }
}
