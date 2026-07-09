<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Subject;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Sections/classes and the mandatory/examinable subject offering toggles
 * (user request 2026-07-09), plus the "load default subjects" action.
 */
class AcademicsScreenTest extends TestCase
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

    public function test_admin_can_create_a_section_and_a_class(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadone');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sections", ['name' => 'Junior Secondary'])
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $section = Section::where('name', 'Junior Secondary')->first();
        app(TenantManager::class)->forget();
        $this->assertNotNull($section);

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/classes", [
            'section_id' => $section->id, 'name' => 'JSS 1',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertTrue(SchoolClass::where('name', 'JSS 1')->exists());
        app(TenantManager::class)->forget();
    }

    public function test_applying_default_subjects_attaches_them_with_mandatory_and_examinable_flags(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadtwo');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Senior Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'SS 1', 'sequence' => 1]);
        $classId = $class->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/classes/{$classId}/subjects/apply-defaults", [
            'catalog' => 'ss',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $class = SchoolClass::find($classId);
        $offered = $class->subjects()->orderBy('name')->get();
        $mandatory = $offered->firstWhere('name', 'English Language');
        $elective = $offered->firstWhere('name', 'Biology');
        app(TenantManager::class)->forget();

        $this->assertGreaterThan(15, $offered->count());
        $this->assertTrue((bool) $mandatory->pivot->is_mandatory);
        $this->assertFalse((bool) $elective->pivot->is_mandatory);
        $this->assertTrue((bool) $elective->pivot->is_examinable);
    }

    public function test_admin_can_toggle_and_remove_a_subject_offering(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadthree');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'SS 2', 'sequence' => 1]);
        $subject = Subject::create(['name' => 'Further Mathematics']);
        $class->subjects()->attach($subject->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $classId = $class->id;
        $subjectId = $subject->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/classes/{$classId}/subjects/{$subjectId}", [
            'is_mandatory' => '0',
            'is_examinable' => '1',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $pivot = SchoolClass::find($classId)->subjects()->find($subjectId)->pivot;
        app(TenantManager::class)->forget();
        $this->assertFalse((bool) $pivot->is_mandatory);
        $this->assertTrue((bool) $pivot->is_examinable);

        $this->actingAs($admin)->delete("http://{$school->slug}.localhost/academics/classes/{$classId}/subjects/{$subjectId}")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $stillOffered = SchoolClass::find($classId)->subjects()->count();
        app(TenantManager::class)->forget();
        $this->assertSame(0, $stillOffered);
    }

    public function test_a_teacher_without_academics_update_cannot_manage_subjects(): void
    {
        // subject-teacher's grants do not include any academics.* permission.
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'acadfour');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'SS 3', 'sequence' => 1]);
        $classId = $class->id;
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/academics/classes/{$classId}/subjects/apply-defaults", [
            'catalog' => 'ss',
        ])->assertForbidden();
    }
}
