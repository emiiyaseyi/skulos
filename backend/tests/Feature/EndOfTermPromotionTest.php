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
use App\Models\Tenant\Setting;
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
 * End of term workflow and automatic promotion (user request 2026-07-11):
 * ending a term advances to the next term in the session, or on the last
 * term, rolls over to the next session and promotes qualifying students.
 */
class EndOfTermPromotionTest extends TestCase
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

    public function test_ending_a_non_final_term_just_moves_to_the_next_term(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'eotone');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        $first = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term 2025/2026', 'sequence' => 1]);
        $second = Term::create(['academic_session_id' => $session->id, 'name' => 'Second Term 2025/2026', 'sequence' => 2]);
        $first->makeCurrent(); // also makes $session current, see the Term model regression test below
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/end-term")->assertRedirect();

        app(TenantManager::class)->use($school);
        $secondAfter = Term::find($second->id);
        $sessionAfter = AcademicSession::find($session->id);
        app(TenantManager::class)->forget();

        $this->assertTrue($secondAfter->is_current);
        $this->assertTrue($sessionAfter->is_current, 'the session itself should not change on a non-final term');
    }

    public function test_ending_the_final_term_rolls_over_to_a_new_session_and_promotes(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'eottwo');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class1 = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $class2 = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 2', 'sequence' => 2]);
        $arm = Arm::create(['name' => 'A']);
        $classArm1 = ClassArm::create(['school_class_id' => $class1->id, 'arm_id' => $arm->id]);
        ClassArm::create(['school_class_id' => $class2->id, 'arm_id' => $arm->id]);

        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        $third = Term::create(['academic_session_id' => $session->id, 'name' => 'Third Term 2025/2026', 'sequence' => 3]);
        $third->makeCurrent();

        $subject = Subject::create(['name' => 'Mathematics']);
        $component = AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 100, 'sequence' => 1]);

        $passer = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm1->id]);
        $failer = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade', 'current_class_arm_id' => $classArm1->id]);
        Score::create(['student_id' => $passer->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm1->id, 'term_id' => $third->id, 'assessment_component_id' => $component->id, 'score' => 80]);
        Score::create(['student_id' => $failer->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm1->id, 'term_id' => $third->id, 'assessment_component_id' => $component->id, 'score' => 20]);

        Setting::put('promotion_threshold', 40);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/end-term")->assertRedirect();

        app(TenantManager::class)->use($school);
        $newSession = AcademicSession::where('name', '2026/2027 Session')->first();
        $passerAfter = Student::find($passer->id);
        $failerAfter = Student::find($failer->id);
        app(TenantManager::class)->forget();

        $this->assertNotNull($newSession, 'the next session should be created automatically');
        $this->assertTrue($newSession->is_current);
        $this->assertSame('First Term 2026/2027', $newSession->terms()->current()->first()?->name);
        $this->assertNotEquals($classArm1->id, $passerAfter->current_class_arm_id, 'a passing student should move up a class');
        $this->assertEquals($classArm1->id, $failerAfter->current_class_arm_id, 'a failing student should stay in the same class');
    }

    public function test_a_student_in_the_last_class_overall_graduates_instead_of_being_promoted(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'eotthree');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'SS 3', 'sequence' => 1]);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);

        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        $third = Term::create(['academic_session_id' => $session->id, 'name' => 'Third Term 2025/2026', 'sequence' => 3]);
        $third->makeCurrent();

        $subject = Subject::create(['name' => 'Mathematics']);
        $component = AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 100, 'sequence' => 1]);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm->id]);
        Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id, 'term_id' => $third->id, 'assessment_component_id' => $component->id, 'score' => 90]);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/end-term")->assertRedirect();

        app(TenantManager::class)->use($school);
        $studentAfter = Student::find($student->id);
        app(TenantManager::class)->forget();

        $this->assertSame(Student::STATUS_GRADUATED, $studentAfter->status);
        $this->assertSame($classArm->id, $studentAfter->current_class_arm_id, 'a graduated student is not moved to a nonexistent class');
    }

    public function test_admin_can_view_and_update_the_promotion_threshold(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'eotfour');

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/academics/promotion-settings")
            ->assertOk()->assertSee('40'); // default

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/promotion-settings", [
            'promotion_threshold' => 50,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $stored = Setting::grab('promotion_threshold');
        app(TenantManager::class)->forget();
        $this->assertEquals(50, $stored);
    }

    public function test_ending_a_term_with_no_current_term_is_rejected(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'eotfive');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/end-term")
            ->assertNotFound();
    }

    public function test_a_teacher_cannot_end_a_term(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'eotsix');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term 2025/2026', 'sequence' => 1]);
        $term->makeCurrent();
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/academics/end-term")
            ->assertForbidden();
    }
}
