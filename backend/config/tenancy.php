<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant database driver
    |--------------------------------------------------------------------------
    |
    | "sqlite"  — one .sqlite file per school under storage/tenants/ (local dev)
    | "mysql"   — one MySQL database per school, named per "database_prefix"
    |             (production; requires a MySQL user with CREATE DATABASE)
    |
    */

    'driver' => env('TENANCY_DRIVER', env('DB_CONNECTION', 'sqlite')),

    /*
    |--------------------------------------------------------------------------
    | Where tenant SQLite files live (sqlite driver only)
    |--------------------------------------------------------------------------
    */

    'sqlite_path' => storage_path('tenants'),

    /*
    |--------------------------------------------------------------------------
    | MySQL database name prefix (mysql driver only)
    |--------------------------------------------------------------------------
    |
    | A school with slug "greenfield-academy" gets database
    | "{prefix}greenfield_academy".
    |
    */

    'database_prefix' => env('TENANCY_DB_PREFIX', 'skulos_school_'),

    /*
    |--------------------------------------------------------------------------
    | cPanel-API-based database provisioning (mysql driver only)
    |--------------------------------------------------------------------------
    |
    | Set 'enabled' true when the MySQL user configured in
    | config('database.connections.mysql') does not have (and cannot be
    | granted) global CREATE DATABASE privilege — the normal case on
    | shared cPanel hosting, confirmed directly by this project's own
    | host 2026-08-15. TenantManager then creates each school's database
    | through cPanel's UAPI instead of a raw SQL statement. Requires an
    | API token from cPanel > Security > Manage API Tokens.
    |
    | 'database_prefix' above MUST match cPanel's own naming convention
    | (the cPanel username followed by "_", e.g. "skulosap_") when this
    | is enabled, or database creation will be rejected.
    |
    */

    'cpanel' => [
        'enabled' => env('CPANEL_API_ENABLED', false),
        'host' => env('CPANEL_HOST'),
        'port' => env('CPANEL_PORT', 2083),
        'username' => env('CPANEL_USERNAME'),
        'token' => env('CPANEL_API_TOKEN'),
        // The MySQL user (config('database.connections.mysql.username'))
        // is granted privileges on every newly created database — this
        // is deliberately a separate setting rather than always reusing
        // that connection's own username, in case a different, more
        // narrowly scoped MySQL user should be granted per-database
        // access instead of the one the platform's own queries use.
        'db_user' => env('CPANEL_DB_USER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | The runtime connection name used for the active school
    |--------------------------------------------------------------------------
    |
    | Tenant-scoped models set `protected $connection = 'tenant'`. The
    | TenantManager repoints this connection at the active school's database.
    |
    */

    'connection' => 'tenant',

    /*
    |--------------------------------------------------------------------------
    | Tenant migrations path (relative to the backend root)
    |--------------------------------------------------------------------------
    */

    'migrations_path' => 'database/migrations/tenant',

    /*
    |--------------------------------------------------------------------------
    | Header used to resolve the school on API requests
    |--------------------------------------------------------------------------
    |
    | Subdomain resolution is used for web requests; API clients (and the
    | future Next.js frontend) send this header with the school slug instead.
    |
    */

    'header' => 'X-School',
];
