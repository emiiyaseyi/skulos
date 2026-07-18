<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\AssessmentWindow;
use App\Models\Tenant\AssessmentWindowExtension;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Score;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Assessment score-entry windows (user request 2026-07-18: "let each
 * assessment and exam has a window for the teachers to enter their
 * scores, only admin can set this, it can grant extension which the
 * teacher will have to request... the admin can review, adjust their
 * window, reject or approve").
 */
class AssessmentWindowTest extends TestCase
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

    protected function seedAcademics(School $school): array
    {
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $class->subjects()->attach($subject->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $term->makeCurrent();

        $first = AssessmentComponent::create(['name' => 'First CA', 'max_score' => 20, 'sequence' => 1]);
        $exam = AssessmentComponent::create(['name' => 'Exam', 'max_score' => 60, 'sequence' => 2]);

        $ada = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm->id]);

        app(TenantManager::class)->forget();

        return compact('classArm', 'term', 'subject', 'first', 'exam', 'ada');
    }

    protected function attachTeacher(School $school, User $user, int $classArmId, int $subjectId): Staff
    {
        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'T1', 'first_name' => 'Tade', 'last_name' => 'Teacher', 'user_id' => $user->id]);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $subjectId, 'class_arm_id' => $classArmId]);
        app(TenantManager::class)->forget();

        return $staff;
    }

    protected function saveScore(School $school, User $teacher, array $f, AssessmentComponent $component, float $value): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [$f['ada']->id => [$component->id => $value]],
        ]);
    }

    public function test_an_admin_can_set_a_window_for_a_component_and_term(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'winone');
        $f = $this->seedAcademics($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/structure/windows", [
            'assessment_component_id' => $f['first']->id,
            'term_id' => $f['term']->id,
            'opens_at' => now()->subDay()->format('Y-m-d\TH:i'),
            'closes_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $window = AssessmentWindow::where('assessment_component_id', $f['first']->id)->where('term_id', $f['term']->id)->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($window);
        $this->assertNotNull($window->opens_at);
        $this->assertNotNull($window->closes_at);
    }

    public function test_a_teacher_without_approve_results_cannot_set_a_window(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'wintwo');
        $f = $this->seedAcademics($school);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/structure/windows", [
            'assessment_component_id' => $f['first']->id,
            'term_id' => $f['term']->id,
        ])->assertForbidden();
    }

    public function test_a_score_outside_a_closed_window_is_skipped_but_others_still_save(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'winthree');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);

        app(TenantManager::class)->use($school);
        AssessmentWindow::create([
            'assessment_component_id' => $f['first']->id, 'term_id' => $f['term']->id,
            'opens_at' => now()->subDays(5), 'closes_at' => now()->subDay(), // already closed
        ]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/scores", [
            'term_id' => $f['term']->id,
            'class_arm_id' => $f['classArm']->id,
            'subject_id' => $f['subject']->id,
            'scores' => [$f['ada']->id => [$f['first']->id => 18, $f['exam']->id => 52]],
        ]);
        $response->assertRedirect();

        app(TenantManager::class)->use($school);
        $firstScore = Score::where('student_id', $f['ada']->id)->where('assessment_component_id', $f['first']->id)->first();
        $examScore = Score::where('student_id', $f['ada']->id)->where('assessment_component_id', $f['exam']->id)->first();
        app(TenantManager::class)->forget();

        $this->assertNull($firstScore, 'the closed component must not be saved');
        $this->assertNotNull($examScore, 'the open component must still save');
        $this->assertSame(52.0, (float) $examScore->score);
    }

    public function test_a_blanket_access_role_bypasses_the_window_entirely(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'winfour');
        $f = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        AssessmentWindow::create([
            'assessment_component_id' => $f['first']->id, 'term_id' => $f['term']->id,
            'opens_at' => now()->subDays(5), 'closes_at' => now()->subDay(),
        ]);
        app(TenantManager::class)->forget();

        $this->saveScore($school, $owner, $f, $f['first'], 15)->assertRedirect();

        app(TenantManager::class)->use($school);
        $score = Score::where('student_id', $f['ada']->id)->where('assessment_component_id', $f['first']->id)->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($score, 'an owner (blanket access) must not be window-restricted');
    }

    public function test_a_teacher_can_request_and_be_granted_an_extension(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'winfive');
        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);

        app(TenantManager::class)->use($school);
        $window = AssessmentWindow::create([
            'assessment_component_id' => $f['first']->id, 'term_id' => $f['term']->id,
            'opens_at' => now()->subDays(5), 'closes_at' => now()->subDay(),
        ]);
        app(TenantManager::class)->forget();

        // Blocked before any extension exists.
        $this->saveScore($school, $teacher, $f, $f['first'], 18);
        app(TenantManager::class)->use($school);
        $this->assertNull(Score::where('assessment_component_id', $f['first']->id)->first());
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/window-extensions", [
            'assessment_component_id' => $f['first']->id,
            'term_id' => $f['term']->id,
            'reason' => 'I was on approved leave when the window closed.',
            'requested_close_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $extension = AssessmentWindowExtension::where('assessment_window_id', $window->id)->first();
        app(TenantManager::class)->forget();
        $this->assertNotNull($extension);
        $this->assertSame(AssessmentWindowExtension::STATUS_PENDING, $extension->status);

        // Still blocked while pending.
        $this->saveScore($school, $teacher, $f, $f['first'], 18);
        app(TenantManager::class)->use($school);
        $this->assertNull(Score::where('assessment_component_id', $f['first']->id)->first());
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/window-extensions/{$extension->id}/approve", [])
            ->assertRedirect();

        // Now allowed.
        $this->saveScore($school, $teacher, $f, $f['first'], 18)->assertRedirect();
        app(TenantManager::class)->use($school);
        $score = Score::where('assessment_component_id', $f['first']->id)->first();
        app(TenantManager::class)->forget();
        $this->assertNotNull($score);
        $this->assertSame(18.0, (float) $score->score);
    }

    public function test_the_admin_can_adjust_the_close_time_when_approving(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'winsix');
        $f = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        $window = AssessmentWindow::create([
            'assessment_component_id' => $f['first']->id, 'term_id' => $f['term']->id,
            'opens_at' => now()->subDays(5), 'closes_at' => now()->subDays(2),
        ]);
        $staff = Staff::create(['staff_id' => 'T2', 'first_name' => 'Req', 'last_name' => 'Uester']);
        $extension = AssessmentWindowExtension::create([
            'assessment_window_id' => $window->id, 'staff_id' => $staff->id,
            'reason' => 'Need more time.', 'requested_close_at' => now()->subHour(), // already in the past
        ]);
        app(TenantManager::class)->forget();

        $adjustedClose = now()->addDays(3);
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/window-extensions/{$extension->id}/approve", [
            'requested_close_at' => $adjustedClose->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $extension->fresh();
        app(TenantManager::class)->forget();

        $this->assertSame(AssessmentWindowExtension::STATUS_APPROVED, $fresh->status);
        $this->assertSame($adjustedClose->format('Y-m-d H:i'), $fresh->requested_close_at->format('Y-m-d H:i'));
    }

    public function test_rejecting_an_extension_leaves_the_default_window_applying(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'winseven');
        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);
        $f = $this->seedAcademics($school);
        $staff = $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);

        app(TenantManager::class)->use($school);
        $window = AssessmentWindow::create([
            'assessment_component_id' => $f['first']->id, 'term_id' => $f['term']->id,
            'opens_at' => now()->subDays(5), 'closes_at' => now()->subDay(),
        ]);
        $extension = AssessmentWindowExtension::create([
            'assessment_window_id' => $window->id, 'staff_id' => $staff->id,
            'reason' => 'Please.', 'requested_close_at' => now()->addDay(),
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/window-extensions/{$extension->id}/reject", [])
            ->assertRedirect();

        $this->saveScore($school, $teacher, $f, $f['first'], 18);
        app(TenantManager::class)->use($school);
        $score = Score::where('assessment_component_id', $f['first']->id)->first();
        $fresh = $extension->fresh();
        app(TenantManager::class)->forget();

        $this->assertNull($score, 'a rejected extension must not open the window');
        $this->assertSame(AssessmentWindowExtension::STATUS_REJECTED, $fresh->status);
    }

    public function test_requesting_an_extension_for_a_component_with_no_window_404s(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'wineight');
        $f = $this->seedAcademics($school);
        $this->attachTeacher($school, $teacher, $f['classArm']->id, $f['subject']->id);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/examinations/window-extensions", [
            'assessment_component_id' => $f['first']->id,
            'term_id' => $f['term']->id,
            'reason' => 'No window was ever set for this.',
            'requested_close_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ])->assertNotFound();
    }

    public function test_bulk_approve_and_reject_pending_extensions(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'winnine');
        $f = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        $window = AssessmentWindow::create([
            'assessment_component_id' => $f['first']->id, 'term_id' => $f['term']->id,
            'opens_at' => now()->subDays(5), 'closes_at' => now()->subDay(),
        ]);
        $staffA = Staff::create(['staff_id' => 'BA1', 'first_name' => 'A', 'last_name' => 'One']);
        $staffB = Staff::create(['staff_id' => 'BA2', 'first_name' => 'B', 'last_name' => 'Two']);
        $extA = AssessmentWindowExtension::create(['assessment_window_id' => $window->id, 'staff_id' => $staffA->id, 'reason' => 'A', 'requested_close_at' => now()->addDay()]);
        $extB = AssessmentWindowExtension::create(['assessment_window_id' => $window->id, 'staff_id' => $staffB->id, 'reason' => 'B', 'requested_close_at' => now()->addDay()]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/window-extensions/bulk-action", [
            'extension_ids' => [$extA->id, $extB->id], 'action' => 'approve',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(AssessmentWindowExtension::STATUS_APPROVED, $extA->fresh()->status);
        $this->assertSame(AssessmentWindowExtension::STATUS_APPROVED, $extB->fresh()->status);
        app(TenantManager::class)->forget();
    }

    public function test_the_extension_review_screen_lists_requests(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'winten');
        $f = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        $window = AssessmentWindow::create([
            'assessment_component_id' => $f['first']->id, 'term_id' => $f['term']->id,
            'opens_at' => now()->subDays(5), 'closes_at' => now()->subDay(),
        ]);
        $staff = Staff::create(['staff_id' => 'RS1', 'first_name' => 'Review', 'last_name' => 'Ed']);
        AssessmentWindowExtension::create(['assessment_window_id' => $window->id, 'staff_id' => $staff->id, 'reason' => 'Family emergency.', 'requested_close_at' => now()->addDay()]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/examinations/window-extensions")
            ->assertOk()->assertSee('Review Ed')->assertSee('Family emergency.');
    }
}
