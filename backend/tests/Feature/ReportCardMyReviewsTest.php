<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\ReportCardReview;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * "My Report Reviews" (2026-09-12, user directive): "there's no submenu
 * for staff assign class to review report to... class teacher should
 * see list of report cards sent for review, the status, any one
 * approved, rejected with the reason etc... subject teacher wont see
 * [the class-wide section] as its only class teachers that sees it but
 * if the user is both class and subject, he/she will see it."
 *
 * Two independently-gated sections on one screen: every report card in
 * a class this user actually leads (class-teacher only), and every
 * review explicitly assigned to them regardless of role (actionable).
 */
class ReportCardMyReviewsTest extends TestCase
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

    protected function schoolWithRole(string $roleSlug, string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    /** Term + a class arm with one student + a Staff row for $user, optionally set as the class's own class teacher. */
    protected function setUpClass(School $school, User $user, bool $asClassTeacher): array
    {
        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true]);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true]);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 3', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $student = Student::create([
            'admission_number' => 'P3/1', 'first_name' => 'Ada', 'last_name' => 'Obi',
            'current_class_arm_id' => $classArm->id,
        ]);
        $staff = Staff::create(['staff_id' => 'STF/'.$user->id, 'first_name' => 'T', 'last_name' => 'Eacher', 'user_id' => $user->id]);
        if ($asClassTeacher) {
            $classArm->update(['class_teacher_staff_id' => $staff->id]);
        }
        app(TenantManager::class)->forget();

        return compact('term', 'classArm', 'student', 'staff');
    }

    protected function reviewFor(School $school, Student $student, Term $term, ?Staff $assignedTo = null): ReportCardReview
    {
        app(TenantManager::class)->use($school);
        $review = ReportCardReview::create([
            'student_id' => $student->id, 'term_id' => $term->id, 'report_type' => ReportCardReview::REPORT_TYPE_FULL_TERM,
            'status' => ReportCardReview::STATUS_PENDING_REVIEW, 'review_stage' => 0,
            'assigned_to_staff_id' => $assignedTo?->id,
        ]);
        app(TenantManager::class)->forget();

        return $review;
    }

    public function test_a_class_teacher_sees_their_own_class_report_cards_in_the_my_class_section(): void
    {
        [$school, $teacher] = $this->schoolWithRole('class-teacher', 'myrev-one');
        ['term' => $term, 'student' => $student] = $this->setUpClass($school, $teacher, asClassTeacher: true);
        $this->reviewFor($school, $student, $term);

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/report-cards/my-reviews")
            ->assertOk()
            ->assertSee("My class's report cards", false)
            ->assertSee('Ada Obi');
    }

    public function test_a_subject_teacher_does_not_see_the_my_class_section(): void
    {
        [$school, $teacher] = $this->schoolWithRole('subject-teacher', 'myrev-two');
        ['term' => $term, 'student' => $student] = $this->setUpClass($school, $teacher, asClassTeacher: false);
        $this->reviewFor($school, $student, $term);

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/report-cards/my-reviews")
            ->assertOk()
            ->assertDontSee("My class's report cards", false);
    }

    public function test_a_subject_teacher_sees_and_can_approve_a_review_assigned_to_them(): void
    {
        [$school, $teacher] = $this->schoolWithRole('subject-teacher', 'myrev-three');
        ['term' => $term, 'student' => $student, 'staff' => $staff] = $this->setUpClass($school, $teacher, asClassTeacher: false);
        $this->reviewFor($school, $student, $term, $staff);

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/report-cards/my-reviews")
            ->assertOk()->assertSee('Assigned to me')->assertSee('Ada Obi');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/report-cards/{$student->id}/approve", [
            'report_type' => ReportCardReview::REPORT_TYPE_FULL_TERM,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $review = ReportCardReview::where('student_id', $student->id)->first();
        app(TenantManager::class)->forget();
        $this->assertSame(ReportCardReview::STATUS_APPROVED, $review->status, 'a single-stage default chain should approve outright');
    }

    public function test_a_teacher_cannot_approve_a_review_not_assigned_to_them(): void
    {
        [$school, $teacher] = $this->schoolWithRole('subject-teacher', 'myrev-four');
        ['term' => $term, 'student' => $student] = $this->setUpClass($school, $teacher, asClassTeacher: false);
        // Assigned to nobody (or somebody else) -- either way, not this teacher.
        $this->reviewFor($school, $student, $term, assignedTo: null);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/report-cards/{$student->id}/approve", [
            'report_type' => ReportCardReview::REPORT_TYPE_FULL_TERM,
        ])->assertForbidden();
    }

    public function test_a_subject_teacher_can_send_back_an_assigned_review_with_a_reason(): void
    {
        [$school, $teacher] = $this->schoolWithRole('subject-teacher', 'myrev-five');
        ['term' => $term, 'student' => $student, 'staff' => $staff] = $this->setUpClass($school, $teacher, asClassTeacher: false);
        $this->reviewFor($school, $student, $term, $staff);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/report-cards/{$student->id}/send-back", [
            'report_type' => ReportCardReview::REPORT_TYPE_FULL_TERM,
            'assigned_to_staff_id' => $staff->id,
            'notes' => 'Please fix the physical stats section.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $review = ReportCardReview::where('student_id', $student->id)->first();
        app(TenantManager::class)->forget();
        $this->assertSame(ReportCardReview::STATUS_NEEDS_CORRECTION, $review->status);
        $this->assertSame('Please fix the physical stats section.', $review->notes);
    }

    public function test_a_class_teacher_who_also_has_an_assigned_review_sees_both_sections(): void
    {
        [$school, $teacher] = $this->schoolWithRole('class-teacher', 'myrev-six');
        ['term' => $term, 'student' => $student, 'staff' => $staff] = $this->setUpClass($school, $teacher, asClassTeacher: true);
        $this->reviewFor($school, $student, $term, $staff);

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/report-cards/my-reviews")
            ->assertOk()
            ->assertSee("My class's report cards", false)
            ->assertSee('Assigned to me');
    }
}
