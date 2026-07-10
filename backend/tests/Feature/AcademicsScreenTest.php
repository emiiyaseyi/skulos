<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Subject;
use App\Models\Tenant\Term;
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

    /**
     * Regression test: a class created via the UI used to only ever
     * produce a SchoolClass, never a ClassArm, so it could not actually
     * hold a student (Student.current_class_arm_id points at a ClassArm).
     * Every class now gets one default (unnamed, single stream) ClassArm
     * automatically so it is usable the moment it is created.
     */
    public function test_creating_a_class_also_creates_a_usable_class_arm(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadten');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/classes", [
            'section_id' => $section->id, 'name' => 'Basic 1',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $class = SchoolClass::where('name', 'Basic 1')->first();
        $classArm = ClassArm::where('school_class_id', $class->id)->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($classArm, 'a class must have at least one ClassArm to actually hold students');
        $this->assertNull($classArm->arm_id);
    }

    public function test_a_named_stream_can_be_added_to_a_class(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadeleven');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $classId = $class->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/arms", [
            'school_class_id' => $classId, 'name' => 'B',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $exists = ClassArm::whereHas('arm', fn ($q) => $q->where('name', 'B'))
            ->where('school_class_id', $classId)->exists();
        app(TenantManager::class)->forget();
        $this->assertTrue($exists);
    }

    public function test_the_same_stream_cannot_be_added_twice(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadtwelve');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 2', 'sequence' => 1]);
        $classId = $class->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/arms", ['school_class_id' => $classId, 'name' => 'A']);
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/arms", ['school_class_id' => $classId, 'name' => 'A']);

        app(TenantManager::class)->use($school);
        $count = ClassArm::where('school_class_id', $classId)->count();
        app(TenantManager::class)->forget();
        $this->assertSame(1, $count);
    }

    public function test_creating_a_session_generates_its_three_terms_with_nigerian_naming(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadfive');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sessions", [
            'start_year' => 2025,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $session = AcademicSession::where('name', '2025/2026 Session')->first();
        $this->assertNotNull($session);
        $this->assertTrue($session->is_current, 'the first session created should become current automatically');
        $terms = Term::where('academic_session_id', $session->id)->orderBy('sequence')->pluck('name')->all();
        app(TenantManager::class)->forget();

        $this->assertSame(['First Term 2025/2026', 'Second Term 2025/2026', 'Third Term 2025/2026'], $terms);
    }

    public function test_creating_the_same_session_twice_is_rejected(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadsix');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sessions", ['start_year' => 2025]);
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sessions", ['start_year' => 2025])
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $count = AcademicSession::where('name', '2025/2026 Session')->count();
        app(TenantManager::class)->forget();
        $this->assertSame(1, $count);
    }

    public function test_admin_can_switch_the_current_session_and_term(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadseven');
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sessions", ['start_year' => 2024]);
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sessions", ['start_year' => 2025]);

        app(TenantManager::class)->use($school);
        $olderSession = AcademicSession::where('name', '2024/2025 Session')->first();
        $newerSession = AcademicSession::where('name', '2025/2026 Session')->first();
        $this->assertTrue($olderSession->is_current, 'the first session created should be current by default');
        $secondTermOfNewer = Term::where('academic_session_id', $newerSession->id)->where('sequence', 2)->first();
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sessions/{$newerSession->id}/make-current")
            ->assertRedirect();
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/terms/{$secondTermOfNewer->id}/make-current")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertFalse($olderSession->fresh()->is_current);
        $this->assertTrue($newerSession->fresh()->is_current);
        $this->assertTrue($secondTermOfNewer->fresh()->is_current);
        app(TenantManager::class)->forget();
    }

    public function test_a_custom_term_can_be_added_to_a_session(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadeight');
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sessions", ['start_year' => 2025]);

        app(TenantManager::class)->use($school);
        $session = AcademicSession::first();
        $sessionId = $session->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/terms", [
            'academic_session_id' => $sessionId,
            'name' => 'Summer Term 2025/2026',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $exists = Term::where('academic_session_id', $sessionId)->where('name', 'Summer Term 2025/2026')->exists();
        app(TenantManager::class)->forget();
        $this->assertTrue($exists);
    }

    public function test_a_teacher_without_academics_create_cannot_create_a_session(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'acadnine');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/academics/sessions", ['start_year' => 2025])
            ->assertForbidden();
    }

    public function test_loading_a_default_class_structure_creates_section_classes_arms_and_subjects(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadthirteen');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/class-structure", [
            'tiers' => ['primary'],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $section = Section::where('name', 'Primary')->first();
        $this->assertNotNull($section);
        $classes = SchoolClass::where('section_id', $section->id)->orderBy('sequence')->get();
        $this->assertSame(['Primary 1', 'Primary 2', 'Primary 3', 'Primary 4', 'Primary 5', 'Primary 6'], $classes->pluck('name')->all());

        $firstClass = $classes->first();
        $classArm = ClassArm::where('school_class_id', $firstClass->id)->first();
        $subjectCount = $firstClass->subjects()->count();
        app(TenantManager::class)->forget();

        $this->assertNotNull($classArm, 'each loaded class must have a usable ClassArm');
        $this->assertGreaterThan(5, $subjectCount, 'default subjects should be attached automatically');
    }

    public function test_loading_the_same_tier_twice_does_not_duplicate_classes(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadfourteen');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/class-structure", ['tiers' => ['nursery']]);
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/class-structure", ['tiers' => ['nursery']]);

        app(TenantManager::class)->use($school);
        $count = SchoolClass::whereHas('section', fn ($q) => $q->where('name', 'Nursery'))->count();
        app(TenantManager::class)->forget();
        $this->assertSame(4, $count); // Creche, Pre-Nursery, Nursery 1, Nursery 2, not 8
    }

    public function test_jss_and_ss_share_one_secondary_section(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadfifteen');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/class-structure", [
            'tiers' => ['jss', 'ss'],
        ]);

        app(TenantManager::class)->use($school);
        $sectionCount = Section::where('name', 'Secondary')->count();
        $classCount = SchoolClass::whereHas('section', fn ($q) => $q->where('name', 'Secondary'))->count();
        app(TenantManager::class)->forget();

        $this->assertSame(1, $sectionCount, 'JSS and SS should not create two separate sections');
        $this->assertSame(6, $classCount); // JSS 1-3 + SS 1-3
    }

    public function test_a_teacher_without_academics_create_cannot_load_a_class_structure(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'acadsixteen');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/academics/class-structure", [
            'tiers' => ['primary'],
        ])->assertForbidden();
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
