<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CustomFieldDefinition;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Admin-defined fields (user request 2026-07-22: "I WANT THE ADMIN TO BE
 * ABLE TO CREATE A NEW FIELD THAT IS NOT THERE BEFORE AND ASSIGN TO
 * STUDENTS, STAFF AND PARENTS").
 */
class CustomFieldsTest extends TestCase
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
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_admin_can_create_a_field_and_it_lists_on_the_management_screen(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cfone');

        $response = $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/custom-fields", [
            'label' => 'Blood Group',
            'type' => 'text',
            'entity_types' => ['student', 'staff'],
        ]);
        $response->assertRedirect();

        app(TenantManager::class)->use($school);
        $field = CustomFieldDefinition::first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($field);
        $this->assertSame('blood_group', $field->field_key);
        $this->assertSame(['student', 'staff'], $field->entity_types);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/settings/custom-fields")
            ->assertOk()->assertSee('Blood Group');
    }

    public function test_a_non_admin_cannot_manage_custom_fields(): void
    {
        [$school, $teacher] = $this->schoolWithMember('teacher', 'cftwo');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/settings/custom-fields")->assertForbidden();
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/settings/custom-fields", [
            'label' => 'X', 'type' => 'text', 'entity_types' => ['student'],
        ])->assertForbidden();
    }

    public function test_a_select_field_requires_at_least_one_option(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cfthree');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/custom-fields", [
            'label' => 'Blood Group', 'type' => 'select', 'entity_types' => ['student'],
        ])->assertSessionHasErrors('options');
    }

    public function test_a_required_custom_field_blocks_and_then_allows_student_creation(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cffour');

        app(TenantManager::class)->use($school);
        CustomFieldDefinition::create([
            'label' => 'Blood Group', 'field_key' => 'blood_group', 'type' => 'text',
            'entity_types' => ['student'], 'is_required' => true,
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/create", [
            'admission_number' => 'ADM/700', 'first_name' => 'Ada', 'last_name' => 'Obi',
        ])->assertSessionHasErrors('custom.blood_group');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/create", [
            'admission_number' => 'ADM/700', 'first_name' => 'Ada', 'last_name' => 'Obi',
            'custom' => ['blood_group' => 'O+'],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $student = Student::where('admission_number', 'ADM/700')->first();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/students/{$student->id}")
            ->assertOk()->assertSee('Blood Group')->assertSee('O+');

        // Editing without changing it must not wipe the stored value.
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/{$student->id}/edit", [
            'admission_number' => 'ADM/700', 'first_name' => 'Ada', 'last_name' => 'Obinna',
            'custom' => ['blood_group' => 'A-'],
        ])->assertRedirect();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/students/{$student->id}")
            ->assertOk()->assertSee('A-');
    }

    public function test_a_field_not_scoped_to_staff_does_not_gate_staff_creation(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cffive');

        app(TenantManager::class)->use($school);
        CustomFieldDefinition::create([
            'label' => 'Blood Group', 'field_key' => 'blood_group', 'type' => 'text',
            'entity_types' => ['student'], 'is_required' => true,
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/create", [
            'first_name' => 'Bode', 'last_name' => 'Ade', 'position' => 'Teacher',
            'email' => 'bode@example.com', 'status' => 'active',
        ])->assertSessionDoesntHaveErrors();
    }

    public function test_a_custom_field_can_be_set_on_staff_and_shows_on_their_profile(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cfsix');

        app(TenantManager::class)->use($school);
        CustomFieldDefinition::create([
            'label' => 'Union Member', 'field_key' => 'union_member', 'type' => 'checkbox',
            'entity_types' => ['staff'],
        ]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/create", [
            'first_name' => 'Bode', 'last_name' => 'Ade', 'position' => 'Teacher',
            'email' => 'bode@example.com', 'status' => 'active', 'custom' => ['union_member' => '1'],
        ]);
        $response->assertSessionDoesntHaveErrors();

        app(TenantManager::class)->use($school);
        $staff = Staff::where('first_name', 'Bode')->first();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/staff/{$staff->id}")
            ->assertOk()->assertSee('Union Member')->assertSee('Yes');
    }

    public function test_a_custom_field_can_be_set_on_a_guardian_via_the_student_profile_page(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cfseven');

        app(TenantManager::class)->use($school);
        CustomFieldDefinition::create([
            'label' => 'Occupation', 'field_key' => 'occupation', 'type' => 'text',
            'entity_types' => ['guardian'],
        ]);
        $student = Student::create(['admission_number' => 'ADM/701', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/{$studentId}/guardians", [
            'name' => 'Mrs Obi', 'relationship' => 'Mother', 'email' => 'mrs.obi@example.com',
            'custom' => ['occupation' => 'Engineer'],
        ])->assertRedirect();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/students/{$studentId}")
            ->assertOk()->assertSee('Occupation')->assertSee('Engineer');
    }

    public function test_bulk_deactivate_removes_a_field_from_forms_without_deleting_recorded_values(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cfeight');

        app(TenantManager::class)->use($school);
        $field = CustomFieldDefinition::create([
            'label' => 'Blood Group', 'field_key' => 'blood_group', 'type' => 'text',
            'entity_types' => ['student'], 'is_required' => true,
        ]);
        $fieldId = $field->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/custom-fields/bulk-action", [
            'field_ids' => [$fieldId], 'action' => 'deactivate',
        ])->assertRedirect();

        // No longer required once deactivated: a student can be created without it.
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/students/create", [
            'admission_number' => 'ADM/702', 'first_name' => 'Chidi', 'last_name' => 'Okoro',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $stillExists = CustomFieldDefinition::whereKey($fieldId)->exists();
        app(TenantManager::class)->forget();
        $this->assertTrue($stillExists);
    }

    public function test_deleting_a_field_definition_removes_its_recorded_values(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'cfnine');

        app(TenantManager::class)->use($school);
        $field = CustomFieldDefinition::create([
            'label' => 'Blood Group', 'field_key' => 'blood_group', 'type' => 'text',
            'entity_types' => ['student'],
        ]);
        $fieldId = $field->id;
        $student = Student::create(['admission_number' => 'ADM/703', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        \App\Models\Tenant\CustomFieldValue::create([
            'custom_field_definition_id' => $fieldId, 'entity_type' => 'student', 'entity_id' => $student->id, 'value' => 'O+',
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->delete("http://{$school->slug}.localhost/settings/custom-fields/{$fieldId}")->assertRedirect();

        app(TenantManager::class)->use($school);
        $valueCount = \App\Models\Tenant\CustomFieldValue::where('custom_field_definition_id', $fieldId)->count();
        app(TenantManager::class)->forget();
        $this->assertSame(0, $valueCount);
    }
}
