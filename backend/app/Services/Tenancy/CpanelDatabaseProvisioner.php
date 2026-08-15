<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Creates a school's MySQL database via cPanel's own UAPI instead of a
 * raw `CREATE DATABASE` SQL statement (2026-08-15). A real limitation
 * confirmed directly by the hosting provider on this project's own
 * shared cPanel plan: even an "unlimited databases" account's MySQL
 * user is never granted global CREATE privilege via a raw SQL GRANT —
 * databases are only ever created through cPanel's own tooling, which
 * UAPI exposes programmatically. Only used when TenantManager's mysql
 * driver AND config('tenancy.cpanel.enabled') are both true; otherwise
 * TenantManager falls back to its original raw `CREATE DATABASE IF NOT
 * EXISTS` statement, still correct for a host that does allow it (a
 * VPS with a fully privileged MySQL user, for example).
 *
 * Database and MySQL-user names on cPanel are always prefixed with the
 * account's own cPanel username — TenantManager's own
 * `tenancy.database_prefix` config must be set to match (e.g.
 * "skulosap_") for names this class creates to be accepted at all.
 */
class CpanelDatabaseProvisioner
{
    /**
     * Idempotent, matching the semantics of the raw SQL statement this
     * replaces ("CREATE DATABASE IF NOT EXISTS") — checks first rather
     * than relying on cPanel's own error message for "already exists",
     * since that message text is not a stable contract to parse.
     */
    public function createDatabase(string $databaseName): void
    {
        $existing = $this->call('Mysql/list_databases');
        $names = collect($existing['data'] ?? [])->pluck('database')->all();

        if (in_array($databaseName, $names, true)) {
            return;
        }

        $this->call('Mysql/create_database', ['name' => $databaseName]);
    }

    public function grantPrivileges(string $databaseName, string $dbUser): void
    {
        $this->call('Mysql/set_privileges_on_database', [
            'user' => $dbUser,
            'database' => $databaseName,
            'privileges' => 'ALL PRIVILEGES',
        ]);
    }

    protected function call(string $function, array $params = []): array
    {
        $config = config('tenancy.cpanel');

        foreach (['host', 'username', 'token'] as $required) {
            if (empty($config[$required])) {
                throw new RuntimeException("cPanel API provisioning is enabled but config('tenancy.cpanel.{$required}') is not set.");
            }
        }

        $url = "https://{$config['host']}:{$config['port']}/execute/{$function}";

        $response = Http::timeout(20)
            ->withHeaders(['Authorization' => "cpanel {$config['username']}:{$config['token']}"])
            ->get($url, $params);

        $body = $response->json() ?? [];

        if (! $response->successful() || (int) ($body['status'] ?? 0) !== 1) {
            $errors = implode('; ', (array) ($body['errors'] ?? ['Unknown cPanel API error']));

            throw new RuntimeException("cPanel API call to {$function} failed: {$errors}");
        }

        return $body;
    }
}
