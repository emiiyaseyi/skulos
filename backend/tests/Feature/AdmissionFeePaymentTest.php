<?php

namespace Tests\Feature;

use App\Mail\AdmissionCredentialsMail;
use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\AdmissionPayment;
use App\Models\Tenant\AdmissionWindow;
use App\Models\Tenant\Applicant;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Admissions module, Phase 1d: an applicant uploads application fee
 * evidence via their signed (not logged in yet) status link, an admin
 * verifies or rejects it. Verifying is what actually issues sign in
 * credentials and emails them.
 */
class AdmissionFeePaymentTest extends TestCase
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
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function schoolWithApplicantAndStaff(string $slug, array $applicantOverrides = []): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'admissions', \App\Models\Module::STATE_ENABLED);

        $staff = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($staff->id, ['role_id' => $role->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        $window = AdmissionWindow::create([
            'session_id' => $session->id, 'school_class_id' => $class->id,
            'application_fee_amount' => 5000, 'is_active' => true,
        ]);
        $applicant = Applicant::forceCreate(array_merge([
            'name' => 'Ada Obi', 'phone' => '08011112222', 'admission_number' => 'APP/0001',
            'session_id' => $session->id, 'school_class_id' => $class->id,
            'account_status' => Applicant::STATUS_PENDING_FEE,
        ], $applicantOverrides));
        app(TenantManager::class)->forget();

        $statusUrl = URL::temporarySignedRoute('school.admissions.apply.status', now()->addDays(30), [
            'school' => $school->slug, 'applicant' => $applicant->id,
        ]);

        return [$school, $staff, $applicant, $statusUrl];
    }

    public function test_the_status_link_requires_a_valid_signature(): void
    {
        [$school, , $applicant] = $this->schoolWithApplicantAndStaff('afone');

        $this->get("http://{$school->slug}.localhost/admissions/apply/{$applicant->id}/status")
            ->assertForbidden();
    }

    public function test_an_applicant_can_upload_fee_evidence_via_their_signed_link(): void
    {
        [$school, , $applicant, $statusUrl] = $this->schoolWithApplicantAndStaff('aftwo');

        $this->get($statusUrl)->assertOk()->assertSee('Submit payment evidence');

        $this->post($statusUrl, [
            'evidence' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $payment = AdmissionPayment::first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($payment);
        $this->assertSame(AdmissionPayment::STATUS_PENDING, $payment->status);
        $this->assertSame(5000.0, (float) $payment->amount, 'the amount is taken from the window, not trusted from the request');
    }

    public function test_verifying_the_fee_issues_credentials_and_emails_them(): void
    {
        Mail::fake();
        [$school, $staff, $applicant, $statusUrl] = $this->schoolWithApplicantAndStaff('afthree', ['email' => 'parent@example.com']);

        $this->post($statusUrl, ['evidence' => UploadedFile::fake()->image('receipt.jpg')]);

        app(TenantManager::class)->use($school);
        $payment = AdmissionPayment::first();
        app(TenantManager::class)->forget();

        $this->actingAs($staff)->post(route('school.admissions.fees.verify', ['school' => $school->slug, 'paymentId' => $payment->id]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $applicant->fresh();
        $this->assertSame(Applicant::STATUS_ACTIVE, $fresh->account_status);
        $this->assertNotNull($fresh->password);
        $this->assertNotNull($fresh->expires_at);
        $this->assertSame(1, $fresh->pipeline_stage, 'verifying the fee advances past the first pipeline stage');
        app(TenantManager::class)->forget();

        Mail::assertSent(AdmissionCredentialsMail::class, fn ($m) => $m->hasTo('parent@example.com'));
    }

    public function test_the_issued_password_is_recoverable_afterward_and_shown_on_the_verified_status_page(): void
    {
        Mail::fake();
        [$school, $staff, $applicant, $statusUrl] = $this->schoolWithApplicantAndStaff('afeight', ['email' => 'parent2@example.com']);

        $this->post($statusUrl, ['evidence' => UploadedFile::fake()->image('receipt.jpg')]);
        app(TenantManager::class)->use($school);
        $payment = AdmissionPayment::first();
        app(TenantManager::class)->forget();

        $this->actingAs($staff)->post(route('school.admissions.fees.verify', ['school' => $school->slug, 'paymentId' => $payment->id]));

        app(TenantManager::class)->use($school);
        $fresh = $applicant->fresh();
        $recovered = $fresh->recoverablePassword();
        $this->assertNotNull($recovered);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($recovered, $fresh->password), 'the recoverable copy must match the real hashed password');
        app(TenantManager::class)->forget();

        $this->get($statusUrl)->assertOk()->assertSee($recovered);
    }

    public function test_verifying_with_no_email_on_file_flashes_the_password_to_the_reviewing_staff_member(): void
    {
        Mail::fake();
        [$school, $staff, $applicant, $statusUrl] = $this->schoolWithApplicantAndStaff('affour');

        $this->post($statusUrl, ['evidence' => UploadedFile::fake()->image('receipt.jpg')]);
        app(TenantManager::class)->use($school);
        $payment = AdmissionPayment::first();
        app(TenantManager::class)->forget();

        $this->actingAs($staff)->post(route('school.admissions.fees.verify', ['school' => $school->slug, 'paymentId' => $payment->id]))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($msg) => str_contains($msg, 'share this password'));

        Mail::assertNothingSent();
    }

    public function test_rejecting_the_fee_leaves_the_applicant_without_credentials(): void
    {
        [$school, $staff, $applicant, $statusUrl] = $this->schoolWithApplicantAndStaff('affive');

        $this->post($statusUrl, ['evidence' => UploadedFile::fake()->image('receipt.jpg')]);
        app(TenantManager::class)->use($school);
        $payment = AdmissionPayment::first();
        app(TenantManager::class)->forget();

        $this->actingAs($staff)->post(route('school.admissions.fees.reject', ['school' => $school->slug, 'paymentId' => $payment->id]), [
            'notes' => 'Amount does not match.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $applicant->fresh();
        $this->assertSame(Applicant::STATUS_PENDING_FEE, $fresh->account_status);
        $this->assertNull($fresh->password);
        app(TenantManager::class)->forget();

        // The applicant can see why and resubmit.
        $this->get($statusUrl)->assertOk()->assertSee('not accepted')->assertSee('Resubmit payment evidence');
    }

    public function test_a_payment_already_decided_cannot_be_decided_again(): void
    {
        [$school, $staff, , $statusUrl] = $this->schoolWithApplicantAndStaff('afsix');

        $this->post($statusUrl, ['evidence' => UploadedFile::fake()->image('receipt.jpg')]);
        app(TenantManager::class)->use($school);
        $payment = AdmissionPayment::first();
        app(TenantManager::class)->forget();

        $this->actingAs($staff)->post(route('school.admissions.fees.verify', ['school' => $school->slug, 'paymentId' => $payment->id]));
        $this->actingAs($staff)->post(route('school.admissions.fees.reject', ['school' => $school->slug, 'paymentId' => $payment->id]))
            ->assertSessionHas('error');

        app(TenantManager::class)->use($school);
        $this->assertSame(AdmissionPayment::STATUS_VERIFIED, $payment->fresh()->status, 'the first decision must win, not the second');
        app(TenantManager::class)->forget();
    }

    public function test_a_teacher_without_the_decide_permission_cannot_verify(): void
    {
        [$school, , , $statusUrl] = $this->schoolWithApplicantAndStaff('afseven');
        $this->post($statusUrl, ['evidence' => UploadedFile::fake()->image('receipt.jpg')]);
        app(TenantManager::class)->use($school);
        $payment = AdmissionPayment::first();
        app(TenantManager::class)->forget();

        $teacher = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'subject-teacher');
        $school->users()->attach($teacher->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($teacher)->post(route('school.admissions.fees.verify', ['school' => $school->slug, 'paymentId' => $payment->id]))
            ->assertForbidden();
    }
}
