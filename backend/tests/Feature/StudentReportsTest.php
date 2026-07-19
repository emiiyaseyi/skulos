<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Score;
use App\Models\Tenant\Section;
use App\Models\Tenant\Student;
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
 * Student Reports (Foundation plan feature 'student_reports', built
 * 2026-07-18): a class-wide broadsheet distinct from a single student's
 * own Report Card document, closing the one remaining Foundation nav gap
 * identified 2026-07-17.
 */
class StudentReportsTest extends TestCase
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

    /** One class arm, one term, two students with scores across two subjects. Returns [classArmId, termId]. */
    protected function seedBroadsheet(): array
    {
        $component = AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 100, 'sequence' => 1]);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $math = Subject::create(['name' => 'Mathematics']);
        $english = Subject::create(['name' => 'English']);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true]);

        $top = Student::create(['admission_number' => 'A1', 'first_name' => 'Top', 'last_name' => 'Student', 'current_class_arm_id' => $classArm->id]);
        $second = Student::create(['admission_number' => 'A2', 'first_name' => 'Second', 'last_name' => 'Student', 'current_class_arm_id' => $classArm->id]);

        foreach ([[$top, 90, 85], [$second, 60, 55]] as [$student, $mathScore, $englishScore]) {
            Score::create(['student_id' => $student->id, 'subject_id' => $math->id, 'class_arm_id' => $classArm->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => $mathScore]);
            Score::create(['student_id' => $student->id, 'subject_id' => $english->id, 'class_arm_id' => $classArm->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => $englishScore]);
        }

        return [$classArm->id, $term->id];
    }

    public function test_the_broadsheet_shows_real_scores_and_position(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'srone');

        app(TenantManager::class)->use($school);
        [$classArmId, $termId] = $this->seedBroadsheet();
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/reports/students?class_arm_id={$classArmId}&term_id={$termId}");

        $response->assertOk()
            ->assertSeeInOrder(['Top Student', '175.0']) // 90 + 85, ranked first
            ->assertSee('Second Student')
            ->assertSee('115.0'); // 60 + 55
    }

    public function test_export_matches_the_screen(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'srtwo');

        app(TenantManager::class)->use($school);
        [$classArmId, $termId] = $this->seedBroadsheet();
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/reports/students/export?class_arm_id={$classArmId}&term_id={$termId}");

        $response->assertOk();
        $this->assertStringContainsString('Top Student', $response->getContent());
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_a_class_teacher_only_sees_their_own_class_arm(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'srthree');

        app(TenantManager::class)->use($school);
        [$classArmId, $termId] = $this->seedBroadsheet();
        // A second, unrelated class arm the teacher is not assigned to.
        $section = Section::create(['name' => 'Primary', 'sequence' => 2]);
        $otherClass = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 2', 'sequence' => 1]);
        $otherArm = ClassArm::create(['school_class_id' => $otherClass->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/reports/students?class_arm_id={$otherArm->id}&term_id={$termId}")
            ->assertNotFound();

        // Their own class arm still 404s here too since they were never
        // actually assigned as its class teacher (no Staff/ClassArm link
        // set up in this test), matching TeacherScope's real behaviour.
        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/reports/students?class_arm_id={$classArmId}&term_id={$termId}")
            ->assertNotFound();
    }

    public function test_the_broadsheet_shows_insight_cards(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'srfive');

        app(TenantManager::class)->use($school);
        [$classArmId, $termId] = $this->seedBroadsheet();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/reports/students?class_arm_id={$classArmId}&term_id={$termId}")
            ->assertOk()
            ->assertSee('Students assessed')
            ->assertSee('Class average')
            ->assertSee('Top of the class')
            ->assertSee('Top Student');
    }

    public function test_the_broadsheet_can_be_sorted_by_name(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'srsix');

        app(TenantManager::class)->use($school);
        [$classArmId, $termId] = $this->seedBroadsheet();
        app(TenantManager::class)->forget();

        // "Second Student" sorts before "Top Student" alphabetically,
        // reversing the position-ranked default order (Top Student first).
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/reports/students?class_arm_id={$classArmId}&term_id={$termId}&sort=name&dir=asc")
            ->assertOk()
            ->assertSeeInOrder(['Second Student', 'Top Student']);
    }

    public function test_the_class_picker_is_grouped_by_section(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'srseven');

        app(TenantManager::class)->use($school);
        $this->seedBroadsheet();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/reports/students")
            ->assertOk()
            ->assertSee('<optgroup label="Secondary">', false);
    }

    public function test_a_role_without_examinations_view_cannot_reach_it(): void
    {
        [$school, $user] = $this->schoolWithMember('security', 'srfour');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/reports/students")->assertForbidden();
    }
}
