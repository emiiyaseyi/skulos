<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
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

    /**
     * Students CSV export (user directive, 2026-07-16 "ALL THERE": build
     * out the remaining Reports & Analytics "Soon" items — covers
     * export_to_excel/bulk_export, which had no real screen behind them,
     * only the import side existed until now).
     */
    public function test_export_downloads_a_csv_matching_the_importer_columns(): void
    {
        [$school, $user] = $this->schoolWithMember('registrar', 'exportone');

        app(TenantManager::class)->use($school);
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        $house = \App\Models\Tenant\House::create(['name' => 'Falcon']);
        Student::create([
            'admission_number' => 'ADM/001', 'first_name' => 'Ada', 'last_name' => 'Obi',
            'gender' => 'Female', 'current_class_arm_id' => $arm->id, 'house_id' => $house->id,
        ]);
        Student::create(['admission_number' => 'ADM/002', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/students/export.csv");
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $lines = explode("\n", trim($response->getContent()));
        $this->assertSame(
            'admission_number,first_name,last_name,other_names,gender,date_of_birth,email,parent_mobile,class,house,state_of_origin,lga,address',
            $lines[0],
            'the export header must exactly match the importer\'s own columns, so the file round trips back through import'
        );
        // Ordered by last name (Ade before Obi), same as the browse screen.
        $this->assertStringContainsString('ADM/002,Bode,Ade', $lines[1]);
        $this->assertStringContainsString('ADM/001,Ada,Obi,,Female,,,,"Primary 1",Falcon', $lines[2]);
    }

    public function test_export_respects_the_same_search_query_as_the_screen(): void
    {
        [$school, $user] = $this->schoolWithMember('registrar', 'exporttwo');

        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'ADM/001', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        Student::create(['admission_number' => 'ADM/002', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/students/export.csv?q=Ada");
        $response->assertOk();
        $this->assertStringContainsString('ADM/001', $response->getContent());
        $this->assertStringNotContainsString('ADM/002', $response->getContent());
    }

    public function test_export_requires_the_bulk_permission(): void
    {
        // subject-teacher can view students but has no bulk permission.
        [$school, $user] = $this->schoolWithMember('subject-teacher', 'exportthree');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/students/export.csv")->assertForbidden();
    }

    /**
     * class-teacher does not hold students.bulk by default (only
     * students.view/students.update, see config('skulos.roles')), so this
     * grants it directly as an extra permission to actually exercise the
     * TeacherScope narrowing in StudentController::export(), the same
     * defensive scoping the browse screen and bulk-action already apply,
     * in case a school ever grants a scoped teacher role bulk access.
     */
    public function test_a_class_teachers_export_is_scoped_to_their_own_class(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'exportfour');
        $teacherRole = $teacherUser->roleIn($school);
        $bulkPermission = \App\Models\Permission::firstOrCreate(['code' => 'students.bulk'], ['label' => 'Bulk import/export students', 'module' => 'students']);
        $teacherRole->permissions()->attach($bulkPermission->id);

        app(TenantManager::class)->use($school);
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $myArm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        $otherArm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        $staff = \App\Models\Tenant\Staff::create(['staff_id' => 'STF/1', 'first_name' => 'T', 'last_name' => 'Eacher', 'status' => 'active', 'user_id' => $teacherUser->id]);
        $myArm->update(['class_teacher_staff_id' => $staff->id]);
        Student::create(['admission_number' => 'MINE/001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $myArm->id]);
        Student::create(['admission_number' => 'OTHER/001', 'first_name' => 'Bode', 'last_name' => 'Ade', 'current_class_arm_id' => $otherArm->id]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/students/export.csv");
        $response->assertOk();
        $this->assertStringContainsString('MINE/001', $response->getContent());
        $this->assertStringNotContainsString('OTHER/001', $response->getContent());
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

    /**
     * User request 2026-07-21: "Why do we have to manually create login
     * for students... students can login with their admission number and
     * the default password which must be change upon first login."
     */
    public function test_creating_a_student_auto_provisions_a_login_with_the_default_password(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'autologinone');

        $response = $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/create", [
            'admission_number' => 'ADM/950', 'first_name' => 'Ada', 'last_name' => 'Obi',
        ]);
        $response->assertRedirect()->assertSessionHas('status');
        $this->assertStringContainsString('Student@123', session('status'));

        app(TenantManager::class)->use($school);
        $student = Student::where('admission_number', 'ADM/950')->first();
        $this->assertNotNull($student->user_id);
        $user = \App\Models\User::find($student->user_id);
        $this->assertTrue($user->must_change_password);
        app(TenantManager::class)->forget();

        // The real point: signing in with just the admission number + the
        // default password now works, with no separate "create login" step.
        $this->post('/logout');
        $this->post("http://{$school->slug}.localhost/login", [
            'email' => 'ADM/950', 'password' => 'Student@123', 'as' => 'student',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);
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

    /**
     * User report 2026-07-21 audit finding: the consolidated per-student
     * report (grades, attendance, comments) already existed via
     * ReportCardController::show(), but nothing on the student profile
     * page linked to it.
     */
    public function test_the_profile_page_links_to_the_report_card_when_a_current_term_exists(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'profilereport');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'ADM/904', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $noTerm = $this->actingAs($admin)->get("http://{$school->slug}.localhost/students/{$studentId}");
        $noTerm->assertOk()->assertDontSee('Report card');

        app(TenantManager::class)->use($school);
        $session = \App\Models\Tenant\AcademicSession::create(['name' => '2025/2026']);
        $term = \App\Models\Tenant\Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $term->makeCurrent();
        app(TenantManager::class)->forget();

        $withTerm = $this->actingAs($admin)->get("http://{$school->slug}.localhost/students/{$studentId}");
        $withTerm->assertOk()->assertSee('Report card')
            ->assertSee(route('school.report-cards.show', ['school' => $school->slug, 'studentId' => $studentId, 'termId' => $term->id]), false);
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

        // User request 2026-07-21: "Parents can login with either email
        // or phone number", auto-provisioned, no separate "create login" step.
        app(TenantManager::class)->use($school);
        $this->assertNotNull($guardian->guardian_user_id);
        $user = \App\Models\User::find($guardian->guardian_user_id);
        $this->assertTrue($user->must_change_password);
        app(TenantManager::class)->forget();

        $this->post('/logout');
        $this->post("http://{$school->slug}.localhost/login", [
            'email' => 'mrsobi@example.com', 'password' => 'Parent@123', 'as' => 'parent',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    /** Two children sharing one real parent must never get two separate logins, or reset each other's password. */
    public function test_two_guardians_sharing_the_same_email_reuse_one_login(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'sharedparent');

        app(TenantManager::class)->use($school);
        $first = Student::create(['admission_number' => 'ADM/920', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $second = Student::create(['admission_number' => 'ADM/921', 'first_name' => 'Bode', 'last_name' => 'Obi']);
        $firstId = $first->id;
        $secondId = $second->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$firstId}/guardians", [
            'name' => 'Mr Obi', 'relationship' => 'Father', 'email' => 'mr.obi@example.com',
        ])->assertRedirect();
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$secondId}/guardians", [
            'name' => 'Mr Obi', 'relationship' => 'Father', 'email' => 'mr.obi@example.com',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $guardianOne = $first->fresh()->guardians()->first();
        $guardianTwo = $second->fresh()->guardians()->first();
        $userCount = \App\Models\User::where('email', 'mr.obi@example.com')->count();
        app(TenantManager::class)->forget();

        $this->assertSame($guardianOne->guardian_user_id, $guardianTwo->guardian_user_id);
        $this->assertSame(1, $userCount);
    }

    /** The other half of guardian CRUD, added later, only "add" existed before this (2026-07-15). */
    public function test_a_guardians_details_can_be_edited(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'profileguardianedit');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'ADM/904', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $guardian = $student->guardians()->create(['name' => 'Mrs Obi', 'relationship' => 'Mother', 'email' => 'old@example.com', 'is_primary' => false]);
        $guardianId = $guardian->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/guardians/{$guardianId}/edit", [
            'name' => 'Mrs Obi-Ade', 'relationship' => 'Mother', 'email' => 'new@example.com', 'is_primary' => '1',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = StudentGuardian::find($guardianId);
        app(TenantManager::class)->forget();
        $this->assertSame('Mrs Obi-Ade', $updated->name);
        $this->assertSame('new@example.com', $updated->email);
        $this->assertTrue($updated->is_primary);
    }

    /** Removing a guardian must not delete their platform login, they may be linked to other children too. */
    public function test_a_guardian_can_be_removed_without_deleting_their_login(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'profileguardiandelete');
        $guardianUser = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($guardianUser->id, ['role_id' => $role->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'ADM/905', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $guardian = $student->guardians()->create(['name' => 'Mrs Obi', 'guardian_user_id' => $guardianUser->id, 'is_primary' => true]);
        $guardianId = $guardian->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->delete("http://{$school->slug}.localhost/students/guardians/{$guardianId}")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $gone = StudentGuardian::find($guardianId);
        app(TenantManager::class)->forget();
        $this->assertNull($gone);
        $this->assertNotNull(User::find($guardianUser->id), 'the platform login must survive the guardian link being removed');
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
            'default_student_password' => 'Student@123',
            'default_parent_password' => 'Parent@123',
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

    /** User request 2026-07-22: "I want the option of having both as the unique identifier too, both email and phone number." */
    public function test_a_school_can_switch_its_parent_identifier_to_both_and_either_contact_field_satisfies_it(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'identifierboth');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/settings", [
            'parent_identifier' => 'both',
            'staff_identifier' => 'email',
            'guardian_visibility' => 'class_teacher_only',
            'default_student_password' => 'Student@123',
            'default_parent_password' => 'Parent@123',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $studentA = Student::create(['admission_number' => 'ADM/960', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentB = Student::create(['admission_number' => 'ADM/961', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        $studentIdA = $studentA->id;
        $studentIdB = $studentB->id;
        app(TenantManager::class)->forget();

        // Neither given: rejected.
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$studentIdA}/guardians", [
            'name' => 'Mr Obi', 'relationship' => 'Father',
        ])->assertSessionHasErrors('email');

        // Phone only: accepted.
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$studentIdA}/guardians", [
            'name' => 'Mr Obi', 'relationship' => 'Father', 'phone' => '08011112222',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Email only: accepted.
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$studentIdB}/guardians", [
            'name' => 'Mrs Ade', 'relationship' => 'Mother', 'email' => 'mrs.ade@example.com',
        ])->assertRedirect()->assertSessionHasNoErrors();
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
            'default_student_password' => 'Student@123',
            'default_parent_password' => 'Parent@123',
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
