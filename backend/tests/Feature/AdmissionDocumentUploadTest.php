<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AdmissionDocument;
use App\Models\Tenant\Applicant;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admissions module, Phase 1f: an applicant uploads supporting documents
 * (birth certificate, last report card, etc, all optional) as the final
 * step of the application wizard; an admin verifies or rejects each one.
 */
class AdmissionDocumentUploadTest extends TestCase
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

    protected function schoolWithApplicantAndStaff(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'admissions', \App\Models\Module::STATE_ENABLED);

        $staff = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($staff->id, ['role_id' => $role->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $applicant = Applicant::forceCreate([
            'name' => 'Ngozi Umeh', 'phone' => '08055554444', 'admission_number' => 'APP/0050',
            'password' => Hash::make('secret123'), 'account_status' => Applicant::STATUS_ACTIVE, 'expires_at' => now()->addDays(30),
        ]);
        app(TenantManager::class)->forget();

        return [$school, $staff, $applicant];
    }

    protected function signIn(School $school): void
    {
        $this->post("http://{$school->slug}.localhost/admissions/login", ['identifier' => 'APP/0050', 'password' => 'secret123']);
    }

    public function test_the_documents_step_is_shown_when_a_school_has_no_custom_form_fields(): void
    {
        [$school] = $this->schoolWithApplicantAndStaff('aduone');
        // Simulates a school that deliberately cleared every seeded
        // section (AdmissionFormSection::ensureSeeded() would otherwise
        // populate a real starter form the first time /admissions/form
        // is visited, which is not the "genuinely no custom fields"
        // scenario this test means to cover).
        app(TenantManager::class)->use($school);
        \App\Models\Tenant\Setting::put('admission_form_seeded', true);
        app(TenantManager::class)->forget();
        $this->signIn($school);

        $this->get("http://{$school->slug}.localhost/admissions/form")
            ->assertRedirect("http://{$school->slug}.localhost/admissions/documents");

        $this->get("http://{$school->slug}.localhost/admissions/documents")
            ->assertOk()->assertSee('Birth Certificate')->assertSee('Step 1 of 1');
    }

    public function test_an_applicant_can_upload_an_optional_document_and_leave_others_blank(): void
    {
        [$school, , $applicant] = $this->schoolWithApplicantAndStaff('adutwo');
        $this->signIn($school);

        $this->post("http://{$school->slug}.localhost/admissions/documents", [
            'birth_certificate' => UploadedFile::fake()->image('birth.jpg'),
        ])->assertRedirect("http://{$school->slug}.localhost/admissions/dashboard");

        app(TenantManager::class)->use($school);
        $this->assertSame(1, AdmissionDocument::where('applicant_id', $applicant->id)->count(), 'only the one uploaded document is created, the rest stay blank');
        $doc = AdmissionDocument::where('applicant_id', $applicant->id)->first();
        $this->assertSame('birth_certificate', $doc->document_type);
        $this->assertSame(AdmissionDocument::STATUS_PENDING, $doc->status);
        app(TenantManager::class)->forget();
    }

    public function test_an_admin_can_verify_and_reject_documents(): void
    {
        [$school, $staff, $applicant] = $this->schoolWithApplicantAndStaff('aduthree');
        $this->signIn($school);
        $this->post("http://{$school->slug}.localhost/admissions/documents", [
            'birth_certificate' => UploadedFile::fake()->image('birth.jpg'),
            'last_report_card' => UploadedFile::fake()->create('report.pdf', 200, 'application/pdf'),
        ]);

        app(TenantManager::class)->use($school);
        $birth = AdmissionDocument::where('applicant_id', $applicant->id)->where('document_type', 'birth_certificate')->first();
        $report = AdmissionDocument::where('applicant_id', $applicant->id)->where('document_type', 'last_report_card')->first();
        app(TenantManager::class)->forget();

        $this->actingAs($staff)->get(route('school.admissions.document-review.index', ['school' => $school->slug]))
            ->assertOk()->assertSee('Ngozi Umeh')->assertSee('Birth Certificate');

        $this->actingAs($staff)->post(route('school.admissions.document-review.verify', ['school' => $school->slug, 'documentId' => $birth->id]))
            ->assertRedirect();
        $this->actingAs($staff)->post(route('school.admissions.document-review.reject', ['school' => $school->slug, 'documentId' => $report->id]), [
            'notes' => 'Illegible scan.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(AdmissionDocument::STATUS_VERIFIED, $birth->fresh()->status);
        $this->assertSame(AdmissionDocument::STATUS_REJECTED, $report->fresh()->status);
        $this->assertSame('Illegible scan.', $report->fresh()->notes);
        app(TenantManager::class)->forget();
    }

    public function test_reuploading_a_rejected_document_returns_it_to_pending(): void
    {
        [$school, $staff, $applicant] = $this->schoolWithApplicantAndStaff('adufour');
        $this->signIn($school);
        $this->post("http://{$school->slug}.localhost/admissions/documents", [
            'birth_certificate' => UploadedFile::fake()->image('birth.jpg'),
        ]);

        app(TenantManager::class)->use($school);
        $doc = AdmissionDocument::where('applicant_id', $applicant->id)->first();
        app(TenantManager::class)->forget();

        $this->actingAs($staff)->post(route('school.admissions.document-review.reject', ['school' => $school->slug, 'documentId' => $doc->id]), ['notes' => 'Blurry.']);

        $this->post("http://{$school->slug}.localhost/admissions/documents", [
            'birth_certificate' => UploadedFile::fake()->image('birth2.jpg'),
        ]);

        app(TenantManager::class)->use($school);
        $fresh = $doc->fresh();
        $this->assertSame(AdmissionDocument::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->notes);
        app(TenantManager::class)->forget();
    }

    public function test_a_staff_member_without_the_permission_cannot_review_documents(): void
    {
        [$school] = $this->schoolWithApplicantAndStaff('adufive');
        $teacher = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'subject-teacher');
        $school->users()->attach($teacher->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($teacher)->get(route('school.admissions.document-review.index', ['school' => $school->slug]))
            ->assertForbidden();
    }

    /**
     * User directive 2026-09-11: "let the school be able to make some of
     * the document mandatory in the application settings." Every type
     * stays optional by default (nothing new for an already-provisioned
     * school), but a school can flag specific ones as required, and the
     * applicant cannot finish without them.
     */
    public function test_a_required_document_blocks_finishing_until_uploaded(): void
    {
        [$school] = $this->schoolWithApplicantAndStaff('adusix');
        app(TenantManager::class)->use($school);
        \App\Models\Tenant\Setting::put('admission_required_documents', ['birth_certificate']);
        app(TenantManager::class)->forget();
        $this->signIn($school);

        $this->get("http://{$school->slug}.localhost/admissions/documents")
            ->assertOk()->assertSee('Birth Certificate *', false);

        $this->post("http://{$school->slug}.localhost/admissions/documents", [
            'last_report_card' => UploadedFile::fake()->create('report.pdf', 200, 'application/pdf'),
        ])->assertSessionHasErrors('birth_certificate');

        $this->post("http://{$school->slug}.localhost/admissions/documents", [
            'birth_certificate' => UploadedFile::fake()->image('birth.jpg'),
            'last_report_card' => UploadedFile::fake()->create('report.pdf', 200, 'application/pdf'),
        ])->assertRedirect("http://{$school->slug}.localhost/admissions/dashboard");
    }

    /**
     * A required document already on file (even just pending, not yet
     * verified) satisfies the requirement -- revisiting this step must
     * not force a redundant re-upload of something already given.
     */
    public function test_an_already_uploaded_required_document_does_not_need_reuploading(): void
    {
        [$school, , $applicant] = $this->schoolWithApplicantAndStaff('aduseven');
        app(TenantManager::class)->use($school);
        \App\Models\Tenant\Setting::put('admission_required_documents', ['birth_certificate']);
        app(TenantManager::class)->forget();
        $this->signIn($school);

        $this->post("http://{$school->slug}.localhost/admissions/documents", [
            'birth_certificate' => UploadedFile::fake()->image('birth.jpg'),
        ])->assertRedirect();

        // Revisiting and submitting with nothing new must not fail.
        $this->post("http://{$school->slug}.localhost/admissions/documents", [])
            ->assertRedirect("http://{$school->slug}.localhost/admissions/dashboard")
            ->assertSessionDoesntHaveErrors('birth_certificate');

        app(TenantManager::class)->use($school);
        $this->assertSame(1, AdmissionDocument::where('applicant_id', $applicant->id)->count());
        app(TenantManager::class)->forget();
    }

    /**
     * A real report: "applicant did not upload any document but its
     * showing Application complete. The school will review your
     * documents shortly." -- the message must not claim a review is
     * coming when nothing was ever given to review.
     */
    public function test_the_completion_message_does_not_promise_a_review_when_nothing_was_uploaded(): void
    {
        [$school] = $this->schoolWithApplicantAndStaff('aduight');
        $this->signIn($school);

        $this->followingRedirects()
            ->post("http://{$school->slug}.localhost/admissions/documents", [])
            ->assertOk()
            ->assertSee('Application complete.')
            ->assertDontSee('The school will review your documents shortly.');
    }

    public function test_the_completion_message_still_mentions_review_when_something_was_uploaded(): void
    {
        [$school] = $this->schoolWithApplicantAndStaff('adunine');
        $this->signIn($school);

        $this->followingRedirects()
            ->post("http://{$school->slug}.localhost/admissions/documents", [
                'birth_certificate' => UploadedFile::fake()->image('birth.jpg'),
            ])
            ->assertOk()
            ->assertSee('The school will review your documents shortly.');
    }
}
