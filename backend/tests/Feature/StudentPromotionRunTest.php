<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\PromotionRun;
use App\Models\Tenant\PromotionRunItem;
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
 * Admin-triggered student promotion (feedback list 8/7/2026, item 9), a
 * second, separate path from the automatic promotion EndOfTermPromotionTest
 * already covers — run on demand against a chosen source term, with a
 * persisted, revisitable per-student report.
 */
class StudentPromotionRunTest extends TestCase
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

    /** Two classes (Primary 1 -> Primary 2), one arm each, a subject/component, and a real term. */
    protected function twoClassSetup(): array
    {
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class1 = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $class2 = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 2', 'sequence' => 2]);
        $arm = Arm::create(['name' => 'A']);
        $classArm1 = ClassArm::create(['school_class_id' => $class1->id, 'arm_id' => $arm->id]);
        $classArm2 = ClassArm::create(['school_class_id' => $class2->id, 'arm_id' => $arm->id]);

        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'Third Term 2025/2026', 'sequence' => 3]);
        $term->makeCurrent();

        $subject = Subject::create(['name' => 'Mathematics']);
        $component = AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 100, 'sequence' => 1]);

        return [$classArm1, $classArm2, $term, $subject, $component];
    }

    public function test_a_manual_run_promotes_at_threshold_and_repeats_below_it_with_a_reason(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'sprone');

        app(TenantManager::class)->use($school);
        [$classArm1, , $term, $subject, $component] = $this->twoClassSetup();

        $passer = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm1->id]);
        $repeater = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade', 'current_class_arm_id' => $classArm1->id]);
        Score::create(['student_id' => $passer->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm1->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 85]);
        Score::create(['student_id' => $repeater->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm1->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 50]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($admin)->post("http://{$school->slug}.localhost/examinations/promote-students", [
            'term_id' => $term->id,
            'threshold' => 70,
        ]);
        $response->assertRedirect();

        app(TenantManager::class)->use($school);
        $run = PromotionRun::first();
        $passerAfter = Student::find($passer->id);
        $repeaterAfter = Student::find($repeater->id);
        $repeaterItem = PromotionRunItem::where('student_id', $repeater->id)->first();
        app(TenantManager::class)->forget();

        $this->assertSame(1, $run->promoted_count);
        $this->assertSame(1, $run->repeated_count);
        $this->assertNotEquals($classArm1->id, $passerAfter->current_class_arm_id, 'the passing student should move up a class');
        $this->assertEquals($classArm1->id, $repeaterAfter->current_class_arm_id, 'the repeating student should stay put');
        $this->assertSame(PromotionRunItem::OUTCOME_REPEATED, $repeaterItem->outcome);
        $this->assertStringContainsString('50', $repeaterItem->reason);
        $this->assertStringContainsString('70', $repeaterItem->reason);
    }

    public function test_a_student_in_the_last_class_graduates_and_a_scoreless_student_is_recorded_as_no_results(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'sprtwo');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'SS 3', 'sequence' => 1]);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'Third Term 2025/2026', 'sequence' => 3]);
        $term->makeCurrent();
        $subject = Subject::create(['name' => 'Mathematics']);
        $component = AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 100, 'sequence' => 1]);

        $graduate = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm->id]);
        $scoreless = Student::create(['admission_number' => 'A2', 'first_name' => 'Chidi', 'last_name' => 'Eze', 'current_class_arm_id' => $classArm->id]);
        Score::create(['student_id' => $graduate->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 90]);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/examinations/promote-students", [
            'term_id' => $term->id,
            'threshold' => 70,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $graduateAfter = Student::find($graduate->id);
        $scorelessItem = PromotionRunItem::where('student_id', $scoreless->id)->first();
        app(TenantManager::class)->forget();

        $this->assertSame(Student::STATUS_GRADUATED, $graduateAfter->status);
        $this->assertSame(PromotionRunItem::OUTCOME_SKIPPED_NO_RESULTS, $scorelessItem->outcome);
    }

    public function test_running_twice_against_the_same_term_does_not_double_promote(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'sprthree');

        app(TenantManager::class)->use($school);
        [$classArm1, $classArm2, $term, $subject, $component] = $this->twoClassSetup();
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm1->id]);
        Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm1->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 85]);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/examinations/promote-students", ['term_id' => $term->id, 'threshold' => 70])->assertRedirect();
        $this->actingAs($admin)->post("http://{$school->slug}.localhost/examinations/promote-students", ['term_id' => $term->id, 'threshold' => 70])->assertRedirect();

        app(TenantManager::class)->use($school);
        $studentAfter = Student::find($student->id);
        // Ordered by id, not created_at: two runs created within the
        // same test-speed second would otherwise tie on timestamp and
        // make ->latest()/->oldest() return an arbitrary one of the two.
        $firstRun = PromotionRun::with('items')->orderBy('id')->first();
        $secondRun = PromotionRun::with('items')->orderByDesc('id')->first();
        app(TenantManager::class)->forget();

        $this->assertEquals($classArm2->id, $studentAfter->current_class_arm_id, 'promoted once, not moved further by the second run');
        $this->assertSame(1, $firstRun->promoted_count);
        $this->assertSame(0, $firstRun->skipped_count, 'the promoted student should not also show up as a spurious duplicate entry under their own new class in the same run');
        $this->assertSame(0, $secondRun->promoted_count, 'the second run should not re-promote an already-moved student');
        $this->assertSame(1, $secondRun->skipped_count, 'the student now shows under their new class with no results recorded there yet');
    }

    public function test_the_report_screen_shows_the_persisted_run(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'sprfour');

        app(TenantManager::class)->use($school);
        [$classArm1, , $term, $subject, $component] = $this->twoClassSetup();
        $repeater = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm1->id]);
        Score::create(['student_id' => $repeater->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm1->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 40]);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/examinations/promote-students", ['term_id' => $term->id, 'threshold' => 70])->assertRedirect();

        app(TenantManager::class)->use($school);
        $run = PromotionRun::first();
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/examinations/promote-students/runs/{$run->id}")
            ->assertOk()
            ->assertSee('Ada Obi')
            ->assertSee('Repeated');
    }

    public function test_a_non_admin_tier_staff_member_cannot_run_or_view_promotions(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'sprfive');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/examinations/promote-students")
            ->assertForbidden();
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/promote-students", ['term_id' => 1, 'threshold' => 70])
            ->assertForbidden();
    }
}
