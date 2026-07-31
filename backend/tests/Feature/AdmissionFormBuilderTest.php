<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AdmissionFormField;
use App\Models\Tenant\AdmissionFormSection;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Admissions module, Phase 1e: admin builds the school's own custom
 * application form (sections grouped into steps, fields per section).
 */
class AdmissionFormBuilderTest extends TestCase
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

    protected function schoolWithMember(string $roleSlug, string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'admissions', \App\Models\Module::STATE_ENABLED);
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_owner_can_build_a_section_and_add_fields_to_it(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'afbone');

        $this->actingAs($owner)->post(route('school.admissions.form-builder.sections.store', ['school' => $school->slug]), [
            'label' => 'Guardian Information', 'step' => 1,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $section = AdmissionFormSection::first();
        app(TenantManager::class)->forget();
        $this->assertNotNull($section);
        $this->assertSame(1, $section->step);

        $this->actingAs($owner)->post(route('school.admissions.form-builder.fields.store', ['school' => $school->slug]), [
            'label' => 'Previous School', 'type' => 'text', 'admission_form_section_id' => $section->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $field = AdmissionFormField::first();
        app(TenantManager::class)->forget();
        $this->assertNotNull($field);
        $this->assertSame('previous_school', $field->field_key);
        $this->assertSame($section->id, $field->admission_form_section_id);
    }

    public function test_a_select_field_requires_at_least_one_option(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'afbtwo');

        $this->actingAs($owner)->post(route('school.admissions.form-builder.fields.store', ['school' => $school->slug]), [
            'label' => 'Blood Group', 'type' => 'select',
        ])->assertSessionHasErrors('options');
    }

    public function test_deleting_a_section_keeps_its_fields_unassigned_not_deleted(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'afbthree');

        app(TenantManager::class)->use($school);
        $section = AdmissionFormSection::create(['label' => 'Docs', 'step' => 1]);
        $field = AdmissionFormField::create(['label' => 'Notes', 'field_key' => 'notes', 'type' => 'text', 'admission_form_section_id' => $section->id, 'sequence' => 1]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->delete(route('school.admissions.form-builder.sections.destroy', ['school' => $school->slug, 'sectionId' => $section->id]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertNotNull(AdmissionFormField::find($field->id), 'the field itself must survive its section being removed');
        $this->assertNull(AdmissionFormField::find($field->id)->admission_form_section_id);
        app(TenantManager::class)->forget();
    }

    public function test_the_builder_page_shows_a_live_preview_of_active_fields_only(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'afbfive');

        app(TenantManager::class)->use($school);
        $section = AdmissionFormSection::create(['label' => 'Guardian Information', 'step' => 1]);
        AdmissionFormField::create(['label' => 'Previous School', 'field_key' => 'previous_school', 'type' => 'text', 'admission_form_section_id' => $section->id, 'sequence' => 1]);
        AdmissionFormField::create(['label' => 'Retired Field', 'field_key' => 'retired_field', 'type' => 'text', 'admission_form_section_id' => $section->id, 'sequence' => 2, 'is_active' => false]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get(route('school.admissions.form-builder.index', ['school' => $school->slug]));
        $response->assertOk()->assertSee('Live preview')->assertSee('Previous School');

        // "Retired Field" legitimately still appears further down, in the
        // management table (inactive fields stay listed there so an admin
        // can reactivate them) — so assert its ABSENCE specifically within
        // the live-preview block, not the whole page.
        $body = $response->getContent();
        $previewBlock = substr($body, strpos($body, 'Live preview'), strpos($body, 'Sections and fields') - strpos($body, 'Live preview'));
        $this->assertStringContainsString('Previous School', $previewBlock);
        $this->assertStringNotContainsString('Retired Field', $previewBlock);
    }

    public function test_a_teacher_without_manage_settings_cannot_reach_the_form_builder(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'afbfour');

        $this->actingAs($teacher)->get(route('school.admissions.form-builder.index', ['school' => $school->slug]))
            ->assertForbidden();
    }
}
