<?php

namespace Tests\Feature;

use App\Services\Tenancy\CpanelDatabaseProvisioner;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * cPanel's UAPI, not a raw SQL statement, is what actually creates a
 * school's MySQL database on this project's own production host (see
 * config/tenancy.php's docblock — a real, confirmed shared-hosting
 * limitation, 2026-08-15). Http::fake() throughout, no real MySQL or
 * cPanel connection needed.
 */
class CpanelDatabaseProvisionerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'tenancy.cpanel.host' => 'server.example.com',
            'tenancy.cpanel.port' => 2083,
            'tenancy.cpanel.username' => 'demoacct',
            'tenancy.cpanel.token' => 'test-token',
        ]);
    }

    public function test_create_database_skips_an_already_existing_database(): void
    {
        Http::fake([
            '*Mysql/list_databases*' => Http::response([
                'status' => 1,
                'data' => [['database' => 'demoacct_greenfield'], ['database' => 'demoacct_other']],
            ]),
        ]);

        app(CpanelDatabaseProvisioner::class)->createDatabase('demoacct_greenfield');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'create_database'));
    }

    public function test_create_database_calls_the_real_create_endpoint_when_missing(): void
    {
        Http::fake([
            '*Mysql/list_databases*' => Http::response(['status' => 1, 'data' => []]),
            '*Mysql/create_database*' => Http::response(['status' => 1]),
        ]);

        app(CpanelDatabaseProvisioner::class)->createDatabase('demoacct_newschool');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'create_database')
                && $request['name'] === 'demoacct_newschool'
                && $request->hasHeader('Authorization', 'cpanel demoacct:test-token');
        });
    }

    public function test_a_failed_cpanel_response_throws_with_the_real_error_message(): void
    {
        Http::fake([
            '*Mysql/list_databases*' => Http::response(['status' => 1, 'data' => []]),
            '*Mysql/create_database*' => Http::response(['status' => 0, 'errors' => ['Quota exceeded.']]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Quota exceeded.');

        app(CpanelDatabaseProvisioner::class)->createDatabase('demoacct_newschool');
    }

    public function test_grant_privileges_calls_the_real_endpoint_with_the_right_params(): void
    {
        Http::fake(['*Mysql/set_privileges_on_database*' => Http::response(['status' => 1])]);

        app(CpanelDatabaseProvisioner::class)->grantPrivileges('demoacct_greenfield', 'demoacct_dbuser');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'set_privileges_on_database')
                && $request['database'] === 'demoacct_greenfield'
                && $request['user'] === 'demoacct_dbuser'
                && $request['privileges'] === 'ALL PRIVILEGES';
        });
    }

    public function test_missing_config_throws_a_clear_error_instead_of_a_confusing_http_failure(): void
    {
        config(['tenancy.cpanel.token' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tenancy.cpanel.token');

        app(CpanelDatabaseProvisioner::class)->createDatabase('demoacct_greenfield');
    }
}
