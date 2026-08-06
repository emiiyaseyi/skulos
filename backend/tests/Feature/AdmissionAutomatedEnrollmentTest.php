<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\AdmissionWindow;
use App\Models\Tenant\Applicant;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\FeeItem;
use App\Models\Tenant\Payment;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Term;
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
 * Automated admissions pipeline (user directive 2026-08-06): offer ->
 * admission letter -> parent accepts -> pays school fees (optional and
 * non-optional) -> auto-assigned to the lowest-population class arm ->
 * the receiving class teacher onboards the child. The pre-existing
 * one-step manual convert path (AdmissionPipelineConversionTest) is
 * untouched and re-verified separately, not part of this file.
 */
class AdmissionAutomatedEnrollmentTest extends TestCase
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

    /**
     * @return array{0: School, 1: User, 2: Applicant, 3: ClassArm, 4: ClassArm, 5: Term}
     */
    protected function fixture(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug, 'name' => 'Greenfield Academy']));
        $this->rbac->setModuleState($school, 'admissions', \App\Models\Module::STATE_ENABLED);
        $this->rbac->setModuleState($school, 'finance', \App\Models\Module::STATE_ENABLED);

        $admin = User::factory()->create();
        $adminRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($admin->id, ['role_id' => $adminRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $armA = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $armB = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);
        $session = AcademicSession::create(['name' => '2026/2027 Session', 'is_current' => true]);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true]);
        AdmissionWindow::create([
            'session_id' => $session->id, 'school_class_id' => $class->id,
            'application_fee_amount' => 5000, 'is_active' => true,
        ]);
        $applicant = Applicant::forceCreate([
            'name' => 'Ada Obi', 'first_name' => 'Ada', 'last_name' => 'Obi',
            'phone' => '08011112222', 'email' => 'parent-ada@example.com',
            'admission_number' => 'APP/0001', 'password' => Hash::make('secret123'),
            'account_status' => Applicant::STATUS_ACTIVE, 'expires_at' => now()->addDays(30),
            'session_id' => $session->id, 'school_class_id' => $class->id,
        ]);
        app(TenantManager::class)->forget();

        return [$school, $admin, $applicant, $armA, $armB, $term];
    }

    protected function loginAsApplicant(School $school, string $identifier = 'APP/0001', string $password = 'secret123'): void
    {
        $this->post("http://{$school->slug}.localhost/admissions/login", ['identifier' => $identifier, 'password' => $password])
            ->assertRedirect();
    }

    public function test_offering_admission_sends_the_offer_letter_email_but_waitlist_does_not(): void
    {
        Mail::fake();
        [$school, $admin, $applicant] = $this->fixture('aaeone');

        $this->actingAs($admin)->post(route('school.admissions.pipeline.offer', ['school' => $school->slug, 'applicantId' => $applicant->id]))
            ->assertRedirect();
        Mail::assertSent(\App\Mail\AdmissionOfferMail::class);

        Mail::fake();
        [$school2, $admin2, $applicant2] = $this->fixture('aaetwo');
        $this->actingAs($admin2)->post(route('school.admissions.pipeline.waitlist', ['school' => $school2->slug, 'applicantId' => $applicant2->id]))
            ->assertRedirect();
        Mail::assertNotSent(\App\Mail\AdmissionOfferMail::class);
    }

    public function test_the_admission_letter_is_only_viewable_once_offered(): void
    {
        [$school, $admin, $applicant] = $this->fixture('aaethree');

        $this->actingAs($admin)->get(route('school.admissions.pipeline.letter', ['school' => $school->slug, 'applicantId' => $applicant->id]))
            ->assertNotFound();

        $this->actingAs($admin)->post(route('school.admissions.pipeline.offer', ['school' => $school->slug, 'applicantId' => $applicant->id]));

        $this->actingAs($admin)->get(route('school.admissions.pipeline.letter', ['school' => $school->slug, 'applicantId' => $applicant->id]))
            ->assertOk()->assertSee('Greenfield Academy')->assertSee('Ada Obi');
    }

    public function test_accepting_an_offer_creates_a_dormant_student_in_the_lowest_population_arm_with_an_active_guardian_login(): void
    {
        [$school, $admin, $applicant, $armA, $armB] = $this->fixture('aaefour');

        app(TenantManager::class)->use($school);
        // Give armA one existing active student, so armB (still empty) is the genuine lowest-population pick.
        Student::create(['admission_number' => 'EXIST/0001', 'first_name' => 'Existing', 'last_name' => 'Kid', 'current_class_arm_id' => $armA->id, 'status' => Student::STATUS_ACTIVE]);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post(route('school.admissions.pipeline.offer', ['school' => $school->slug, 'applicantId' => $applicant->id]));

        $this->loginAsApplicant($school);
        $this->post(route('school.admissions.offer.accept', ['school' => $school->slug]))->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $applicant->fresh();
        $this->assertSame(Applicant::OFFER_ACCEPTED, $fresh->offer_response);
        $this->assertSame(Applicant::DECISION_CONVERTED, $fresh->decision_status);

        $student = Student::find($fresh->converted_student_id);
        $this->assertNotNull($student);
        $this->assertSame(Student::STATUS_PENDING_ENROLLMENT, $student->status);
        $this->assertSame($armB->id, $student->current_class_arm_id, 'the emptier arm B must be picked, not arm A');
        $this->assertMatchesRegularExpression('#^GA/JSS1/2026/0001$#', $student->admission_number);
        $this->assertNotNull($student->user_id);

        $guardian = $student->guardians()->first();
        $this->assertNotNull($guardian);
        $this->assertNotNull($guardian->guardian_user_id);
        $guardianUser = User::find($guardian->guardian_user_id);
        $this->assertTrue($guardianUser->isMemberOf($school), 'the guardian login must be fully active immediately, so they can sign in and pay');

        $this->assertFalse(Student::active()->whereKey($student->id)->exists(), 'a pending_enrollment student must not appear in the active roster yet');
        app(TenantManager::class)->forget();
    }

    public function test_an_admin_preferred_stream_overrides_the_lowest_population_pick(): void
    {
        [$school, $admin, $applicant, $armA, $armB] = $this->fixture('aaefive');

        // armB is the emptier arm, but the admin explicitly prefers armA.
        $this->actingAs($admin)->post(route('school.admissions.pipeline.offer', ['school' => $school->slug, 'applicantId' => $applicant->id]), [
            'preferred_class_arm_id' => $armA->id,
        ]);

        $this->loginAsApplicant($school);
        $this->post(route('school.admissions.offer.accept', ['school' => $school->slug]));

        app(TenantManager::class)->use($school);
        $student = Student::find($applicant->fresh()->converted_student_id);
        $this->assertSame($armA->id, $student->current_class_arm_id);
        app(TenantManager::class)->forget();
    }

    public function test_declining_an_offer_marks_the_applicant_rejected_and_creates_no_student(): void
    {
        [$school, $admin, $applicant] = $this->fixture('aaesix');

        $this->actingAs($admin)->post(route('school.admissions.pipeline.offer', ['school' => $school->slug, 'applicantId' => $applicant->id]));

        $this->loginAsApplicant($school);
        $this->post(route('school.admissions.offer.decline', ['school' => $school->slug]))->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $applicant->fresh();
        $this->assertSame(Applicant::OFFER_DECLINED, $fresh->offer_response);
        $this->assertSame(Applicant::DECISION_REJECTED, $fresh->decision_status);
        $this->assertNull($fresh->converted_student_id);
        app(TenantManager::class)->forget();
    }

    public function test_admission_numbers_are_sequential_per_class_and_year_and_are_never_reissued_after_a_soft_delete(): void
    {
        [$school, $admin, $applicant, $armA, $armB] = $this->fixture('aaeseven');

        $this->actingAs($admin)->post(route('school.admissions.pipeline.offer', ['school' => $school->slug, 'applicantId' => $applicant->id]));
        $this->loginAsApplicant($school);
        $this->post(route('school.admissions.offer.accept', ['school' => $school->slug]));

        app(TenantManager::class)->use($school);
        $firstStudent = Student::find($applicant->fresh()->converted_student_id);
        $this->assertStringEndsWith('/0001', $firstStudent->admission_number);
        $firstStudent->delete(); // soft delete
        app(TenantManager::class)->forget();

        // A second applicant, same class/year.
        app(TenantManager::class)->use($school);
        $applicant2 = Applicant::forceCreate([
            'name' => 'Bola Ade', 'first_name' => 'Bola', 'last_name' => 'Ade', 'phone' => '08022223333',
            'admission_number' => 'APP/0002', 'password' => Hash::make('secret123'),
            'account_status' => Applicant::STATUS_ACTIVE, 'expires_at' => now()->addDays(30),
            'session_id' => $applicant->session_id, 'school_class_id' => $applicant->school_class_id,
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post(route('school.admissions.pipeline.offer', ['school' => $school->slug, 'applicantId' => $applicant2->id]));
        // The first applicant's session is still authenticated at this
        // point (unlike real production, a test shares one session store
        // across sequential requests) — the /admissions/login route sits
        // behind guest:applicant, so it would silently no-op without an
        // explicit logout first. forgetGuards() alone only clears the
        // guard's in-memory cached user, not the underlying session.
        $this->post(route('school.admissions.logout', ['school' => $school->slug]));
        app('auth')->forgetGuards();
        $this->loginAsApplicant($school, 'APP/0002', 'secret123');
        $this->post(route('school.admissions.offer.accept', ['school' => $school->slug]))->assertRedirect();

        app(TenantManager::class)->use($school);
        $secondStudent = Student::find($applicant2->fresh()->converted_student_id);
        $this->assertStringEndsWith('/0002', $secondStudent->admission_number, 'the soft-deleted first student\'s number must not be reissued');
        app(TenantManager::class)->forget();
    }

    /**
     * Full end-to-end walk: offer -> accept -> pay both optional and
     * non-optional fees -> class-teacher onboarding queue -> onboard ->
     * verify roster inclusion and every notification/mail along the way.
     */
    public function test_the_full_offer_to_onboarded_pipeline_end_to_end(): void
    {
        Mail::fake();
        [$school, $admin, $applicant, $armA, $armB, $term] = $this->fixture('aaeeight');

        app(TenantManager::class)->use($school);
        $classTeacherUser = User::factory()->create();
        $classTeacherRole = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($classTeacherUser->id, ['role_id' => $classTeacherRole->id, 'status' => 'active']);
        $staff = Staff::create(['staff_id' => 'STF/CT1', 'first_name' => 'Chi', 'last_name' => 'Teacher', 'user_id' => $classTeacherUser->id]);
        // Both arms are empty, so lowestPopulationArm()'s tie-break (lowest
        // id) picks armA, the first created — assign the class teacher there.
        $armA->update(['class_teacher_staff_id' => $staff->id]);

        $nonOptional = FeeItem::create(['name' => 'Tuition', 'amount' => 40000, 'term_id' => $term->id, 'is_optional' => false]);
        $optional = FeeItem::create(['name' => 'Uniform', 'amount' => 10000, 'term_id' => $term->id, 'is_optional' => true]);
        app(TenantManager::class)->forget();

        // Offer.
        $this->actingAs($admin)->post(route('school.admissions.pipeline.offer', ['school' => $school->slug, 'applicantId' => $applicant->id]))->assertRedirect();
        Mail::assertSent(\App\Mail\AdmissionOfferMail::class);

        // Accept.
        $this->loginAsApplicant($school);
        $this->post(route('school.admissions.offer.accept', ['school' => $school->slug]))->assertRedirect();
        Mail::assertSent(\App\Mail\AdmissionAcceptanceMail::class);

        app(TenantManager::class)->use($school);
        $student = Student::find($applicant->fresh()->converted_student_id);
        $studentId = $student->id;
        $guardianUserId = $student->guardians()->first()->guardian_user_id;
        app(TenantManager::class)->forget();

        $guardianUser = User::find($guardianUserId);

        // The student appears in the queue immediately (so the teacher can
        // see who is coming), but marked fees-pending.
        $this->actingAs($classTeacherUser)->get(route('school.admissions.onboarding.index', ['school' => $school->slug]))
            ->assertOk()->assertSee($student->admission_number)->assertSee('Pending');

        // Guardian pays the NON-OPTIONAL fee only — must NOT clear the gate.
        app(TenantManager::class)->use($school);
        $paymentOne = Payment::create(['student_id' => $studentId, 'fee_item_id' => $nonOptional->id, 'amount' => 40000, 'submitted_by_user_id' => $guardianUserId]);
        app(TenantManager::class)->forget();
        $this->actingAs($admin)->post(route('school.finance.payments.verify', ['school' => $school->slug, 'paymentId' => $paymentOne->id]))->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertNull($student->fresh()->admission_fees_cleared_at, 'paying only the non-optional fee must not clear a NEW admission, which owes both');
        app(TenantManager::class)->forget();

        // Guardian pays the remaining OPTIONAL fee too — now it clears.
        app(TenantManager::class)->use($school);
        $paymentTwo = Payment::create(['student_id' => $studentId, 'fee_item_id' => $optional->id, 'amount' => 10000, 'submitted_by_user_id' => $guardianUserId]);
        app(TenantManager::class)->forget();
        $this->actingAs($admin)->post(route('school.finance.payments.verify', ['school' => $school->slug, 'paymentId' => $paymentTwo->id]))->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertNotNull($student->fresh()->admission_fees_cleared_at);
        $clearedNotification = \App\Models\Tenant\Notification::where('user_id', $guardianUserId)->where('category', 'admission_fees_cleared')->exists();
        $this->assertTrue($clearedNotification);
        $onboardingPendingNotification = \App\Models\Tenant\Notification::where('user_id', $classTeacherUser->id)->where('category', 'admission_onboarding_pending')->exists();
        $this->assertTrue($onboardingPendingNotification);
        app(TenantManager::class)->forget();

        // Now the class teacher's onboarding queue shows the student as fees-cleared.
        $this->actingAs($classTeacherUser)->get(route('school.admissions.onboarding.index', ['school' => $school->slug]))
            ->assertOk()->assertSee($student->admission_number)->assertSee('Cleared');

        // A DIFFERENT class teacher (armB, which holds no dormant admissions) must not see this student.
        app(TenantManager::class)->use($school);
        $otherTeacherUser = User::factory()->create();
        $otherRole = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($otherTeacherUser->id, ['role_id' => $otherRole->id, 'status' => 'active']);
        $otherStaff = Staff::create(['staff_id' => 'STF/CT2', 'first_name' => 'Other', 'last_name' => 'Teacher', 'user_id' => $otherTeacherUser->id]);
        $armB->update(['class_teacher_staff_id' => $otherStaff->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($otherTeacherUser)->get(route('school.admissions.onboarding.index', ['school' => $school->slug]))
            ->assertOk()->assertDontSee($student->admission_number);
        $this->actingAs($otherTeacherUser)->get(route('school.admissions.onboarding.show', ['school' => $school->slug, 'studentId' => $studentId]))
            ->assertNotFound();

        // Onboard.
        app(TenantManager::class)->use($school);
        $house = \App\Models\Tenant\House::create(['name' => 'Red House', 'is_active' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($classTeacherUser)->post(route('school.admissions.onboarding.onboard', ['school' => $school->slug, 'studentId' => $studentId]), [
            'house_id' => $house->id,
        ])->assertRedirect();
        Mail::assertSent(\App\Mail\AdmissionOnboardedMail::class);

        app(TenantManager::class)->use($school);
        $onboarded = Student::find($studentId);
        $this->assertSame(Student::STATUS_ACTIVE, $onboarded->status);
        $this->assertNotNull($onboarded->onboarded_at);
        $this->assertSame($classTeacherUser->id, $onboarded->onboarded_by_user_id);
        $this->assertSame($house->id, $onboarded->house_id);
        $this->assertTrue(Student::active()->whereKey($studentId)->exists(), 'the onboarded student must now appear in the normal active roster');
        $onboardedNotification = \App\Models\Tenant\Notification::where('user_id', $guardianUserId)->where('category', 'admission_onboarded')->exists();
        $this->assertTrue($onboardedNotification);
        app(TenantManager::class)->forget();
    }

    public function test_onboarding_is_blocked_until_fees_clear(): void
    {
        [$school, $admin, $applicant, $armA, $armB] = $this->fixture('aaenine');

        app(TenantManager::class)->use($school);
        $classTeacherUser = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($classTeacherUser->id, ['role_id' => $role->id, 'status' => 'active']);
        $staff = Staff::create(['staff_id' => 'STF/CT3', 'first_name' => 'Chi', 'last_name' => 'Teacher', 'user_id' => $classTeacherUser->id]);
        // Both arms are empty, so lowestPopulationArm()'s tie-break (lowest id) picks armA.
        $armA->update(['class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post(route('school.admissions.pipeline.offer', ['school' => $school->slug, 'applicantId' => $applicant->id]));
        $this->loginAsApplicant($school);
        $this->post(route('school.admissions.offer.accept', ['school' => $school->slug]));

        app(TenantManager::class)->use($school);
        $studentId = $applicant->fresh()->converted_student_id;
        app(TenantManager::class)->forget();

        $this->actingAs($classTeacherUser)->post(route('school.admissions.onboarding.onboard', ['school' => $school->slug, 'studentId' => $studentId]))
            ->assertStatus(422);
    }
}
