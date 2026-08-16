<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Student;
use App\Services\Bulk\Importers\StudentImporter;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Preview-before-import (2026-08-16, direct instruction: show the good
 * and bad rows and let the admin decide, rather than importing on
 * upload and reporting afterwards when it is too late to change their
 * mind).
 *
 * These cover the three things that make the preview worth trusting:
 * nothing is written by looking, tidy-ups are real but conservative,
 * and a row that clashes with someone already on record is flagged as
 * a duplicate rather than buried among validation errors.
 */
class ImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function school(): School
    {
        return app(TenantManager::class)->provision(School::factory()->create(['slug' => 'prev']));
    }

    public function test_previewing_writes_nothing(): void
    {
        $school = $this->school();

        app(TenantManager::class)->connectTo($school, function () {
            $preview = (new StudentImporter)->preview([
                ['admission_number' => 'P/001', 'first_name' => 'Ada', 'last_name' => 'Okoro'],
            ]);

            $this->assertCount(1, $preview['good']);
            // The whole promise of a preview: looking changes nothing.
            $this->assertSame(0, Student::count(), 'Previewing created a student.');
        });
    }

    public function test_messy_spacing_and_invisible_characters_are_tidied_and_reported(): void
    {
        $school = $this->school();

        app(TenantManager::class)->connectTo($school, function () {
            $preview = (new StudentImporter)->preview([[
                // Trailing space, doubled internal space, a non-breaking
                // space and a zero-width space -- all of which arrive in
                // real spreadsheets pasted from Word or a website.
                'admission_number' => ' P/002 ',
                'first_name' => "Ada\u{00A0}Grace",
                'last_name' => "Okoro\u{200B}",
            ]]);

            $entry = $preview['good'][0];
            $this->assertSame('P/002', $entry['values']['admission_number']);
            $this->assertSame('Ada Grace', $entry['values']['first_name']);
            $this->assertSame('Okoro', $entry['values']['last_name']);
            // Reported, not silently done, so the admin can see it.
            $this->assertContains('first_name', $entry['changed']);
        });
    }

    public function test_capitalisation_and_spelling_are_never_touched(): void
    {
        $school = $this->school();

        app(TenantManager::class)->connectTo($school, function () {
            $preview = (new StudentImporter)->preview([[
                'admission_number' => 'P/003', 'first_name' => 'McDonald', 'last_name' => "O'Brien",
            ]]);

            // "Correcting" a name is how McDonald becomes Mcdonald
            // against a family's wishes.
            $this->assertSame('McDonald', $preview['good'][0]['values']['first_name']);
            $this->assertSame("O'Brien", $preview['good'][0]['values']['last_name']);
        });
    }

    public function test_a_student_already_on_record_is_flagged_as_a_duplicate(): void
    {
        $school = $this->school();

        app(TenantManager::class)->connectTo($school, function () {
            Student::create([
                'admission_number' => 'P/100', 'first_name' => 'Existing',
                'last_name' => 'Student', 'gender' => 'Female', 'status' => Student::STATUS_ACTIVE,
            ]);

            $preview = (new StudentImporter)->preview([
                ['admission_number' => 'P/100', 'first_name' => 'Ada', 'last_name' => 'Okoro'],
            ]);

            $this->assertCount(0, $preview['good']);
            $this->assertCount(1, $preview['bad']);
            // Called out as a duplicate specifically: "already on
            // record" is a different decision for an admin than "this
            // row is malformed".
            $this->assertNotEmpty($preview['bad'][0]['already_on_record']);
        });
    }

    public function test_the_same_identifier_twice_in_one_file_is_flagged(): void
    {
        $school = $this->school();

        app(TenantManager::class)->connectTo($school, function () {
            $preview = (new StudentImporter)->preview([
                ['admission_number' => 'P/200', 'first_name' => 'Ada', 'last_name' => 'One'],
                ['admission_number' => 'P/200', 'first_name' => 'Bola', 'last_name' => 'Two'],
            ]);

            $this->assertCount(0, $preview['good']);
            $this->assertCount(2, $preview['bad']);
            $this->assertNotEmpty($preview['bad'][0]['duplicate_in_file']);
        });
    }
}
