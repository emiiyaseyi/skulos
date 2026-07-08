<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\Tenant\Setting;
use App\Services\Bulk\BulkActionRunner;
use App\Services\Bulk\CsvReader;
use App\Services\Bulk\Importer;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class SettingsTestImporter extends Importer
{
    public function columns(): array
    {
        return ['key' => 'Setting Key', 'value' => 'Value'];
    }

    protected function rules(array $row): array
    {
        return [
            'key' => ['required', 'string', 'max:100'],
            'value' => ['required'],
        ];
    }

    protected function uniqueBy(): array
    {
        return ['key'];
    }

    protected function persist(array $row): void
    {
        Setting::put($row['key'], $row['value']);
    }

    protected function auditName(): string
    {
        return 'settings';
    }
}

class BulkFrameworkTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);

        $this->school = app(TenantManager::class)->provision(
            School::factory()->create(['slug' => 'bulk-school'])
        );
        app(TenantManager::class)->use($this->school);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);

        parent::tearDown();
    }

    public function test_csv_reader_handles_bom_blank_lines_and_trailing_columns(): void
    {
        $csv = "\xEF\xBB\xBFkey,value\n\nmotto,Excellence,\n  , \nterm_count,3\n";

        $rows = app(CsvReader::class)->rows($csv);

        $this->assertSame([
            ['key' => 'motto', 'value' => 'Excellence'],
            ['key' => 'term_count', 'value' => '3'],
        ], $rows);
    }

    public function test_template_is_just_the_header_row(): void
    {
        $this->assertSame("key,value\n", (new SettingsTestImporter)->template());
    }

    public function test_strict_mode_writes_nothing_when_any_row_fails(): void
    {
        $rows = [
            ['key' => 'motto', 'value' => 'Excellence'],
            ['key' => null, 'value' => 'orphan'], // invalid: key required
        ];

        $result = (new SettingsTestImporter)->import($rows, Importer::MODE_STRICT);

        $this->assertSame(0, $result->imported);
        $this->assertSame(1, $result->failed());
        // Header is row 1, so the second data row is spreadsheet row 3.
        $this->assertSame(3, $result->errors[0]['row']);
        $this->assertArrayHasKey('key', $result->errors[0]['errors']);
        $this->assertSame(0, Setting::count());
    }

    public function test_partial_mode_imports_valid_rows_and_reports_the_rest(): void
    {
        $rows = [
            ['key' => 'motto', 'value' => 'Excellence'],
            ['key' => null, 'value' => 'orphan'],
            ['key' => 'term_count', 'value' => '3'],
        ];

        $result = (new SettingsTestImporter)->import($rows, Importer::MODE_PARTIAL);

        $this->assertSame(2, $result->imported);
        $this->assertSame(1, $result->failed());
        $this->assertSame(2, Setting::count());
        $this->assertSame('Excellence', Setting::grab('motto'));
    }

    public function test_in_file_duplicates_are_flagged_per_row(): void
    {
        $rows = [
            ['key' => 'motto', 'value' => 'One'],
            ['key' => 'MOTTO', 'value' => 'Two'], // duplicate, case-insensitive
        ];

        $result = (new SettingsTestImporter)->import($rows, Importer::MODE_STRICT);

        $this->assertSame(0, $result->imported);
        $this->assertSame(2, $result->failed());
        $this->assertStringContainsString('more than once', $result->errors[0]['errors']['key'][0]);
    }

    public function test_imports_are_audited_in_the_tenant_database(): void
    {
        (new SettingsTestImporter)->import([
            ['key' => 'motto', 'value' => 'Excellence'],
        ]);

        $log = AuditLog::on('tenant')->where('action', 'bulk.import.settings')->first();

        $this->assertNotNull($log);
        $this->assertSame(1, $log->new_values['imported']);
    }

    public function test_bulk_action_runner_collects_per_id_outcomes_and_audits(): void
    {
        Setting::put('a', 1);
        Setting::put('b', 2);
        Setting::put('c', 3);

        $outcome = app(BulkActionRunner::class)->run('settings.delete', ['a', 'b', 'c'], function ($key) {
            if ($key === 'b') {
                throw new RuntimeException('b is protected');
            }
            Setting::where('key', $key)->firstOrFail()->delete();
        });

        $this->assertSame(['a', 'c'], $outcome['succeeded']);
        $this->assertSame(['b' => 'b is protected'], $outcome['failed']);
        $this->assertSame(1, Setting::count());

        $log = AuditLog::on('tenant')->where('action', 'bulk.settings.delete')->first();
        $this->assertNotNull($log);
        $this->assertSame(2, $log->new_values['succeeded']);
        $this->assertSame(['b'], $log->new_values['ids_failed']);
    }
}
