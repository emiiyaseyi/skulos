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

        // Seed the school's default calendar (notable days). Admin can edit,
        // remove, or add more afterwards.
        $this->connectTo($school, function () {
            app(\App\Services\Calendar\CalendarService::class)->seedDefaults();
        });

        $school->forceFill([
            'status' => School::STATUS_ACTIVE,
            'provisioned_at' => now(),
        ])->save();

        return $school;
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
        $slug = Str::of($school->slug)->replace('-', '_')->replaceMatches('/[^a-z0-9_]/', '');

        return $this->driver() === 'sqlite'
            ? (string) $slug
            : config('tenancy.database_prefix').$slug;
    }

    protected function driver(): string
    {
        return config('tenancy.driver');
    }
}
