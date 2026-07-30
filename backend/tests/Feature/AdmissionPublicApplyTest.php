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
            'admission_window_id' => $window->id,
            'name' => 'Ada Obi',
            'phone' => '08011112222',
        ]);

        app(TenantManager::class)->use($school);
        $applicant = Applicant::first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($applicant);
        $this->assertSame('Ada Obi', $applicant->name);
        $this->assertSame(Applicant::STATUS_PENDING_FEE, $applicant->account_status);
        $this->assertNull($applicant->password, 'no password is issued until the application fee clears');
        $this->assertNotNull($applicant->admission_number);

        $response->assertRedirect("http://{$school->slug}.localhost/admissions/apply/submitted/{$applicant->id}");
        $this->get($response->headers->get('Location'))->assertOk()->assertSee($applicant->admission_number);
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
            ->assertOk()->assertSee('not currently open');
    }

    public function test_a_closed_window_is_not_selectable(): void
    {
        [$school, $window] = $this->schoolWithOpenWindow('apfour');
        app(TenantManager::class)->use($school);
        $window->update(['is_active' => false]);
        app(TenantManager::class)->forget();

        $this->post("http://{$school->slug}.localhost/admissions/apply", [
            'admission_window_id' => $window->id,
            'name' => 'Ada Obi',
            'phone' => '08011112222',
        ])->assertSessionHasErrors('admission_window_id');
    }
}
