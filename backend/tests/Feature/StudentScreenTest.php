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
            "admission_number,first_name,last_name,other_names,gender,date_of_birth,email,parent_mobile\n",
            $response->getContent()
        );
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
