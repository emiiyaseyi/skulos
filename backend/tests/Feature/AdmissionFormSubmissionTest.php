<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AdmissionFormField;
use App\Models\Tenant\AdmissionFormSection;
use App\Models\Tenant\AdmissionFormValue;
use App\Models\Tenant\Applicant;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Admissions module, Phase 1e: an applicant fills in the school's own
 * custom application form across multiple steps, coming and going,
 * nothing lost between visits.
 */
class AdmissionFormSubmissionTest extends TestCase
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

    protected function activeApplicantSchool(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        app(TenantManager::class)->use($school);

        $sectionOne = AdmissionFormSection::create(['label' => 'Guardian Info', 'step' => 1, 'sequence' => 1]);
        $fieldName = AdmissionFormField::create(['label' => 'Guardian Name', 'field_key' => 'guardian_name', 'type' => 'text', 'admission_form_section_id' => $sectionOne->id, 'is_required' => true, 'sequence' => 1]);

        $sectionTwo = AdmissionFormSection::create(['label' => 'Health', 'step' => 2, 'sequence' => 1]);
        $fieldAllergy = AdmissionFormField::create(['label' => 'Known Allergies', 'field_key' => 'known_allergies', 'type' => 'textarea', 'admission_form_section_id' => $sectionTwo->id, 'sequence' => 1]);

        $applicant = Applicant::forceCreate([
            'name' => 'Femi Bello', 'phone' => '08099998888', 'admission_number' => 'APP/0001',
            'password' => Hash::make('secret123'), 'account_status' => Applicant::STATUS_ACTIVE, 'expires_at' => now()->addDays(30),
        ]);
        app(TenantManager::class)->forget();

        return [$school, $applicant, $fieldName, $fieldAllergy];
    }

    protected function signIn(School $school, string $identifier, string $password): void
    {
        $this->post("http://{$school->slug}.localhost/admissions/login", ['identifier' => $identifier, 'password' => $password]);
    }

    public function test_an_applicant_can_save_step_one_then_come_back_for_step_two_without_losing_step_one(): void
    {
        [$school, , $fieldName, $fieldAllergy] = $this->activeApplicantSchool('afsone');
        $this->signIn($school, 'APP/0001', 'secret123');

        $this->get("http://{$school->slug}.localhost/admissions/form")
            ->assertOk()->assertSee('Guardian Name')->assertSee('Step 1 of 2');

        $this->post("http://{$school->slug}.localhost/admissions/form?step=1", [
            'fields' => [$fieldName->id => 'Mrs Bello'],
        ])->assertRedirect("http://{$school->slug}.localhost/admissions/form?step=2");

        $this->get("http://{$school->slug}.localhost/admissions/form?step=2")
            ->assertOk()->assertSee('Known Allergies')->assertSee('Step 2 of 2');

        $this->post("http://{$school->slug}.localhost/admissions/form?step=2", [
            'fields' => [$fieldAllergy->id => 'None'],
        ])->assertRedirect("http://{$school->slug}.localhost/admissions/dashboard");

        app(TenantManager::class)->use($school);
        $this->assertSame('Mrs Bello', AdmissionFormValue::where('admission_form_field_id', $fieldName->id)->value('value'), 'step 1 must still be saved after step 2 is submitted');
        $this->assertSame('None', AdmissionFormValue::where('admission_form_field_id', $fieldAllergy->id)->value('value'));
        app(TenantManager::class)->forget();
    }

    public function test_a_required_field_left_blank_is_rejected(): void
    {
        [$school, , $fieldName] = $this->activeApplicantSchool('afstwo');
        $this->signIn($school, 'APP/0001', 'secret123');

        $this->post("http://{$school->slug}.localhost/admissions/form?step=1", [
            'fields' => [$fieldName->id => ''],
        ])->assertSessionHasErrors("fields.{$fieldName->id}");
    }

    public function test_revisiting_a_step_prefills_the_previously_saved_answer(): void
    {
        [$school, $applicant, $fieldName] = $this->activeApplicantSchool('afsthree');
        $this->signIn($school, 'APP/0001', 'secret123');

        $this->post("http://{$school->slug}.localhost/admissions/form?step=1", ['fields' => [$fieldName->id => 'Mr Adekunle']]);

        $this->get("http://{$school->slug}.localhost/admissions/form?step=1")
            ->assertOk()->assertSee('Mr Adekunle', false);
    }

    public function test_a_locked_out_applicant_cannot_reach_the_form(): void
    {
        [$school] = $this->activeApplicantSchool('afsfour');
        $this->signIn($school, 'APP/0001', 'secret123');

        app(TenantManager::class)->use($school);
        Applicant::where('admission_number', 'APP/0001')->update(['account_status' => Applicant::STATUS_ARCHIVED]);
        app(TenantManager::class)->forget();
        app('auth')->forgetGuards();

        $this->get("http://{$school->slug}.localhost/admissions/form")
            ->assertRedirect("http://{$school->slug}.localhost/admissions/login");
    }
}
