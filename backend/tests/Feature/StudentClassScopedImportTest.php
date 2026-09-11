<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
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
 * Class-scoped bulk student import (2026-09-11, "let's make it possible
 * for one to upload bulk students on a class by class basis too"):
 * reached from a specific class's own page, this locks the destination
 * class for the whole file instead of asking for a Class column on
 * every row like the school-wide import under Students still does.
 */
class StudentClassScopedImportTest extends TestCase
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

    protected function schoolWithMember(string $roleSlug, string $slug = 'clsimp'): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    protected function makeArm(School $school, string $className): ClassArm
    {
        app(TenantManager::class)->use($school);
        $section = Section::firstOrCreate(['name' => 'Section'], ['sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => $className, 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        app(TenantManager::class)->forget();

        return $arm;
    }

    public function test_the_class_scoped_template_has_no_class_column(): void
    {
        [$school, $user] = $this->schoolWithMember('registrar', 'tmplclass');
        $arm = $this->makeArm($school, 'JSS 1');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/students/class/{$arm->id}/template.csv");
        $response->assertOk();

        $header = explode(',', trim($response->getContent()));
        $this->assertNotContains('class', $header);
        $this->assertContains('admission_number', $header);

        // The plain school-wide template is untouched -- still has it.
        $wholeSchool = explode(',', trim(
            $this->actingAs($user)->get("http://{$school->slug}.localhost/students/template.csv")->getContent()
        ));
        $this->assertContains('class', $wholeSchool);
    }

    public function test_import_form_shows_the_locked_class_name(): void
    {
        [$school, $user] = $this->schoolWithMember('registrar', 'formclass');
        $arm = $this->makeArm($school, 'Primary 2');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/students/class/{$arm->id}/import")
            ->assertOk()->assertSee('Primary 2');
    }

    public function test_uploading_with_no_class_column_previews_then_imports_every_row_into_the_locked_class(): void
    {
        [$school, $user] = $this->schoolWithMember('registrar', 'runclass');
        $arm = $this->makeArm($school, 'JSS 2');

        $csv = "admission_number,first_name,last_name,other_names,gender,date_of_birth,email,parent_mobile\n"
            ."CS/001,Ngozi,Eze,,female,,,\n"
            ."CS/002,Femi,Alabi,,male,,,\n";
        $file = UploadedFile::fake()->createWithContent('class-students.csv', $csv);

        $preview = $this->actingAs($user)->post("http://{$school->slug}.localhost/students/class/{$arm->id}/import", [
            'file' => $file,
            'mode' => 'partial',
        ]);
        $preview->assertOk()->assertSee('Ready to import');

        // Nothing written yet -- same preview-first discipline as the
        // school-wide import.
        app(TenantManager::class)->use($school);
        $this->assertSame(0, Student::count());
        app(TenantManager::class)->forget();

        $this->actingAs($user)
            ->post("http://{$school->slug}.localhost/students/class/{$arm->id}/import/confirm")
            ->assertOk()->assertSee('2 imported');

        app(TenantManager::class)->use($school);
        $students = Student::where('current_class_arm_id', $arm->id)->get();
        app(TenantManager::class)->forget();

        $this->assertSame(2, $students->count());
        $this->assertTrue($students->pluck('admission_number')->contains('CS/001'));
        $this->assertTrue($students->pluck('admission_number')->contains('CS/002'));
    }

    /**
     * A stray "class" column in the uploaded file (e.g. a school-wide
     * template reused by mistake) is not a validation error under the
     * scoped importer -- it is simply ignored, since the destination is
     * fixed regardless of what the file says.
     */
    public function test_a_stray_class_column_in_the_file_is_ignored_the_locked_class_always_wins(): void
    {
        [$school, $user] = $this->schoolWithMember('registrar', 'strayclass');
        $rightArm = $this->makeArm($school, 'SS 1');
        $wrongArm = $this->makeArm($school, 'SS 2');

        $csv = "admission_number,first_name,last_name,other_names,gender,date_of_birth,email,parent_mobile,class\n"
            ."SC/001,Bisi,Ojo,,female,,,,{$wrongArm->label()}\n";
        $file = UploadedFile::fake()->createWithContent('mixed.csv', $csv);

        $this->actingAs($user)->post("http://{$school->slug}.localhost/students/class/{$rightArm->id}/import", [
            'file' => $file,
            'mode' => 'partial',
        ])->assertOk()->assertSee('Ready to import');

        $this->actingAs($user)
            ->post("http://{$school->slug}.localhost/students/class/{$rightArm->id}/import/confirm")
            ->assertOk()->assertSee('1 imported');

        app(TenantManager::class)->use($school);
        $student = Student::where('admission_number', 'SC/001')->first();
        app(TenantManager::class)->forget();

        $this->assertSame($rightArm->id, $student->current_class_arm_id);
    }

    public function test_class_scoped_import_requires_the_bulk_permission(): void
    {
        [$school, $user] = $this->schoolWithMember('subject-teacher', 'noclassperm');
        $arm = $this->makeArm($school, 'JSS 3');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/students/class/{$arm->id}/import")
            ->assertForbidden();
    }

    public function test_the_class_show_page_offers_the_import_link_only_with_permission(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'linkclass');
        $arm = $this->makeArm($school, 'JSS 4');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/academics/classes/{$arm->school_class_id}")
            ->assertOk()->assertSee('Import students');

        $teacher = User::factory()->create();
        $teacherRole = $this->rbac->cloneTemplateRole($school, 'subject-teacher');
        $school->users()->attach($teacher->id, ['role_id' => $teacherRole->id, 'status' => 'active']);

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/academics/classes/{$arm->school_class_id}")
            ->assertOk()->assertDontSee('Import students');
    }
}
