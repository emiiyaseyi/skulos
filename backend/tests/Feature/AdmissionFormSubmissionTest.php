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
            ->assertOk()->assertSee('Guardian Name')->assertSee('Step 1 of 3');

        $this->post("http://{$school->slug}.localhost/admissions/form?step=1", [
            'fields' => [$fieldName->id => 'Mrs Bello'],
        ])->assertRedirect("http://{$school->slug}.localhost/admissions/form?step=2");

        $this->get("http://{$school->slug}.localhost/admissions/form?step=2")
            ->assertOk()->assertSee('Known Allergies')->assertSee('Step 2 of 3');

        // Documents (Phase 1f) is now the wizard's own real final step,
        // chained automatically once the custom-field steps are done.
        $this->post("http://{$school->slug}.localhost/admissions/form?step=2", [
            'fields' => [$fieldAllergy->id => 'None'],
        ])->assertRedirect("http://{$school->slug}.localhost/admissions/documents");

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

    public function test_a_geo_field_saves_and_reloads_its_country_state_and_lga(): void
    {
        [$school, , $fieldName] = $this->activeApplicantSchool('afsfive');
        app(TenantManager::class)->use($school);
        $geoField = AdmissionFormField::create(['label' => 'State of Origin', 'field_key' => 'state_of_origin', 'type' => 'geo', 'admission_form_section_id' => $fieldName->admission_form_section_id, 'sequence' => 2]);
        app(TenantManager::class)->forget();
        $this->signIn($school, 'APP/0001', 'secret123');

        $this->get("http://{$school->slug}.localhost/admissions/form?step=1")
            ->assertOk()->assertSee('Country')->assertSee('State');

        $this->post("http://{$school->slug}.localhost/admissions/form?step=1", [
            'fields' => [$fieldName->id => 'Mrs Bello'],
            "field_{$geoField->id}_country" => 'Nigeria',
            "field_{$geoField->id}_state" => 'Lagos',
            "field_{$geoField->id}_lga" => 'Ikeja',
        ]);

        app(TenantManager::class)->use($school);
        $stored = json_decode(AdmissionFormValue::where('admission_form_field_id', $geoField->id)->value('value'), true);
        app(TenantManager::class)->forget();

        $this->assertSame(['country' => 'Nigeria', 'state' => 'Lagos', 'lga' => 'Ikeja'], $stored);
    }

    /**
     * User directive 2026-09-11: "applicant should be able to move from
     * one section to another without using the continue button at the
     * end of the page, so we should have the name of each section at
     * the top and user can click on anyone." Jumping was already safe
     * server-side; this confirms the stepper actually renders the links
     * and that jumping straight to a later step (without ever
     * submitting the earlier one) still works.
     */
    public function test_the_stepper_shows_every_step_and_lets_an_applicant_jump_directly_to_one(): void
    {
        [$school] = $this->activeApplicantSchool('afssix');
        $this->signIn($school, 'APP/0001', 'secret123');

        $this->get("http://{$school->slug}.localhost/admissions/form?step=1")
            ->assertOk()
            ->assertSee('Guardian Info')
            ->assertSee('Health')
            ->assertSee('Documents');

        // Jump straight to step 2 without ever submitting step 1.
        $this->get("http://{$school->slug}.localhost/admissions/form?step=2")
            ->assertOk()->assertSee('Known Allergies');
    }

    /**
     * "Save and continue later" (2026-09-11, user directive): persists
     * whatever is filled in without enforcing the step's own required
     * fields, and sends the applicant to their dashboard rather than the
     * next step.
     */
    public function test_save_and_continue_later_persists_partial_progress_without_requiring_fields(): void
    {
        [$school, , $fieldName] = $this->activeApplicantSchool('afsseven');
        $this->signIn($school, 'APP/0001', 'secret123');

        // fieldName is required; a plain "Continue" would reject this.
        $this->post("http://{$school->slug}.localhost/admissions/form?step=1", [
            'fields' => [$fieldName->id => ''],
            'mode' => 'draft',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect("http://{$school->slug}.localhost/admissions/dashboard");
    }

    public function test_save_and_continue_later_still_saves_whatever_was_actually_typed(): void
    {
        [$school, , $fieldName] = $this->activeApplicantSchool('afseight');
        $this->signIn($school, 'APP/0001', 'secret123');

        $this->post("http://{$school->slug}.localhost/admissions/form?step=1", [
            'fields' => [$fieldName->id => 'Mrs Adeyemi'],
            'mode' => 'draft',
        ])->assertRedirect("http://{$school->slug}.localhost/admissions/dashboard");

        app(TenantManager::class)->use($school);
        $this->assertSame('Mrs Adeyemi', AdmissionFormValue::where('admission_form_field_id', $fieldName->id)->value('value'));
        app(TenantManager::class)->forget();
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
