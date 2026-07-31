<?php

namespace Tests\Feature;

use App\Mail\AdmissionRecoveryMail;
use App\Models\School;
use App\Models\Tenant\AdmissionRecoveryRequest;
use App\Models\Tenant\Applicant;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Admissions module (user directive 2026-07-31): an applicant who lost
 * their reference number/password can check their own identity
 * (name+DOB+phone-or-email) automatically, or, if that doesn't match,
 * ask the school to check manually, reviewed and approved by an admin.
 */
class AdmissionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected Rbac $rbac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
        $this->seed(RbacSeeder::class);
        $this->rbac = app(Rbac::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function schoolWithApplicantAndStaff(string $slug, array $overrides = []): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'admissions', \App\Models\Module::STATE_ENABLED);

        $staff = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($staff->id, ['role_id' => $role->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $applicant = Applicant::forceCreate(array_merge([
            'name' => 'Tolu Bakare', 'first_name' => 'Tolu', 'last_name' => 'Bakare',
            'date_of_birth' => '2015-06-01', 'phone' => '08099990000', 'email' => 'parent@example.com',
            'admission_number' => 'APP/0777', 'password' => Hash::make('secret123'),
            'account_status' => Applicant::STATUS_ACTIVE, 'expires_at' => now()->addDays(30),
        ], $overrides));
        app(TenantManager::class)->forget();

        return [$school, $staff, $applicant];
    }

    public function test_a_correct_answer_reveals_the_reference_and_password(): void
    {
        [$school, , $applicant] = $this->schoolWithApplicantAndStaff('arone');

        $this->post("http://{$school->slug}.localhost/admissions/recover", [
            'first_name' => 'Tolu', 'last_name' => 'Bakare',
            'date_of_birth' => '2015-06-01', 'phone_or_email' => '08099990000',
        ])->assertOk()->assertSee('APP/0777');
    }

    public function test_a_matching_email_instead_of_phone_also_works(): void
    {
        [$school] = $this->schoolWithApplicantAndStaff('artwo');

        $this->post("http://{$school->slug}.localhost/admissions/recover", [
            'first_name' => 'Tolu', 'last_name' => 'Bakare',
            'date_of_birth' => '2015-06-01', 'phone_or_email' => 'parent@example.com',
        ])->assertOk()->assertSee('APP/0777');
    }

    public function test_a_wrong_date_of_birth_does_not_match(): void
    {
        [$school] = $this->schoolWithApplicantAndStaff('arthree');

        $this->post("http://{$school->slug}.localhost/admissions/recover", [
            'first_name' => 'Tolu', 'last_name' => 'Bakare',
            'date_of_birth' => '2010-01-01', 'phone_or_email' => '08099990000',
        ])->assertOk()->assertDontSee('APP/0777')->assertSee('check manually');
    }

    public function test_a_pending_fee_applicant_recovery_explains_the_bootstrap_password(): void
    {
        [$school] = $this->schoolWithApplicantAndStaff('arfour', [
            'password' => null, 'account_status' => Applicant::STATUS_PENDING_FEE, 'expires_at' => null,
        ]);

        $this->post("http://{$school->slug}.localhost/admissions/recover", [
            'first_name' => 'Tolu', 'last_name' => 'Bakare',
            'date_of_birth' => '2015-06-01', 'phone_or_email' => '08099990000',
        ])->assertOk()->assertSee('APP/0777')->assertSee('reference number as the password');
    }

    public function test_a_manual_recovery_request_can_be_submitted_and_approved(): void
    {
        Mail::fake();
        [$school, $staff, $applicant] = $this->schoolWithApplicantAndStaff('arfive');

        $this->post("http://{$school->slug}.localhost/admissions/recover/request", [
            'remembered_name' => 'Tolu B.',
            'description' => 'Applied for Primary 1 sometime last month.',
            'email' => 'someoneelse@example.com',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $recoveryRequest = AdmissionRecoveryRequest::first();
        app(TenantManager::class)->forget();
        $this->assertNotNull($recoveryRequest);
        $this->assertSame(AdmissionRecoveryRequest::STATUS_PENDING, $recoveryRequest->status);

        $this->actingAs($staff)->get(route('school.admissions.recovery-review.index', ['school' => $school->slug]))
            ->assertOk()->assertSee('Applied for Primary 1');

        $this->actingAs($staff)->post(route('school.admissions.recovery-review.approve', ['school' => $school->slug, 'requestId' => $recoveryRequest->id]), [
            'matched_applicant_id' => $applicant->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $recoveryRequest->fresh();
        $this->assertSame(AdmissionRecoveryRequest::STATUS_APPROVED, $fresh->status);
        $this->assertSame($applicant->id, $fresh->matched_applicant_id);
        app(TenantManager::class)->forget();

        Mail::assertSent(AdmissionRecoveryMail::class, fn ($m) => $m->hasTo('someoneelse@example.com') && $m->applicant->id === $applicant->id);
    }

    public function test_a_recovery_request_already_decided_cannot_be_decided_again(): void
    {
        [$school, $staff, $applicant] = $this->schoolWithApplicantAndStaff('arsix');
        app(TenantManager::class)->use($school);
        $recoveryRequest = AdmissionRecoveryRequest::create(['description' => 'x', 'email' => 'a@example.com']);
        app(TenantManager::class)->forget();

        $this->actingAs($staff)->post(route('school.admissions.recovery-review.reject', ['school' => $school->slug, 'requestId' => $recoveryRequest->id]));
        $this->actingAs($staff)->post(route('school.admissions.recovery-review.approve', ['school' => $school->slug, 'requestId' => $recoveryRequest->id]), [
            'matched_applicant_id' => $applicant->id,
        ])->assertSessionHas('error');

        app(TenantManager::class)->use($school);
        $this->assertSame(AdmissionRecoveryRequest::STATUS_REJECTED, $recoveryRequest->fresh()->status, 'the first decision must win, not the second');
        app(TenantManager::class)->forget();
    }

    public function test_a_teacher_without_the_decide_permission_cannot_review_recovery_requests(): void
    {
        [$school] = $this->schoolWithApplicantAndStaff('arseven');
        $teacher = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'subject-teacher');
        $school->users()->attach($teacher->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($teacher)->get(route('school.admissions.recovery-review.index', ['school' => $school->slug]))
            ->assertForbidden();
    }
}
