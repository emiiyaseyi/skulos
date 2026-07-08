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
