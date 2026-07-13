<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The students list + bulk CSV import UI (not just the underlying service,
 * which StudentTest already covers), including RBAC gating on the pages.
 */
class StudentScreenTest extends TestCase
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

    protected function schoolWithMember(string $roleSlug, string $slug = 'roll'): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_students_index_lists_and_searches(): void
    {
        [$school, $user] = $this->schoolWithMember('registrar');
        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'ADM/001', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        Student::create(['admission_number' => 'ADM/002', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/students");
        $response->assertOk()->assertSee('ADM/001')->assertSee('ADM/002');

        $filtered = $this->actingAs($user)->get("http://{$school->slug}.localhost/students?q=Ada");
        $filtered->assertOk()->assertSee('ADM/001')->assertDontSee('ADM/002');
    }

    /**
     * Browsing (no search) groups students by section then class (user
     * request 2026-07-11: "all students should be categorized by their
     * section... first and then their classes while we retain the search
     * by name and admission number box"). A search still returns a flat
     * list, grouping the few matches across a dozen classes would be
     * worse than just showing them.
     */
    public function test_browsing_without_a_search_groups_students_by_section_and_class(): void
    {
        [$school, $user] = $this->schoolWithMember('registrar', 'groupone');

        app(TenantManager::class)->use($school);
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        Student::create(['admission_number' => 'P1/001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $arm->id]);
        Student::create(['admission_number' => 'P1/002', 'first_name' => 'Bode', 'last_name' => 'Ade', 'current_class_arm_id' => $arm->id]);
        Student::create(['admission_number' => 'NOCLASS/001', 'first_name' => 'Chika', 'last_name' => 'Uzo']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/students");

        $response->assertOk()
            ->assertSee('Primary')
            ->assertSee('Primary 1')
            ->assertSee('2') // headcount for Primary 1
            ->assertDontSee('Ada Obi') // individual names not listed under a grouped class row
            ->assertSee('No class assigned')
            ->assertSee('Chika Uzo'); // unassigned students are still individually listed
    }

    /**
     * "security" holds no grants at all (config('skulos.roles')), a
     * genuinely permission-less role — "parent" used to fill this role
     * in the test but now legitimately holds students.view of its own
     * (scoped to their own children only, user request 2026-07-13,
     * "BUILD THE FULL STUDENT AND PARENT PORTAL"), so it no longer
     * exercises "no permission at all".
     */
    public function test_students_index_requires_permission(): void
    {
        [$school, $user] = $this->schoolWithMember('security', 'noaccess');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/students")->assertForbidden();
    }

    public function test_import_form_hides_bulk_button_without_permission(): void
    {
        // subject-teacher can view students but has no bulk permission.
        [$school, $user] = $this->schoolWithMember('subject-teacher', 'viewonly');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/students")
            ->assertOk()->assertDontSee('Bulk import');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/students/import")->assertForbidden();
    }

    public function test_template_download_matches_importer_columns(): void
    {
        [$school, $user] = $this->schoolWithMember('registrar', 'tmplt');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/students/template.csv");
        $response->assertOk();
        $this->assertSame(
            "admission_number,first_name,last_name,other_names,gender,date_of_birth,email,parent_mobile,class,house,state_of_origin,lga,address\n",
            $response->getContent()
        );
    }

    public function test_admin_can_create_a_student_via_the_single_form(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'createone');

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/students/create")->assertOk();

        $response = $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/create", [
            'admission_number' => 'ADM/900',
            'first_name' => 'Ada',
            'last_name' => 'Obi',
        ]);
        $response->assertRedirect();

        app(TenantManager::class)->use($school);
        $student = Student::where('admission_number', 'ADM/900')->first();
        app(TenantManager::class)->forget();
        $this->assertNotNull($student);
    }

    public function test_the_single_form_enforces_admin_configured_required_fields(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'createtwo');

        app(TenantManager::class)->use($school);
        \App\Models\Tenant\Setting::put('student_required_fields', ['gender']);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/create", [
            'admission_number' => 'ADM/901',
            'first_name' => 'Ada',
            'last_name' => 'Obi',
            // gender deliberately omitted
        ])->assertSessionHasErrors('gender');

        app(TenantManager::class)->use($school);
        $exists = Student::where('admission_number', 'ADM/901')->exists();
        app(TenantManager::class)->forget();
        $this->assertFalse($exists);
    }

    public function test_admin_can_view_and_edit_a_students_profile(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'profileone');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'ADM/902', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/students/{$studentId}")
            ->assertOk()->assertSee('Ada Obi')->assertSee('ADM/902');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$studentId}/edit", [
            'admission_number' => 'ADM/902',
            'first_name' => 'Ada',
            'last_name' => 'Obinna',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = Student::find($studentId);
        app(TenantManager::class)->forget();
        $this->assertSame('Obinna', $updated->last_name);
    }

    public function test_a_guardian_can_be_added_from_the_profile_page(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'profiletwo');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'ADM/903', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        // Email is this school's default parent identifier and is
        // therefore required, alongside the optional phone number.
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$studentId}/guardians", [
            'name' => 'Mrs Obi', 'relationship' => 'Mother', 'phone' => '08000000000', 'email' => 'mrsobi@example.com', 'is_primary' => '1',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $guardian = $student->fresh()->guardians()->first();
        app(TenantManager::class)->forget();
        $this->assertSame('Mrs Obi', $guardian->name);
        $this->assertTrue($guardian->is_primary);
    }

    /**
     * A school can choose phone as its parent identifier instead of email
     * (user request 2026-07-11: "the admin can determine what the unique
     * ID is, either email address or phone number... the school must
     * stick to one only"). Once set to phone, email becomes optional and
     * phone becomes the required field for a new guardian.
     */
    public function test_a_school_can_switch_its_parent_identifier_to_phone(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'identifierone');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/settings", [
            'parent_identifier' => 'phone',
            'staff_identifier' => 'email',
            'guardian_visibility' => 'class_teacher_only',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'ADM/950', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        // Phone only, no email, must now succeed.
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$studentId}/guardians", [
            'name' => 'Mr Obi', 'relationship' => 'Father', 'phone' => '08011112222',
        ])->assertRedirect()->assertSessionHasNoErrors();

        app(TenantManager::class)->use($school);
        $guardian = Student::find($studentId)->guardians()->first();
        app(TenantManager::class)->forget();
        $this->assertSame('08011112222', $guardian->phone);
        $this->assertNull($guardian->email);
    }

    public function test_the_default_parent_identifier_is_email_and_is_enforced(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'identifiertwo');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'ADM/951', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        // No email, no explicit identifier setting change: must be rejected.
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$studentId}/guardians", [
            'name' => 'Mrs Ade', 'relationship' => 'Mother', 'phone' => '08033334444',
        ])->assertSessionHasErrors('email');
    }

    public function test_a_teacher_without_students_create_cannot_add_a_student(): void
    {
        // subject-teacher's grants include students.view but not students.create.
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'createthree');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/students/create")->assertForbidden();
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/students/create", [
            'admission_number' => 'ADM/904', 'first_name' => 'X', 'last_name' => 'Y',
        ])->assertForbidden();
    }

    public function test_admin_can_view_and_update_which_student_fields_are_required(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'fieldsettings');

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/students/settings")
            ->assertOk()->assertSee('State of Origin');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/settings", [
            'required_fields' => ['class', 'lga'],
            'parent_identifier' => 'email',
            'staff_identifier' => 'email',
            'guardian_visibility' => 'class_teacher_only',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $stored = \App\Models\Tenant\Setting::grab('student_required_fields', []);
        app(TenantManager::class)->forget();
        $this->assertSame(['class', 'lga'], $stored);
    }

    public function test_a_teacher_cannot_manage_student_field_settings(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'fieldsettingsblocked');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/students/settings")->assertForbidden();
    }

    public function test_full_upload_and_import_cycle_via_the_controller(): void
    {
        [$school, $user] = $this->schoolWithMember('registrar', 'upload');

        $csv = "admission_number,first_name,last_name,other_names,gender,date_of_birth,email,parent_mobile\n"
            ."ADM/300,Chika,Nwosu,,female,,chika@example.com,\n"
            .",Bad,Row,,,,,\n";
        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $response = $this->actingAs($user)->post("http://{$school->slug}.localhost/students/import", [
            'file' => $file,
            'mode' => 'partial',
        ]);

        $response->assertOk()->assertSee('1 imported')->assertSee('to fix');

        app(TenantManager::class)->use($school);
        $this->assertTrue(Student::where('admission_number', 'ADM/300')->exists());
        app(TenantManager::class)->forget();
    }

    public function test_strict_mode_upload_imports_nothing_when_a_row_is_bad(): void
    {
        [$school, $user] = $this->schoolWithMember('registrar', 'strictup');

        $csv = "admission_number,first_name,last_name,other_names,gender,date_of_birth,email,parent_mobile\n"
            ."ADM/400,Ok,Row,,,,,\n"
            .",Bad,Row,,,,,\n";
        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $this->actingAs($user)->post("http://{$school->slug}.localhost/students/import", [
            'file' => $file,
            'mode' => 'strict',
        ])->assertOk()->assertSee('Nothing was imported');

        app(TenantManager::class)->use($school);
        $this->assertSame(0, Student::count());
        app(TenantManager::class)->forget();
    }

    /** Bulk actions (user directive: every list gets bulk actions), the flat search-results table gets status change and soft delete. */
    public function test_bulk_status_change_and_delete_from_search_results(): void
    {
        [$school, $user] = $this->schoolWithMember('registrar', 'bulkone');

        app(TenantManager::class)->use($school);
        $one = Student::create(['admission_number' => 'ADM/500', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $two = Student::create(['admission_number' => 'ADM/501', 'first_name' => 'Ada', 'last_name' => 'Okoro']);
        app(TenantManager::class)->forget();

        $this->actingAs($user)->post("http://{$school->slug}.localhost/students/bulk-action", [
            'student_ids' => [$one->id], 'action' => 'graduated',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame('graduated', Student::find($one->id)->status);
        app(TenantManager::class)->forget();

        $this->actingAs($user)->post("http://{$school->slug}.localhost/students/bulk-action", [
            'student_ids' => [$two->id], 'action' => 'delete',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertNull(Student::find($two->id));
        $this->assertNotNull(Student::onlyTrashed()->find($two->id));
        app(TenantManager::class)->forget();
    }

    /**
     * Undo/restore (user request 2026-07-12, "To Do List 2" doc item 3): a
     * bulk delete must not be a one-way door.
     */
    public function test_a_deleted_student_can_be_restored_from_trashed(): void
    {
        [$school, $user] = $this->schoolWithMember('registrar', 'bulktwo');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'ADM/502', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($user)->post("http://{$school->slug}.localhost/students/bulk-action", [
            'student_ids' => [$studentId], 'action' => 'delete',
        ])->assertRedirect();

        $this->actingAs($user)->get("http://{$school->slug}.localhost/students/trashed")
            ->assertOk()->assertSee('Ada Obi');

        $this->actingAs($user)->post("http://{$school->slug}.localhost/students/trashed/bulk-action", [
            'student_ids' => [$studentId], 'action' => 'restore',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $restored = Student::find($studentId);
        app(TenantManager::class)->forget();
        $this->assertNotNull($restored);
        $this->assertSame('Ada Obi', $restored->fullName());
    }

    /** Permanently deleting a student cascades to their guardians (constrained cascadeOnDelete in the migration), never leaves an orphaned guardian row. */
    public function test_permanently_deleting_a_student_removes_their_guardians_too(): void
    {
        [$school, $user] = $this->schoolWithMember('registrar', 'bulkthree');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'ADM/503', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentId = $student->id;
        $student->guardians()->create(['name' => 'Mrs Obi', 'email' => 'mrsobi@example.com', 'is_primary' => true]);
        $student->delete();
        app(TenantManager::class)->forget();

        $this->actingAs($user)->post("http://{$school->slug}.localhost/students/trashed/bulk-action", [
            'student_ids' => [$studentId], 'action' => 'force-delete',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertNull(Student::withTrashed()->find($studentId));
        $this->assertSame(0, \App\Models\Tenant\StudentGuardian::where('student_id', $studentId)->count());
        app(TenantManager::class)->forget();
    }

    public function test_trashed_page_requires_the_delete_permission_not_just_view(): void
    {
        // bursar's grants include students.view but not students.update/delete.
        [$school, $bursar] = $this->schoolWithMember('bursar', 'bulkfour');

        $this->actingAs($bursar)->get("http://{$school->slug}.localhost/students/trashed")
            ->assertOk()->assertDontSee('Restore selected');
    }
}
