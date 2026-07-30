<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Applicant;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Admissions module, Phase 1a (user directive 2026-07-30): an applicant
 * signs in with their admission number or parent phone number, separate
 * from the platform's normal login, and is locked out of the dashboard
 * once their temporary application window (default 30 days) lapses.
 */
class AdmissionApplicantAuthTest extends TestCase
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

    protected function schoolWithApplicant(string $slug, array $overrides = []): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        app(TenantManager::class)->use($school);
        // forceCreate(): password/admission_number/account_status/
        // expires_at are deliberately NOT in Applicant::$fillable (same
        // reasoning as Payment's own status/verified_at exclusion), a
        // real public applicant can never mass-assign their own account
        // status. Matches the established test convention for that
        // model (Payment::forceCreate() in FinanceTest).
        $applicant = Applicant::forceCreate(array_merge([
            'name' => 'Chidinma Okafor',
            'phone' => '08012345678',
            'admission_number' => 'APP-0001',
            'password' => Hash::make('secret123'),
            'account_status' => Applicant::STATUS_ACTIVE,
            'expires_at' => now()->addDays(30),
        ], $overrides));
        app(TenantManager::class)->forget();

        return [$school, $applicant];
    }

    public function test_an_applicant_can_sign_in_with_their_admission_number(): void
    {
        [$school] = $this->schoolWithApplicant('admone');

        $this->post("http://{$school->slug}.localhost/admissions/login", [
            'identifier' => 'APP-0001',
            'password' => 'secret123',
        ])->assertRedirect("http://{$school->slug}.localhost/admissions/dashboard");

        $this->get("http://{$school->slug}.localhost/admissions/dashboard")->assertOk()->assertSee('Chidinma Okafor');
    }

    public function test_an_applicant_can_sign_in_with_their_parent_phone_number(): void
    {
        [$school] = $this->schoolWithApplicant('admtwo');

        $this->post("http://{$school->slug}.localhost/admissions/login", [
            'identifier' => '08012345678',
            'password' => 'secret123',
        ])->assertRedirect("http://{$school->slug}.localhost/admissions/dashboard");
    }

    public function test_wrong_password_is_rejected(): void
    {
        [$school] = $this->schoolWithApplicant('admthree');

        $this->post("http://{$school->slug}.localhost/admissions/login", [
            'identifier' => 'APP-0001',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('identifier');
    }

    /**
     * The window lapsing WHILE a session is already open (not at login
     * time, an expired applicant can never log in at all in the first
     * place, see the "wrong password"-style rejection above) is the real
     * scenario EnsureApplicantActive exists for: still holding a valid
     * session cookie from before expiry, but their window has since
     * closed.
     */
    public function test_an_applicants_session_is_cut_off_and_the_account_archived_once_their_window_lapses_mid_session(): void
    {
        [$school, $applicant] = $this->schoolWithApplicant('admfour');

        $this->post("http://{$school->slug}.localhost/admissions/login", [
            'identifier' => 'APP-0001',
            'password' => 'secret123',
        ])->assertRedirect("http://{$school->slug}.localhost/admissions/dashboard");

        app(TenantManager::class)->use($school);
        $applicant->forceFill(['expires_at' => now()->subDay()])->save();
        app(TenantManager::class)->forget();

        // Auth::guard('applicant') caches its resolved user for the life
        // of the guard instance; within one test method that instance
        // survives across sequential $this->get()/post() calls (unlike a
        // real request, which is always a fresh PHP process). Forgetting
        // it here is what actually simulates "a later, separate request",
        // not a change the real middleware needs.
        app('auth')->forgetGuards();

        $this->get("http://{$school->slug}.localhost/admissions/dashboard")
            ->assertRedirect("http://{$school->slug}.localhost/admissions/login");

        app(TenantManager::class)->use($school);
        $this->assertSame(Applicant::STATUS_ARCHIVED, $applicant->fresh()->account_status);
        app(TenantManager::class)->forget();

        // The session was invalidated, a further request must not still read as authenticated.
        $this->get("http://{$school->slug}.localhost/admissions/dashboard")
            ->assertRedirect("http://{$school->slug}.localhost/admissions/login");
    }

    /**
     * The nightly sweep (ArchiveExpiredApplicants) catches an applicant
     * who never comes back at all, not just one who tries to use an
     * expired session (the middleware test above).
     */
    public function test_the_scheduled_sweep_archives_expired_applicants_who_never_return(): void
    {
        [$school, $applicant] = $this->schoolWithApplicant('admfive', ['expires_at' => now()->subDay()]);

        $this->artisan('admissions:archive-expired-applicants')->assertSuccessful();

        app(TenantManager::class)->use($school);
        $this->assertSame(Applicant::STATUS_ARCHIVED, $applicant->fresh()->account_status);
        app(TenantManager::class)->forget();
    }

    /** An archived applicant is recoverable: an admin can reopen the window, nothing about the model prevents it once account_status/expires_at are reset. */
    public function test_an_archived_applicant_can_be_recovered_by_extending_the_window(): void
    {
        [$school, $applicant] = $this->schoolWithApplicant('admsix', [
            'account_status' => Applicant::STATUS_ARCHIVED,
            'expires_at' => now()->subDay(),
        ]);

        app(TenantManager::class)->use($school);
        $this->assertFalse($applicant->fresh()->isActive());

        $applicant->forceFill(['account_status' => Applicant::STATUS_ACTIVE, 'expires_at' => now()->addDays(30)])->save();
        $this->assertTrue($applicant->fresh()->isActive());
        app(TenantManager::class)->forget();
    }
}
