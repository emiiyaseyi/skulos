<?php

namespace App\Services\Tenancy;

use App\Models\School;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Hybrid multi-tenancy (tracker TD-004): one shared platform database plus
 * one dedicated database per school. Locally the tenant driver is SQLite
 * (a file per school under storage/tenants); in production it is MySQL
 * (a database per school). Everything here goes through the query builder /
 * schema builder so both drivers behave identically.
 */
class TenantManager
{
    protected ?School $current = null;

    /**
     * Create the school's database, run tenant migrations, and mark it active.
     */
    public function provision(School $school): School
    {
        if ($school->isProvisioned()) {
            return $school;
        }

        $database = $this->databaseNameFor($school);

        if ($this->driver() === 'sqlite') {
            $dir = config('tenancy.sqlite_path');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $path = $dir.DIRECTORY_SEPARATOR.$database.'.sqlite';
            if (! file_exists($path)) {
                touch($path);
            }
        } elseif (config('tenancy.cpanel.enabled')) {
            // Shared cPanel hosting: the app's own MySQL user typically
            // cannot be granted global CREATE DATABASE privilege (see
            // CpanelDatabaseProvisioner's own docblock), so the database
            // is created through cPanel's UAPI instead of raw SQL.
            $provisioner = app(CpanelDatabaseProvisioner::class);
            $provisioner->createDatabase($database);
            $provisioner->grantPrivileges($database, config('tenancy.cpanel.db_user'));
        } else {
            // MySQL: create the schema itself. Name is derived from the slug
            // (sanitized to [a-z0-9_]) — never from raw user input.
            DB::statement(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $database
            ));
        }

        $school->forceFill(['database' => $database])->save();

        $this->migrate($school);

        // Seed the school's default calendar (notable days and public
        // holidays) for this year and next, so a school provisioned late
        // in the year still has next year's calendar ready on day one
        // (user directive 2026-07-15: "ALWAYS DO IT FOR EVERY YEAR GOING
        // FORWARD TOO"). Admin can edit, remove, or add more afterwards.
        $this->connectTo($school, function () {
            $calendar = app(\App\Services\Calendar\CalendarService::class);
            $calendar->seedDefaults(now()->year);
            $calendar->seedDefaults(now()->year + 1);
        });

        $school->forceFill([
            'status' => School::STATUS_ACTIVE,
            'provisioned_at' => now(),
        ])->save();

        return $school;
    }

    /**
     * Undo a provisioning attempt that failed partway (2026-08-15).
     * Before this, a school whose database could not be created or
     * migrated was left as a real row stuck at "pending", holding its
     * slug so the same address could not be tried again, with a
     * possibly half-migrated database behind it — an admin had to
     * notice and delete it by hand (which is exactly what happened live
     * when CREATE DATABASE was denied on shared hosting).
     *
     * Only ever called on a provisioning failure, where the school by
     * definition has no real data yet, so removing it destroys nothing
     * a user entered. Cleanup failures are logged and swallowed: the
     * caller's own error about why provisioning failed is the useful
     * one, and must not be replaced by a secondary error from tidying
     * up after it.
     */
    public function discardFailedProvision(School $school): void
    {
        $database = $school->database;

        try {
            if ($database) {
                if ($this->driver() === 'sqlite') {
                    $path = config('tenancy.sqlite_path').DIRECTORY_SEPARATOR.$database.'.sqlite';
                    if (file_exists($path)) {
                        unlink($path);
                    }
                } elseif (config('tenancy.cpanel.enabled')) {
                    app(CpanelDatabaseProvisioner::class)->deleteDatabase($database);
                } else {
                    DB::statement(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Could not drop the database for failed provision of [{$school->slug}]: ".$e->getMessage());
        }

        try {
            $school->forceDelete();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Could not remove the school row for failed provision of [{$school->slug}]: ".$e->getMessage());
        }
    }

    /**
     * Run (or re-run) tenant migrations for one school.
     */
    public function migrate(School $school): void
    {
        $this->connectTo($school, function () {
            Artisan::call('migrate', [
                '--database' => config('tenancy.connection'),
                '--path' => config('tenancy.migrations_path'),
                '--force' => true,
            ]);
        });
    }

    /**
     * Point the "tenant" connection at this school and make it current.
     */
    public function use(School $school): void
    {
        if (! $school->isProvisioned()) {
            throw new RuntimeException("School [{$school->slug}] has not been provisioned.");
        }

        $this->configureConnection($school);
        $this->current = $school;
    }

    /**
     * Run a callback against a school's database, then restore whatever
     * school (or none) was current before.
     */
    public function connectTo(School $school, callable $callback): mixed
    {
        $previous = $this->current;

        $this->configureConnection($school);
        $this->current = $school;

        try {
            return $callback();
        } finally {
            if ($previous) {
                $this->configureConnection($previous);
            } else {
                DB::purge(config('tenancy.connection'));
            }
            $this->current = $previous;
        }
    }

    public function current(): ?School
    {
        return $this->current;
    }

    public function forget(): void
    {
        DB::purge(config('tenancy.connection'));
        $this->current = null;
    }

    protected function configureConnection(School $school): void
    {
        $name = config('tenancy.connection');

        if ($this->driver() === 'sqlite') {
            $config = [
                'driver' => 'sqlite',
                'database' => config('tenancy.sqlite_path').DIRECTORY_SEPARATOR.$school->database.'.sqlite',
                'prefix' => '',
                'foreign_key_constraints' => true,
                // Same WAL + NORMAL pairing as the platform connection's
                // own config('database.connections.sqlite') — see that
                // config entry's docblock for why. A real local-dev
                // performance finding, not a production concern (this
                // branch never runs against MySQL).
                'journal_mode' => env('DB_SQLITE_JOURNAL_MODE', 'wal'),
                'synchronous' => env('DB_SQLITE_SYNCHRONOUS', 'normal'),
            ];
        } else {
            $config = array_merge(config('database.connections.mysql'), [
                'database' => $school->database,
            ]);
        }

        Config::set("database.connections.{$name}", $config);
        DB::purge($name);
    }

    protected function databaseNameFor(School $school): string
    {
        // Lowercase before stripping, not just stripping (found live
        // 2026-07-12: a slug that reached here with an uppercase letter,
        // e.g. "Training", had that letter silently deleted rather than
        // lowercased, producing a database named "raining"). The slug
        // itself should already be lowercased at creation (see
        // SchoolAdminController::store()), this is a second layer so a
        // stray mixed-case slug from any other path never corrupts the
        // database name the same way again.
        $slug = Str::of($school->slug)->lower()->replace('-', '_')->replaceMatches('/[^a-z0-9_]/', '');

        return $this->driver() === 'sqlite'
            ? (string) $slug
            : config('tenancy.database_prefix').$slug;
    }

    protected function driver(): string
    {
        return config('tenancy.driver');
    }
}
