<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-off migration of a school's real data from a local SQLite dev
 * environment into a real, already-provisioned production school
 * (2026-08-15: greenfield, moving from local dev to real cPanel/MySQL
 * hosting, with the explicit ask to "retain everything related to
 * greenfield, its users, login details, settings, etc").
 *
 * Two separate source databases are involved, not one, because login
 * accounts (App\Models\User) live in the shared PLATFORM database
 * (TD-005: one identity across schools), while everything else about
 * a school (students, staff profiles, academic records, settings)
 * lives in that school's own TENANT database. Every tenant table that
 * references a login account by id (`user_id`, `*_user_id` columns —
 * 26 tables found by grep across this project's own tenant migrations)
 * needs that id rewritten, since the destination platform database
 * assigns its own fresh auto-increment ids that will not match the
 * source's.
 *
 * Always run with --dry-run first. It reports exactly what would be
 * imported/remapped without writing anything.
 */
class ImportLegacySchoolData extends Command
{
    protected $signature = 'schools:import-legacy-data
        {slug : Slug of the already-created destination school, e.g. greenfield}
        {platform_sqlite : Absolute path to the source platform.sqlite file}
        {tenant_sqlite : Absolute path to the source tenant sqlite file}
        {--source-slug= : The slug to look up in the SOURCE platform database, if different from the destination slug (defaults to the destination slug)}
        {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Import a real school data set (users, login accounts, every tenant table) from a local SQLite dev copy into an already-provisioned production school.';

    protected bool $dryRun = false;

    /** old platform user id => new platform user id */
    protected array $userIdMap = [];

    public function handle(TenantManager $tenants, Rbac $rbac): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $slug = $this->argument('slug');
        $platformPath = $this->argument('platform_sqlite');
        $tenantPath = $this->argument('tenant_sqlite');

        foreach ([$platformPath, $tenantPath] as $path) {
            if (! is_file($path)) {
                $this->error("File not found: {$path}");

                return self::FAILURE;
            }
        }

        $school = School::where('slug', $slug)->first();
        if (! $school) {
            $this->error("No school with slug '{$slug}' exists yet. Create it first through the admin UI.");

            return self::FAILURE;
        }
        if (! $school->isProvisioned()) {
            $this->error("School '{$slug}' exists but is not provisioned yet.");

            return self::FAILURE;
        }

        $this->registerSourceConnections($platformPath, $tenantPath);

        if ($this->dryRun) {
            $this->warn('DRY RUN — nothing will be written. Re-run without --dry-run once this looks right.');
        }
        $this->newLine();

        $sourceSlug = $this->option('source-slug') ?: $slug;
        $oldSchool = DB::connection('import_platform')->table('schools')->where('slug', $sourceSlug)->first();
        if (! $oldSchool) {
            $this->error("No school with slug '{$sourceSlug}' found in the source platform database.");

            return self::FAILURE;
        }

        $this->info('=== Step 1: Login accounts ===');
        $this->importUsers($oldSchool->id, $school, $rbac);

        $this->newLine();
        $this->info('=== Step 2: Tenant data ===');
        $tenants->use($school);
        $this->importTenantTables();
        $tenants->forget();

        $this->newLine();
        $this->info($this->dryRun ? 'Dry run complete.' : 'Import complete.');

        return self::SUCCESS;
    }

    protected function registerSourceConnections(string $platformPath, string $tenantPath): void
    {
        Config::set('database.connections.import_platform', [
            'driver' => 'sqlite',
            'database' => $platformPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        Config::set('database.connections.import_tenant', [
            'driver' => 'sqlite',
            'database' => $tenantPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    /**
     * Imports every user this school currently has (school_user rows)
     * PLUS every user referenced anywhere in the tenant database that
     * is no longer a current member (e.g. a staff member who has since
     * left, but whose name should still show correctly on old audit
     * log entries / attendance records / approvals) — collected in
     * importTenantTables() via collectReferencedUserIds() and merged
     * in here before the pivot rows are written, so the id map is
     * complete before any tenant table needs it.
     */
    protected function importUsers(int $oldSchoolId, School $newSchool, Rbac $rbac): void
    {
        $memberIds = DB::connection('import_platform')->table('school_user')
            ->where('school_id', $oldSchoolId)->pluck('user_id')->all();

        $referencedIds = $this->collectReferencedUserIds();

        $allOldIds = array_unique(array_merge($memberIds, $referencedIds));
        $this->line(count($allOldIds).' distinct user(s) referenced (including former members / non-members who acted on records).');

        foreach ($allOldIds as $oldId) {
            $this->mapUser((int) $oldId);
        }

        $pivots = DB::connection('import_platform')->table('school_user')
            ->where('school_id', $oldSchoolId)->get();

        $this->line(count($pivots).' current membership row(s) to recreate.');

        $roleCache = [];
        foreach ($pivots as $pivot) {
            $newUserId = $this->userIdMap[$pivot->user_id] ?? null;
            if (! $newUserId) {
                $this->warn("  Skipping membership for old user #{$pivot->user_id} — could not be imported.");

                continue;
            }

            $oldRole = DB::connection('import_platform')->table('roles')->where('id', $pivot->role_id)->first();
            if (! $oldRole) {
                $this->warn("  Skipping membership for user #{$newUserId} — its old role (#{$pivot->role_id}) no longer exists in the source.");

                continue;
            }

            $cacheKey = $oldRole->slug;
            if (! isset($roleCache[$cacheKey])) {
                // Template roles are cloned into a school on demand (see
                // Rbac::cloneTemplateRole()), so a role this school never
                // happened to use yet may not exist here as a school-scoped
                // row until we ask for it explicitly.
                $newRole = Role::where('school_id', $newSchool->id)->where('slug', $oldRole->slug)->first();
                if (! $newRole) {
                    $newRole = $this->dryRun ? null : $rbac->cloneTemplateRole($newSchool, $oldRole->slug);
                }
                $roleCache[$cacheKey] = $newRole;
            }
            $newRole = $roleCache[$cacheKey];

            if (! $newRole && ! $this->dryRun) {
                $this->warn("  Skipping membership for user #{$newUserId} — role '{$oldRole->slug}' could not be resolved.");

                continue;
            }

            if ($this->dryRun) {
                $this->line("  Would attach user #{$newUserId} to {$newSchool->slug} as '{$oldRole->slug}' (status: {$pivot->status}).");

                continue;
            }

            DB::table('school_user')->updateOrInsert(
                ['user_id' => $newUserId, 'school_id' => $newSchool->id],
                ['role_id' => $newRole->id, 'status' => $pivot->status, 'created_at' => $pivot->created_at, 'updated_at' => now()]
            );
        }
    }

    /**
     * Ensures a mapping exists for one old user id, reusing an
     * already-existing production account by email (the same
     * "reuse an existing account" reasoning AccountProvisioningService
     * already relies on elsewhere in this app — a real person's account
     * must never be duplicated), otherwise creating a fresh one.
     * is_super_admin is deliberately never copied from source — that
     * flag is never something an import should be able to grant.
     */
    protected function mapUser(int $oldUserId): void
    {
        if (isset($this->userIdMap[$oldUserId])) {
            return;
        }

        $oldUser = DB::connection('import_platform')->table('users')->where('id', $oldUserId)->first();
        if (! $oldUser) {
            $this->warn("  Old user #{$oldUserId} referenced in tenant data but not found in the source platform database — will be left null on import.");

            return;
        }

        $existing = User::where('email', $oldUser->email)->first();
        if ($existing) {
            $this->userIdMap[$oldUserId] = $existing->id;
            $this->line("  {$oldUser->email}: reusing existing production account (#{$existing->id}).");

            return;
        }

        if ($this->dryRun) {
            $this->line("  {$oldUser->email}: would create a new production account.");
            $this->userIdMap[$oldUserId] = -1; // placeholder so dry-run counts/logic below still work

            return;
        }

        $newId = DB::table('users')->insertGetId([
            'name' => $oldUser->name,
            'email' => $oldUser->email,
            'phone' => $oldUser->phone,
            'password' => $oldUser->password,
            'email_verified_at' => $oldUser->email_verified_at,
            'must_change_password' => $oldUser->must_change_password ?? false,
            'is_super_admin' => false,
            'remember_token' => null,
            'created_at' => $oldUser->created_at,
            'updated_at' => now(),
        ]);
        $this->userIdMap[$oldUserId] = $newId;
        $this->line("  {$oldUser->email}: created new production account (#{$newId}).");
    }

    /**
     * Scans every tenant table for a "user_id" or "*_user_id" column
     * (the exact naming convention every one of the 26 real tenant
     * tables with such a reference already uses) and returns the
     * distinct set of non-null values found — this is the "referenced
     * but maybe no longer a current member" half of importUsers()'s
     * user set.
     */
    protected function collectReferencedUserIds(): array
    {
        $ids = [];
        foreach ($this->tenantTables() as $table) {
            $columns = $this->tenantTableColumns($table);
            $userColumns = array_filter($columns, fn ($c) => $c === 'user_id' || str_ends_with($c, '_user_id'));

            foreach ($userColumns as $column) {
                $values = DB::connection('import_tenant')->table($table->name)
                    ->whereNotNull($column)->distinct()->pluck($column)->all();
                $ids = array_merge($ids, $values);
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    protected function tenantTables(): array
    {
        return DB::connection('import_tenant')
            ->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT IN ('migrations', 'sqlite_sequence')");
    }

    protected function tenantTableColumns(object|string $table): array
    {
        $name = is_object($table) ? $table->name : $table;

        return collect(DB::connection('import_tenant')->select("PRAGMA table_info(\"{$name}\")"))
            ->pluck('name')->all();
    }

    /**
     * Copies every tenant table's data as-is EXCEPT user-id columns,
     * which are rewritten through $this->userIdMap. The destination
     * table is truncated first — a freshly provisioned school already
     * has a small amount of auto-seeded starter data (this year's and
     * next year's calendar defaults, see CalendarService::seedDefaults(),
     * called from TenantManager::provision()) which would otherwise
     * collide on primary key ids with the real imported rows; that
     * seeded data is reproducible boilerplate, not real records, so
     * discarding it in favor of the real imported history is correct.
     * Foreign key checks are disabled for the whole operation so every
     * table can be copied in any order rather than needing to compute
     * a correct dependency order across dozens of tables by hand.
     */
    protected function importTenantTables(): void
    {
        $mysql = config('tenancy.driver') === 'mysql';

        if (! $this->dryRun) {
            DB::connection('tenant')->statement($mysql ? 'SET FOREIGN_KEY_CHECKS=0' : 'PRAGMA foreign_keys = OFF');
        }

        foreach ($this->tenantTables() as $table) {
            $name = $table->name;
            $columns = $this->tenantTableColumns($name);
            $userColumns = array_values(array_filter($columns, fn ($c) => $c === 'user_id' || str_ends_with($c, '_user_id')));

            // ->count() only, never ->get(): some tenant tables (audit
            // logs, notifications) can hold tens of thousands of rows
            // after months of real use — loading a whole table into
            // memory just to report its size exhausted PHP's memory
            // limit during local testing, caught before ever trying
            // this against production's much larger real data.
            $rowCount = DB::connection('import_tenant')->table($name)->count();
            if ($rowCount === 0) {
                continue;
            }

            $this->line("{$name}: {$rowCount} row(s)".($userColumns ? ' (remapping: '.implode(', ', $userColumns).')' : ''));

            if ($this->dryRun) {
                continue;
            }

            DB::connection('tenant')->table($name)->truncate();

            // ->orderBy('id')->lazy(500): streams the source table in
            // pages rather than loading it all at once, same reasoning
            // as the ->count() change above.
            DB::connection('import_tenant')->table($name)->orderBy('id')->lazy(500)
                ->chunk(500)
                ->each(function ($chunk) use ($name, $userColumns) {
                    $batch = $chunk->map(function ($row) use ($userColumns) {
                        $row = (array) $row;
                        foreach ($userColumns as $column) {
                            if ($row[$column] !== null) {
                                $row[$column] = $this->userIdMap[$row[$column]] ?? null;
                            }
                        }

                        return $row;
                    })->all();

                    DB::connection('tenant')->table($name)->insert($batch);
                });

            if ($mysql) {
                $maxId = DB::connection('tenant')->table($name)->max('id');
                if ($maxId) {
                    DB::connection('tenant')->statement("ALTER TABLE `{$name}` AUTO_INCREMENT = ".((int) $maxId + 1));
                }
            }
        }

        if (! $this->dryRun) {
            DB::connection('tenant')->statement($mysql ? 'SET FOREIGN_KEY_CHECKS=1' : 'PRAGMA foreign_keys = ON');
        }
    }
}
