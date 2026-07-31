<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\AdmissionWindow;
use App\Models\Tenant\Applicant;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Admissions module, Phase 1c: the public "Seek Admission" entry point
 * and self-registration. A guest with no account picks an open window
 * and registers; a real Applicant row is created (no password yet, that
 * only happens once the application fee clears, Phase 1d).
 */
class AdmissionPublicApplyTest extends TestCase
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

    protected function schoolWithOpenWindow(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        $window = AdmissionWindow::create([
            'session_id' => $session->id, 'school_class_id' => $class->id,
            'application_fee_amount' => 5000, 'is_active' => true,
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        app(TenantManager::class)->forget();

        return [$school, $window];
    }

    public function test_a_guest_can_view_and_submit_the_application_form(): void
    {
        [$school, $window] = $this->schoolWithOpenWindow('apone');

        $this->get("http://{$school->slug}.localhost/admissions/apply")
            ->assertOk()->assertSee('Primary 1')->assertSee('5,000.00');

        $response = $this->post("http://{$school->slug}.localhost/admissions/apply", [
            'batch_uuid' => $window->batch_uuid,
            'school_class_id' => $window->school_class_id,
            'first_name' => 'Ada',
            'last_name' => 'Obi',
            'date_of_birth' => '2015-04-12',
            'phone' => '08011112222',
        ]);

        app(TenantManager::class)->use($school);
        $applicant = Applicant::first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($applicant);
        $this->assertSame('Ada Obi', $applicant->name);
        $this->assertSame('Ada', $applicant->first_name);
        $this->assertSame('Obi', $applicant->last_name);
        $this->assertSame('2015-04-12', $applicant->date_of_birth->toDateString());
        $this->assertSame(Applicant::STATUS_PENDING_FEE, $applicant->account_status);
        $this->assertNull($applicant->password, 'no password is issued until the application fee clears');
        $this->assertNotNull($applicant->admission_number);

        // The redirect target is a signed URL (an applicant has no
        // account/password yet at this point, so the signature is the
        // only proof of ownership until credentials exist) — assert it
        // resolves to the right applicant and actually loads, not an
        // exact string match against a URL containing a dynamic
        // signature/expiry.
        $response->assertRedirect();
        $statusUrl = $response->headers->get('Location');
        $this->assertStringContainsString("/admissions/apply/{$applicant->id}/status", $statusUrl);
        $this->assertStringContainsString('signature=', $statusUrl);
        $this->get($statusUrl)->assertOk()->assertSee($applicant->admission_number);
    }

    public function test_the_structured_reference_format_derives_school_and_class_abbreviations(): void
    {
        [$school, $window] = $this->schoolWithOpenWindow('apfive');
        $school->update(['name' => 'Greenfield Academy']);

        app(TenantManager::class)->use($school);
        \App\Models\Tenant\Setting::put('admission_reference_style', 'structured');
        app(TenantManager::class)->forget();

        $this->post("http://{$school->slug}.localhost/admissions/apply", [
            'batch_uuid' => $window->batch_uuid,
            'school_class_id' => $window->school_class_id,
            'first_name' => 'Ada',
            'last_name' => 'Obi',
            'date_of_birth' => '2015-04-12',
            'phone' => '08011112222',
        ]);

        app(TenantManager::class)->use($school);
        $applicant = Applicant::first();
        app(TenantManager::class)->forget();

        $this->assertSame('GA/P1/0001', $applicant->admission_number);
    }

    public function test_the_seek_admission_link_appears_on_the_school_login_page(): void
    {
        [$school] = $this->schoolWithOpenWindow('aptwo');

        $this->get("http://{$school->slug}.localhost/login")
            ->assertOk()->assertSee('Seek Admission');
    }

    public function test_no_open_windows_shows_a_friendly_message_instead_of_a_form(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'apthree']));

        $this->get("http://{$school->slug}.localhost/admissions/apply")
            ->assertOk()->assertSee('No admission window is open right now');
    }

    public function test_a_closed_window_is_not_selectable(): void
    {
        [$school, $window] = $this->schoolWithOpenWindow('apfour');
        app(TenantManager::class)->use($school);
        $window->update(['is_active' => false]);
        app(TenantManager::class)->forget();

        $this->post("http://{$school->slug}.localhost/admissions/apply", [
            'batch_uuid' => $window->batch_uuid,
            'school_class_id' => $window->school_class_id,
            'first_name' => 'Ada',
            'last_name' => 'Obi',
            'date_of_birth' => '2015-04-12',
            'phone' => '08011112222',
        ])->assertSessionHasErrors('batch_uuid');
    }
}
