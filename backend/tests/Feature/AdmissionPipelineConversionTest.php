<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\AdmissionPayment;
use App\Models\Tenant\AdmissionWindow;
use App\Models\Tenant\Applicant;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admissions module, Phase 1g: the applicant status board, admission
 * decisions, and converting an offered, acceptance-fee-paid applicant
 * into a real enrolled student with zero duplicate data entry.
 */
class AdmissionPipelineConversionTest extends TestCase
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

    protected function fixture(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'admissions', \App\Models\Module::STATE_ENABLED);

        $staff = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($staff->id, ['role_id' => $role->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        AdmissionWindow::create([
            'session_id' => $session->id, 'school_class_id' => $class->id,
            'application_fee_amount' => 5000, 'acceptance_fee_amount' => 20000, 'is_active' => true,
        ]);
        $applicant = Applicant::forceCreate([
            'name' => 'Tolu Bakare', 'phone' => '08055556666', 'email' => 'parent@example.com',
            'admission_number' => 'APP/0001', 'password' => Hash::make('secret123'),
            'account_status' => Applicant::STATUS_ACTIVE, 'expires_at' => now()->addDays(30),
            'session_id' => $session->id, 'school_class_id' => $class->id,
        ]);
        app(TenantManager::class)->forget();

        return [$school, $staff, $applicant, $arm];
    }

    public function test_the_full_offer_to_fee_paid_to_conversion_path_creates_a_real_student_and_guardian(): void
    {
        Mail::fake();
        [$school, $staff, $applicant, $arm] = $this->fixture('apcone');

        // Offer admission.
        $this->actingAs($staff)->post(route('school.admissions.pipeline.offer', ['school' => $school->slug, 'applicantId' => $applicant->id]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(Applicant::DECISION_OFFERED, $applicant->fresh()->decision_status);
        app(TenantManager::class)->forget();

        // Applicant pays the acceptance fee.
        $this->post("http://{$school->slug}.localhost/admissions/login", ['identifier' => 'APP/0001', 'password' => 'secret123']);
        $this->post("http://{$school->slug}.localhost/admissions/acceptance-fee", [
            'evidence' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $payment = AdmissionPayment::where('fee_type', AdmissionPayment::TYPE_ACCEPTANCE)->first();
        $this->assertNotNull($payment);
        $this->assertSame(20000.0, (float) $payment->amount);
        app(TenantManager::class)->forget();

        // Enrollment cannot be completed before the fee is verified.
        $this->actingAs($staff)->get(route('school.admissions.pipeline.convert.form', ['school' => $school->slug, 'applicantId' => $applicant->id]))
            ->assertOk()->assertSee('has not been verified');

        // Admin verifies the acceptance fee.
        $this->actingAs($staff)->post(route('school.admissions.fees.verify', ['school' => $school->slug, 'paymentId' => $payment->id]))
            ->assertRedirect();

        // Enrollment: confirm admission number and class stream.
        $this->actingAs($staff)->post(route('school.admissions.pipeline.convert', ['school' => $school->slug, 'applicantId' => $applicant->id]), [
            'admission_number' => 'GFA/0099',
            'class_arm_id' => $arm->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $student = Student::where('admission_number', 'GFA/0099')->first();
        $this->assertNotNull($student);
        $this->assertSame('Tolu', $student->first_name);
        $this->assertSame('Bakare', $student->last_name);
        $this->assertSame($arm->id, $student->current_class_arm_id);
        $this->assertNotNull($student->user_id, 'a real login must be provisioned for the student');

        $guardian = $student->guardians()->first();
        $this->assertNotNull($guardian);
        $this->assertSame('parent@example.com', $guardian->email);
        $this->assertNotNull($guardian->guardian_user_id, 'a real login must be provisioned for the guardian');

        $fresh = $applicant->fresh();
        $this->assertSame(Applicant::DECISION_CONVERTED, $fresh->decision_status);
        $this->assertSame($student->id, $fresh->converted_student_id);
        app(TenantManager::class)->forget();
    }

    public function test_a_second_child_from_the_same_guardian_reuses_the_existing_guardian_account_not_a_duplicate(): void
    {
        [$school, $staff, $applicant, $arm] = $this->fixture('apctwo');

        app(TenantManager::class)->use($school);
        $existingGuardianUser = User::factory()->create(['email' => 'parent@example.com']);
        app(TenantManager::class)->forget();

        $this->actingAs($staff)->post(route('school.admissions.pipeline.offer', ['school' => $school->slug, 'applicantId' => $applicant->id]));

        app(TenantManager::class)->use($school);
        $payment = AdmissionPayment::create([
            'applicant_id' => $applicant->id, 'fee_type' => AdmissionPayment::TYPE_ACCEPTANCE,
            'amount' => 20000, 'evidence_path' => 'fake.webp',
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($staff)->post(route('school.admissions.fees.verify', ['school' => $school->slug, 'paymentId' => $payment->id]));
        $this->actingAs($staff)->post(route('school.admissions.pipeline.convert', ['school' => $school->slug, 'applicantId' => $applicant->id]), [
            'admission_number' => 'GFA/0100', 'class_arm_id' => $arm->id,
        ]);

        app(TenantManager::class)->use($school);
        $student = Student::where('admission_number', 'GFA/0100')->firstOrFail();
        $guardian = $student->guardians()->first();
        $this->assertSame($existingGuardianUser->id, $guardian->guardian_user_id, 'must reuse the already-provisioned account for this email, not duplicate it');
        app(TenantManager::class)->forget();
    }

    public function test_a_rejected_applicant_can_be_nudged_or_offered_again_but_not_converted(): void
    {
        [$school, $staff, $applicant] = $this->fixture('apcthree');

        $this->actingAs($staff)->post(route('school.admissions.pipeline.reject', ['school' => $school->slug, 'applicantId' => $applicant->id]), [
            'notes' => 'Class is full.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(Applicant::DECISION_REJECTED, $applicant->fresh()->decision_status);
        app(TenantManager::class)->forget();

        $this->actingAs($staff)->get(route('school.admissions.pipeline.convert.form', ['school' => $school->slug, 'applicantId' => $applicant->id]))
            ->assertStatus(422);
    }

    public function test_a_teacher_without_the_decide_permission_cannot_offer_admission(): void
    {
        [$school, , $applicant] = $this->fixture('apcfour');
        $teacher = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'subject-teacher');
        $school->users()->attach($teacher->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($teacher)->post(route('school.admissions.pipeline.offer', ['school' => $school->slug, 'applicantId' => $applicant->id]))
            ->assertForbidden();
    }
}
