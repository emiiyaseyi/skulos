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

    public function test_students_index_requires_permission(): void
    {
        [$school, $user] = $this->schoolWithMember('parent', 'noaccess');

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
}
