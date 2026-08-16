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
     * SSS's Arts & Humanities / Science & Technology / Commercial streams
     * (user report 2026-07-23: "in SSS, all subjects are not done by the
     * all students as we have 3 streams per class"), each with its own
     * subject list, not one list every student in the class shares
     * regardless of stream.
     */
    public function test_each_stream_of_a_multi_stream_class_manages_its_own_subject_list(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadstreams');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Senior Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'SSS 1', 'sequence' => 1]);
        $scienceArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => \App\Models\Tenant\Arm::create(['name' => 'Science & Technology'])->id]);
        $commercialArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => \App\Models\Tenant\Arm::create(['name' => 'Commercial'])->id]);
        $maths = Subject::create(['name' => 'Mathematics']);
        $biology = Subject::create(['name' => 'Biology']);
        $commerce = Subject::create(['name' => 'Commerce']);
        // Mandatory for the whole class, at the old class level, still
        // works as the fallback for a stream with no overrides of its own.
        $class->subjects()->attach($maths->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $classId = $class->id;
        $scienceArmId = $scienceArm->id;
        $commercialArmId = $commercialArm->id;
        $mathsId = $maths->id;
        $biologyId = $biology->id;
        $commerceId = $commerce->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/classes/{$classId}/subjects/attach", [
            'subject_id' => $biologyId, 'is_mandatory' => '1', 'is_examinable' => '1', 'class_arm_id' => $scienceArmId,
        ])->assertRedirect();
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/classes/{$classId}/subjects/attach", [
            'subject_id' => $commerceId, 'is_mandatory' => '1', 'is_examinable' => '1', 'class_arm_id' => $commercialArmId,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $scienceSubjects = ClassArm::find($scienceArmId)->offeredSubjects()->pluck('name');
        $commercialSubjects = ClassArm::find($commercialArmId)->offeredSubjects()->pluck('name');
        app(TenantManager::class)->forget();

        $this->assertTrue($scienceSubjects->contains('Biology'));
        $this->assertFalse($scienceSubjects->contains('Commerce'), 'the science stream must never inherit the commercial stream\'s own subjects');
        $this->assertTrue($commercialSubjects->contains('Commerce'));
        $this->assertFalse($commercialSubjects->contains('Biology'), 'the commercial stream must never inherit the science stream\'s own subjects');
        // Mathematics was only ever attached at the class level, and
        // neither stream has touched it directly, so both correctly fall
        // back to the class's own list for it rather than losing it.
        $this->assertTrue($scienceSubjects->contains('Mathematics'));
        $this->assertTrue($commercialSubjects->contains('Mathematics'));
    }

    /** A class with no named streams at all (the vast majority) must be completely unaffected by the per stream override mechanism. */
    public function test_a_single_stream_class_is_unaffected_by_per_stream_subject_overrides(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadstreamsnone');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Junior Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $subject = Subject::create(['name' => 'Basic Science']);
        $class->subjects()->attach($subject->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $classId = $class->id;
        $armId = $arm->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/academics/classes/{$classId}/subjects")
            ->assertOk()->assertSee('Basic Science')->assertDontSee('Pick a stream above');

        app(TenantManager::class)->use($school);
        $offered = ClassArm::find($armId)->offeredSubjects()->pluck('name');
        app(TenantManager::class)->forget();
        $this->assertTrue($offered->contains('Basic Science'));
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

    /**
     * User report 2026-07-21: "I renamed the first class stream to
     * Primary 1 silver and the main class name added silver to it and
     * the second stream also added silver to it. Each stream should be a
     * unique class under the main class name." Renaming one class's
     * stream must never touch the class's own name, and must never
     * rename another CLASS's same-labelled stream, since Arm rows are
     * shared/reused across classes by design.
     */
    public function test_renaming_one_streams_never_touches_the_class_name_or_another_classs_same_named_stream(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadrenameone');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $classOne = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $classTwo = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 2', 'sequence' => 2]);
        $armGold = \App\Models\Tenant\Arm::create(['name' => 'Gold']);
        $classArmOne = ClassArm::create(['school_class_id' => $classOne->id, 'arm_id' => $armGold->id]);
        // Primary 2's own "Gold" stream, sharing the exact same Arm row,
        // the normal "reuse the same label across classes" behavior.
        ClassArm::create(['school_class_id' => $classTwo->id, 'arm_id' => $armGold->id]);
        $classArmOneId = $classArmOne->id;
        $classOneId = $classOne->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/arms/{$classArmOneId}/rename", [
            'name' => 'Silver',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $renamedArm = ClassArm::find($classArmOneId)->arm;
        $classOneName = SchoolClass::find($classOneId)->name;
        $classTwoStillGold = ClassArm::where('school_class_id', $classTwo->id)->first()->arm->name;
        app(TenantManager::class)->forget();

        $this->assertSame('Silver', $renamedArm->name);
        $this->assertSame('Primary 1', $classOneName); // untouched
        $this->assertSame('Gold', $classTwoStillGold); // untouched, not silently renamed too
    }

    public function test_renaming_a_stream_to_an_already_used_name_within_the_same_class_fails_gracefully(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadrenametwo');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $armGold = \App\Models\Tenant\Arm::create(['name' => 'Gold']);
        $armSilver = \App\Models\Tenant\Arm::create(['name' => 'Silver']);
        $goldArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $armGold->id]);
        ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $armSilver->id]);
        $goldArmId = $goldArm->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/arms/{$goldArmId}/rename", [
            'name' => 'Silver',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $stillGold = ClassArm::find($goldArmId)->arm->name;
        app(TenantManager::class)->forget();
        $this->assertSame('Gold', $stillGold);
    }

    /**
     * Regression test for a 2026-07-11 request: adding the first named
     * stream to a class that only has the default single stream (created
     * automatically by storeClass()) must convert that stream, not
     * duplicate it, e.g. "JSS 1" splitting into "JSS 1 A" should not
     * leave an orphaned unnamed stream sitting alongside "JSS 1 A".
     */
    public function test_adding_the_first_stream_converts_the_default_single_stream_not_duplicates_it(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadseventeen');

        // Via the real endpoint, so the default ClassArm is actually created.
        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        app(TenantManager::class)->forget();
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/classes", [
            'section_id' => $section->id, 'name' => 'JSS 1',
        ]);

        app(TenantManager::class)->use($school);
        $class = SchoolClass::where('name', 'JSS 1')->first();
        $defaultArm = ClassArm::where('school_class_id', $class->id)->first();
        $student = \App\Models\Tenant\Student::create([
            'admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi',
            'current_class_arm_id' => $defaultArm->id,
        ]);
        $classId = $class->id;
        $defaultArmId = $defaultArm->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/arms", [
            'school_class_id' => $classId, 'name' => 'A',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $arms = ClassArm::where('school_class_id', $classId)->get();
        $studentAfter = \App\Models\Tenant\Student::find($student->id);
        app(TenantManager::class)->forget();

        $this->assertCount(1, $arms, 'the default stream should be converted, not duplicated');
        $this->assertSame($defaultArmId, $arms->first()->id, 'the same ClassArm row should be reused, not replaced');
        $this->assertNotNull($arms->first()->arm_id);
        $this->assertSame($defaultArmId, $studentAfter->current_class_arm_id, 'the already-enrolled student must stay correctly assigned');
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

    /**
     * Regression test for a 2026-07-11 finding: making a term current used
     * to leave its own session's is_current flag untouched, so a term
     * could be "current" while belonging to a session that was not, an
     * inconsistent state anything reading both scopes could disagree on.
     */
    public function test_making_a_term_current_also_makes_its_session_current(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadeighteen');
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sessions", ['start_year' => 2024]);
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sessions", ['start_year' => 2025]);

        app(TenantManager::class)->use($school);
        $newerSession = AcademicSession::where('name', '2025/2026 Session')->first();
        $termInNewerSession = Term::where('academic_session_id', $newerSession->id)->first();
        app(TenantManager::class)->forget();

        // 2024/2025 Session is current by default (created first); making a
        // term that belongs to the newer session current should bring the
        // newer session along with it, not leave the older one "current"
        // while a term of the newer one also claims to be.
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/terms/{$termInNewerSession->id}/make-current");

        app(TenantManager::class)->use($school);
        $newerSession = $newerSession->fresh();
        $olderSession = AcademicSession::where('name', '2024/2025 Session')->first();
        app(TenantManager::class)->forget();

        $this->assertTrue($newerSession->is_current);
        $this->assertFalse($olderSession->is_current);
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
        $this->assertSame(3, $count); // Creche/Reception, Nursery 1, Nursery 2, not 6
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

    /**
     * Regression test for a live bug found 2026-07-12 ("Class Order &
     * Structure.docx"): SS classes previously reset their sequence to
     * 1, 2, 3 same as JSS's, colliding within the shared Secondary
     * section. SSS 1-3 must sort strictly after JSS 1-3.
     */
    public function test_ss_classes_sort_strictly_after_jss_classes_in_the_shared_section(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadseventeen');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/class-structure", [
            'tiers' => ['jss', 'ss'],
        ]);

        app(TenantManager::class)->use($school);
        $names = SchoolClass::whereHas('section', fn ($q) => $q->where('name', 'Secondary'))
            ->orderBy('sequence')->pluck('name')->all();
        app(TenantManager::class)->forget();

        $this->assertSame(['JSS 1', 'JSS 2', 'JSS 3', 'SSS 1', 'SSS 2', 'SSS 3'], $names);
    }

    /**
     * Regression test for the same live bug: a Section's sequence must
     * follow the canonical Nursery/Primary/Junior/Senior order regardless
     * of which tier an admin ticks first, not "whichever gets applied
     * first wins position 1."
     */
    public function test_section_order_is_canonical_regardless_of_application_order(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadeighteen');

        // Deliberately loaded out of pedagogical order.
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/class-structure", ['tiers' => ['primary']]);
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/class-structure", ['tiers' => ['nursery']]);

        app(TenantManager::class)->use($school);
        $names = Section::orderBy('sequence')->pluck('name')->all();
        app(TenantManager::class)->forget();

        $this->assertSame(['Nursery', 'Primary'], $names, 'Nursery must sort before Primary even though Primary was loaded first');
    }

    /** Regression test for the same live bug: subjects must follow curriculum order, not alphabetical. */
    public function test_a_classs_subjects_follow_curriculum_order_not_alphabetical(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadnineteen');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/class-structure", ['tiers' => ['primary']]);

        app(TenantManager::class)->use($school);
        $class = SchoolClass::where('name', 'Primary 1')->first();
        $names = $class->subjects()->pluck('name')->all();
        app(TenantManager::class)->forget();

        $this->assertSame('English Studies', $names[0], 'the catalog lists English Studies first, not alphabetically (Basic Digital Literacy)');
        $this->assertSame('Mathematics', $names[1]);
    }

    /** realignSequences() must only correct rows that already exist, never invent a tier a school never loaded. */
    public function test_realign_sequences_never_creates_a_tier_the_school_never_loaded(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'acadtwenty');

        app(TenantManager::class)->use($school);
        Section::create(['name' => 'Primary', 'sequence' => 99]);
        $result = app(\App\Services\Academics\AcademicDefaults::class)->realignSequences();
        $sectionCount = Section::count();
        $primary = Section::where('name', 'Primary')->first();
        app(TenantManager::class)->forget();

        $this->assertSame(1, $sectionCount, 'no Nursery/Secondary section should be invented for a school that never had one');
        $this->assertSame(2, $primary->sequence, 'the existing Primary section\'s wrong sequence should be corrected to the canonical value');
        $this->assertSame(1, $result['sections']);
    }

    /** An admin can rename a section or move it to a different position (there was previously no way to fix a section's own order once created). */
    public function test_admin_can_rename_and_reorder_a_section(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadtwentyone');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Early Years', 'sequence' => 5]);
        $sectionId = $section->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sections/{$sectionId}", [
            'name' => 'Creche',
            'sequence' => 1,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = Section::find($sectionId);
        app(TenantManager::class)->forget();

        $this->assertSame('Creche', $updated->name);
        $this->assertSame(1, $updated->sequence);
    }

    /** An admin can manually move a subject to a different position within a class's list. */
    public function test_admin_can_reorder_a_subject_within_a_class(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadtwentytwo');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $subject = Subject::create(['name' => 'Civic Education', 'sequence' => 50]);
        $class->subjects()->attach($subject->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $classId = $class->id;
        $subjectId = $subject->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/classes/{$classId}/subjects/{$subjectId}", [
            'is_mandatory' => '1',
            'is_examinable' => '1',
            'sequence' => 1,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = Subject::find($subjectId);
        app(TenantManager::class)->forget();

        $this->assertSame(1, $updated->sequence);
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

    /**
     * Class edit/rename/retire/delete plus a detail page (user request
     * 2026-07-11: "there is no way to delete, edit, rename, retire etc a
     * class").
     */
    public function test_a_class_can_be_renamed_retired_and_reactivated(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadnineteen');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Basic 1', 'sequence' => 1]);
        $classId = $class->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/classes/{$classId}", [
            'name' => 'Primary 1',
        ])->assertRedirect();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/classes/{$classId}/toggle-active")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = SchoolClass::find($classId);
        app(TenantManager::class)->forget();

        $this->assertSame('Primary 1', $updated->name);
        $this->assertFalse($updated->is_active);
    }

    public function test_a_class_with_enrolled_students_cannot_be_deleted(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadtwenty');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        \App\Models\Tenant\Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $arm->id]);
        $classId = $class->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->delete("http://{$school->slug}.localhost/academics/classes/{$classId}")
            ->assertRedirect()->assertSessionHas('error');

        app(TenantManager::class)->use($school);
        $stillExists = SchoolClass::find($classId);
        app(TenantManager::class)->forget();
        $this->assertNotNull($stillExists);
    }

    public function test_a_class_with_no_students_can_be_deleted(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadtwentyone');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 2', 'sequence' => 2]);
        $classId = $class->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->delete("http://{$school->slug}.localhost/academics/classes/{$classId}")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $gone = SchoolClass::find($classId);
        app(TenantManager::class)->forget();
        $this->assertNull($gone);
    }

    public function test_the_class_detail_page_shows_the_teacher_and_subjects(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadtwentytwo');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 3', 'sequence' => 3]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        \App\Models\Tenant\Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $arm->id]);
        $staff = \App\Models\Tenant\Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tunde', 'last_name' => 'Bello', 'position' => 'Class Teacher', 'status' => 'active']);
        $arm->update(['class_teacher_staff_id' => $staff->id]);
        $classId = $class->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/academics/classes/{$classId}")
            ->assertOk()
            ->assertSee('Tunde Bello');
    }

    /**
     * Reversal, recorded deliberately: on 2026-07-25 the roster was
     * REMOVED from this page ("the student name/list should not be
     * seen... it is a repetition of the student portal section"). On
     * 2026-08-16 it was asked for again -- "one should see a section
     * where it shows all the students in that class" -- so it is back,
     * with the scoping that makes it safe to show.
     *
     * What matters now is not whether names appear, but WHOSE: a
     * teacher restricted to their own class arms must not see another
     * class's children, and parent contact details appear only for a
     * role permitted to see them.
     */
    public function test_the_class_page_lists_its_students(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadroster');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 3', 'sequence' => 3]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        \App\Models\Tenant\Student::create(['admission_number' => 'A2', 'first_name' => 'Chika', 'last_name' => 'Nwosu', 'current_class_arm_id' => $arm->id]);
        $classId = $class->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/academics/classes/{$classId}")
            ->assertOk()
            ->assertSee('Chika Nwosu')
            ->assertSee('Students in this class');
    }

    public function test_a_parent_opening_a_class_sees_only_their_own_child(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'acadrosterparent');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 4', 'sequence' => 4]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        $mine = \App\Models\Tenant\Student::create(['admission_number' => 'B1', 'first_name' => 'Ada', 'last_name' => 'Mine', 'current_class_arm_id' => $arm->id]);
        \App\Models\Tenant\Student::create(['admission_number' => 'B2', 'first_name' => 'Bola', 'last_name' => 'Other', 'current_class_arm_id' => $arm->id]);
        \App\Models\Tenant\StudentGuardian::create([
            'student_id' => $mine->id, 'name' => 'Parent One',
            'guardian_user_id' => $parent->id, 'is_primary' => true,
        ]);
        $classId = $class->id;
        app(TenantManager::class)->forget();

        // A classmate's name on a parent's screen would be a real
        // disclosure, so this is asserted directly rather than assumed
        // from the scope helper being called.
        $this->actingAs($parent)->get("http://{$school->slug}.localhost/academics/classes/{$classId}")
            ->assertOk()
            ->assertSee('Ada Mine')
            ->assertDontSee('Bola Other');
    }

    public function test_assigning_a_class_teacher_updates_the_arm(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadtwentythree');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 4', 'sequence' => 4]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        $staff = \App\Models\Tenant\Staff::create(['staff_id' => 'STF/0002', 'first_name' => 'Bimpe', 'last_name' => 'Ade', 'position' => 'Teacher', 'status' => 'active']);
        $armId = $arm->id;
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/arms/{$armId}/assign-teacher", [
            'role' => 'assistant_teacher', 'staff_id' => $staffId,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = ClassArm::find($armId);
        app(TenantManager::class)->forget();
        $this->assertSame($staffId, $updated->assistant_teacher_staff_id);
    }

    /**
     * Clicking a subject and picking a teacher (user request 2026-07-11:
     * "one should be able to click on the subject and select the subject
     * teacher") applies that teacher across every stream of the class in
     * one action.
     */
    public function test_assigning_a_subject_teacher_applies_to_every_stream(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadtwentyfive');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        // Adding the first named stream converts the default single
        // stream rather than creating a second row (see the class arm
        // conversion test elsewhere in this file), so a second, genuinely
        // new arm needs a second named stream added on top of that one.
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/arms", ['school_class_id' => $class->id, 'name' => 'A']);
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/arms", ['school_class_id' => $class->id, 'name' => 'B']);
        $subject = Subject::create(['name' => 'Mathematics']);
        $class->subjects()->attach($subject->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $staff = \App\Models\Tenant\Staff::create(['staff_id' => 'STF/0010', 'first_name' => 'Kunle', 'last_name' => 'Ade', 'position' => 'Teacher', 'status' => 'active']);
        $classId = $class->id;
        $subjectId = $subject->id;
        $staffId = $staff->id;
        $armIds = ClassArm::where('school_class_id', $classId)->pluck('id');
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/classes/{$classId}/subjects/{$subjectId}/assign-teacher", [
            'staff_id' => $staffId,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $count = \App\Models\Tenant\TeachingAssignment::where('subject_id', $subjectId)->where('staff_id', $staffId)->whereIn('class_arm_id', $armIds)->count();
        app(TenantManager::class)->forget();

        $this->assertSame(2, $count, 'both streams (default + B) must get the assignment');
    }

    public function test_bulk_assigning_subject_teachers_via_csv(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadtwentysix');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 2', 'sequence' => 2]);
        $arm = ClassArm::where('school_class_id', $class->id)->first() ?? ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        $subject = Subject::create(['name' => 'English Language']);
        $class->subjects()->attach($subject->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $staff = \App\Models\Tenant\Staff::create(['staff_id' => 'STF/0011', 'first_name' => 'Ngozi', 'last_name' => 'Eze', 'position' => 'Teacher', 'status' => 'active']);
        $staffId = $staff->id;
        $armId = $arm->id;
        $subjectId = $subject->id;
        app(TenantManager::class)->forget();

        $csv = "class,subject,teacher_staff_id\nJSS 2,English Language,{$staffId}\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('assignments.csv', $csv);

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/subject-teachers/bulk-assign", ['file' => $file])
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $exists = \App\Models\Tenant\TeachingAssignment::where('subject_id', $subjectId)->where('class_arm_id', $armId)->where('staff_id', $staffId)->exists();
        app(TenantManager::class)->forget();

        $this->assertTrue($exists);
    }

    public function test_bulk_assign_reports_an_unknown_class_or_subject(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'acadtwentyseven');

        $csv = "class,subject,teacher_staff_id\nNonexistent Class,Mathematics,1\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('assignments.csv', $csv);

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/subject-teachers/bulk-assign", ['file' => $file])
            ->assertRedirect()->assertSessionHas('error');
    }

    public function test_a_teacher_without_academics_update_cannot_rename_or_delete_a_class(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'acadtwentyfour');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 5', 'sequence' => 5]);
        $classId = $class->id;
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/academics/classes/{$classId}", ['name' => 'Renamed'])
            ->assertForbidden();
        $this->actingAs($teacher)->delete("http://{$school->slug}.localhost/academics/classes/{$classId}")
            ->assertForbidden();
    }
}
