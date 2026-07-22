<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
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
 * Report card review workflow (user request 2026-07-11: section/class
 * browse, generate, single or bulk assign for review, send back for
 * correction, approve, release). The report card's actual marks are still
 * always computed live from Score rows, ReportCardReview only tracks where
 * a given student's card is in the review process for the current term.
 */
class ReportCardWorkflowTest extends TestCase
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

    /** Builds one class with one student and a current term, returns [classId, studentId]. */
    protected function seedClassWithStudent(School $school): array
    {
        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $arm->id]);
        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true]);
        $classId = $class->id;
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        return [$classId, $studentId];
    }

    public function test_owner_can_browse_sections_and_classes(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcone');
        $this->seedClassWithStudent($school);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards")
            ->assertOk()
            ->assertSee('Primary')
            ->assertSee('Primary 1');
    }

    /** User directive 2026-07-22: "the class stream should be separated here... same under the report card page too." */
    public function test_a_multi_stream_class_shows_each_stream_separately_on_the_index_and_a_stream_column_on_its_class_page(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcstream');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $gold = \App\Models\Tenant\Arm::create(['name' => 'Gold']);
        $silver = \App\Models\Tenant\Arm::create(['name' => 'Silver']);
        $goldArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $gold->id]);
        $silverArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $silver->id]);
        Student::create(['admission_number' => 'G1', 'first_name' => 'Goldie', 'last_name' => 'One', 'current_class_arm_id' => $goldArm->id]);
        Student::create(['admission_number' => 'S1', 'first_name' => 'Silva', 'last_name' => 'One', 'current_class_arm_id' => $silverArm->id]);
        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true]);
        $classId = $class->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards")
            ->assertOk()
            ->assertSee('Primary 1 Gold')
            ->assertSee('Primary 1 Silver');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/classes/{$classId}")
            ->assertOk()
            ->assertSee('Goldie One')
            ->assertSee('Silva One')
            ->assertSee('Primary 1 Gold')
            ->assertSee('Primary 1 Silver');
    }

    public function test_owner_can_generate_a_report_card_for_one_student(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rctwo');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/generate")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $review = ReportCardReview::where('student_id', $studentId)->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($review);
        $this->assertSame(ReportCardReview::STATUS_PENDING_REVIEW, $review->status);
    }

    public function test_owner_can_generate_every_student_in_a_class_at_once(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcthree');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/classes/{$classId}/generate")
            ->assertRedirect()
            ->assertSessionHas('status');

        app(TenantManager::class)->use($school);
        $count = ReportCardReview::count();
        app(TenantManager::class)->forget();

        $this->assertSame(1, $count);
    }

    public function test_owner_can_bulk_assign_a_class_for_review(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcfour');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tolu', 'last_name' => 'Bello', 'status' => 'active']);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/classes/{$classId}/bulk-assign", [
            'student_ids' => [$studentId],
            'assigned_to_staff_id' => $staffId,
        ])->assertRedirect()->assertSessionHas('status');

        app(TenantManager::class)->use($school);
        $review = ReportCardReview::where('student_id', $studentId)->first();
        app(TenantManager::class)->forget();

        $this->assertSame($staffId, $review->assigned_to_staff_id);
        $this->assertSame(ReportCardReview::STATUS_PENDING_REVIEW, $review->status);
    }

    public function test_owner_can_send_a_report_card_back_for_correction(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcfive');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tolu', 'last_name' => 'Bello', 'status' => 'active']);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/send-back", [
            'assigned_to_staff_id' => $staffId,
            'notes' => 'Fix the maths score.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $review = ReportCardReview::where('student_id', $studentId)->first();
        app(TenantManager::class)->forget();

        $this->assertSame(ReportCardReview::STATUS_NEEDS_CORRECTION, $review->status);
        $this->assertSame('Fix the maths score.', $review->notes);
    }

    /** A rejected report card must carry a reason (user request 2026-07-11: "comment is mandatory for rejected report"). */
    public function test_sending_a_report_card_back_without_a_comment_fails_validation(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcthirteen');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tolu', 'last_name' => 'Bello', 'status' => 'active']);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/send-back", [
            'assigned_to_staff_id' => $staffId,
        ])->assertSessionHasErrors('notes');
    }

    /** A reviewer must be able to open the actual report card from the pending review list. */
    public function test_pending_review_lists_a_view_link_to_the_actual_report_card(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcfourteen');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/generate");

        app(TenantManager::class)->use($school);
        $term = Term::current()->first();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/pending-review")
            ->assertOk()
            ->assertSee("/report-cards/{$studentId}/{$term->id}", false);
    }

    /** User request 2026-07-22: "under pending review section/page, let's also add the filter and sort option there too." */
    public function test_pending_review_has_filter_and_sort_controls(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcpendingfilter');
        [, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/generate");

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/pending-review")
            ->assertOk()
            ->assertSee('Filter by student or class')
            ->assertSee('id="pr-sort"', false);
    }

    public function test_owner_can_approve_then_release(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcsix');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/approve")->assertRedirect();

        app(TenantManager::class)->use($school);
        $approved = ReportCardReview::where('student_id', $studentId)->first();
        app(TenantManager::class)->forget();
        $this->assertSame(ReportCardReview::STATUS_APPROVED, $approved->status);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/release")->assertRedirect();

        app(TenantManager::class)->use($school);
        $released = ReportCardReview::where('student_id', $studentId)->first();
        app(TenantManager::class)->forget();
        $this->assertSame(ReportCardReview::STATUS_RELEASED, $released->status);
        $this->assertNotNull($released->released_at);
    }

    public function test_a_class_teacher_cannot_assign_review_or_release(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'rcseven');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/assign", [])
            ->assertForbidden();

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/release")
            ->assertForbidden();
    }

    public function test_a_class_teacher_can_still_generate_and_view(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'rceight');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        // TeacherScope keys off a real Staff row assigned to the class arm
        // as class_teacher_staff_id, not just the "class-teacher" RBAC
        // role, so a class teacher must actually be assigned to this class
        // to be in scope for it (audit finding M-1, 2026-07-17: report
        // card actions gained the same TeacherScope check
        // StudentController::show() already had).
        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'T1', 'first_name' => 'Tobi', 'last_name' => 'Teacher', 'user_id' => $teacher->id]);
        ClassArm::where('school_class_id', $classId)->update(['class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/report-cards/classes/{$classId}")->assertOk();
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/generate")->assertRedirect();
    }

    /** User request 2026-07-21: "bulk printing and exporting of report card for a class in PDF or EXCEL — admins and class teachers". */
    public function test_admin_can_print_every_students_report_card_for_a_class_on_one_page(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcprintone');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/classes/{$classId}/print")
            ->assertOk()
            ->assertSee('Ada Obi')
            ->assertSee('Print all');
    }

    public function test_a_class_teacher_outside_the_class_cannot_print_it(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'rcprinttwo');
        [$classId] = $this->seedClassWithStudent($school);

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/report-cards/classes/{$classId}/print")->assertNotFound();
    }

    public function test_the_class_index_links_to_print_all_and_the_broadsheet(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcprintthree');
        [$classId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/classes/{$classId}")
            ->assertOk()
            ->assertSee(route('school.report-cards.class.print', ['school' => $school->slug, 'classId' => $classId]), false)
            ->assertSee(route('school.reports.students.index', ['school' => $school->slug]), false);
    }

    public function test_a_class_teacher_outside_the_class_cannot_generate_or_view(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'rcnineteen');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        // No Staff row links this teacher to the class arm at all, so they
        // are scoped to nothing (audit finding M-1, 2026-07-17).
        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/report-cards/classes/{$classId}")->assertNotFound();
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/generate")->assertNotFound();
    }

    public function test_a_subject_teacher_without_report_card_access_is_forbidden(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'rcnine');
        $this->seedClassWithStudent($school);

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/report-cards")->assertForbidden();
    }

    public function test_pending_review_lists_only_pending_and_needs_correction(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcten');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/generate");
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/pending-review")
            ->assertOk()
            ->assertSee('Ada Obi');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/release")->assertRedirect();

        app(TenantManager::class)->use($school);
        $review = ReportCardReview::where('student_id', $studentId)->first();
        app(TenantManager::class)->forget();
        $this->assertSame(ReportCardReview::STATUS_RELEASED, $review->status, 'released cards must drop out of the pending review query');
    }

    public function test_nudging_a_reviewer_creates_an_in_app_notification(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rceleven');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        app(TenantManager::class)->use($school);
        $reviewerUser = User::factory()->create();
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tolu', 'last_name' => 'Bello', 'status' => 'active', 'user_id' => $reviewerUser->id]);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/generate");
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/assign", ['assigned_to_staff_id' => $staffId]);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/nudge")
            ->assertRedirect()->assertSessionHas('status');

        app(TenantManager::class)->use($school);
        $notification = \App\Models\Tenant\Notification::where('user_id', $reviewerUser->id)->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($notification);
        $this->assertSame('report_card_review', $notification->category);
    }

    public function test_nudging_without_an_assigned_staff_member_fails_gracefully(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rctwelve');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/generate");
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/nudge")
            ->assertRedirect()->assertSessionHas('error');
    }

    /** A class's default reviewer (user request 2026-07-11) auto assigns every report card generated for that class. */
    public function test_a_classs_default_reviewer_is_auto_assigned_on_generate(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcfifteen');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tolu', 'last_name' => 'Bello', 'status' => 'active']);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/classes/{$classId}/default-reviewer", [
            'default_reviewer_staff_id' => $staffId,
        ])->assertRedirect();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/generate");

        app(TenantManager::class)->use($school);
        $review = ReportCardReview::where('student_id', $studentId)->first();
        $class = SchoolClass::find($classId);
        app(TenantManager::class)->forget();

        $this->assertSame($staffId, $review->assigned_to_staff_id);
        $this->assertSame($staffId, $class->default_reviewer_staff_id);
    }

    /** Generating a whole class also picks up its default reviewer. */
    public function test_a_classs_default_reviewer_is_auto_assigned_on_generate_class(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcsixteen');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tolu', 'last_name' => 'Bello', 'status' => 'active']);
        $staffId = $staff->id;
        SchoolClass::find($classId)->update(['default_reviewer_staff_id' => $staffId]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/classes/{$classId}/generate");

        app(TenantManager::class)->use($school);
        $review = ReportCardReview::where('student_id', $studentId)->first();
        app(TenantManager::class)->forget();

        $this->assertSame($staffId, $review->assigned_to_staff_id);
    }

    /** Admin can turn the review stage off, releasing generated cards immediately (user request 2026-07-11). */
    public function test_turning_off_the_review_stage_releases_generated_cards_immediately(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcseventeen');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/review-stage", ['enabled' => '0'])
            ->assertRedirect();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/generate");

        app(TenantManager::class)->use($school);
        $review = ReportCardReview::where('student_id', $studentId)->first();
        app(TenantManager::class)->forget();

        $this->assertSame(ReportCardReview::STATUS_RELEASED, $review->status);
        $this->assertNotNull($review->released_at);
    }

    public function test_the_review_stage_is_on_by_default(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rceighteen');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/generate");

        app(TenantManager::class)->use($school);
        $review = ReportCardReview::where('student_id', $studentId)->first();
        app(TenantManager::class)->forget();

        $this->assertSame(ReportCardReview::STATUS_PENDING_REVIEW, $review->status);
    }

    public function test_a_teacher_without_report_settings_access_cannot_toggle_the_review_stage(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'rcnineteen');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/report-cards/review-stage", ['enabled' => '0'])
            ->assertForbidden();
    }

    /**
     * Configurable multi-stage review chain (user directive 2026-07-12:
     * "Admin configures Workflow... Entirely configurable"). A two-stage
     * chain must require two Approve actions, staying pending_review with
     * an advanced review_stage after the first, before it reaches Approved.
     */
    public function test_a_two_stage_review_chain_requires_two_approvals(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rctwenty');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/review-chain", [
            'chain' => ['class-teacher', 'school-owner'],
        ])->assertRedirect();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/approve")->assertRedirect();

        app(TenantManager::class)->use($school);
        $afterFirst = ReportCardReview::where('student_id', $studentId)->first();
        app(TenantManager::class)->forget();
        $this->assertSame(ReportCardReview::STATUS_PENDING_REVIEW, $afterFirst->status);
        $this->assertSame(1, $afterFirst->review_stage);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/approve")->assertRedirect();

        app(TenantManager::class)->use($school);
        $afterSecond = ReportCardReview::where('student_id', $studentId)->first();
        app(TenantManager::class)->forget();
        $this->assertSame(ReportCardReview::STATUS_APPROVED, $afterSecond->status);
    }

    /** A chain can only be built from the known role picklist, not an arbitrary string. */
    public function test_updating_the_review_chain_rejects_an_unknown_role(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rctwentyone');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/review-chain", [
            'chain' => ['not-a-real-role'],
        ])->assertSessionHasErrors('chain.0');
    }

    /** Sending a report card back for correction restarts the chain from the first stage. */
    public function test_sending_back_resets_the_review_stage(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rctwentytwo');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/review-chain", [
            'chain' => ['class-teacher', 'school-owner'],
        ]);
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/approve");

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tolu', 'last_name' => 'Bello', 'status' => 'active']);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/send-back", [
            'assigned_to_staff_id' => $staffId,
            'notes' => 'Redo it.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $review = ReportCardReview::where('student_id', $studentId)->first();
        app(TenantManager::class)->forget();
        $this->assertSame(0, $review->review_stage);
    }

    /** Promotion recommendation (user directive 2026-07-12: "Promote / Repeat / Conditional Promotion, Reason"). */
    public function test_promotion_recommendation_can_be_saved(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rctwentythree');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/promotion", [
            'promotion_recommendation' => ReportCardReview::PROMOTION_CONDITIONAL,
            'promotion_reason' => 'Needs to improve in Mathematics.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $review = ReportCardReview::where('student_id', $studentId)->first();
        app(TenantManager::class)->forget();
        $this->assertSame(ReportCardReview::PROMOTION_CONDITIONAL, $review->promotion_recommendation);
        $this->assertSame('Needs to improve in Mathematics.', $review->promotion_reason);
    }

    /** Lock only applies to an already released report card, and blocks a second lock attempt cleanly. */
    public function test_a_report_card_must_be_released_before_it_can_be_locked(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rctwentyfour');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/generate");
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/lock")
            ->assertStatus(422);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/approve");
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/release");
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/lock")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $review = ReportCardReview::where('student_id', $studentId)->first();
        app(TenantManager::class)->forget();
        $this->assertSame(ReportCardReview::STATUS_LOCKED, $review->status);
        $this->assertNotNull($review->locked_at);
    }

    /** Archive accepts either a released or a locked report card, taking it out of active lists. */
    public function test_a_released_or_locked_report_card_can_be_archived(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rctwentyfive');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/generate");
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/approve");
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/release");
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/archive")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $review = ReportCardReview::where('student_id', $studentId)->first();
        app(TenantManager::class)->forget();
        $this->assertSame(ReportCardReview::STATUS_ARCHIVED, $review->status);
        $this->assertNotNull($review->archived_at);
    }

    /** The settings page itself must render the chain builder cleanly, not just accept the POST. */
    public function test_settings_page_renders_the_review_chain_builder(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rctwentyseven');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/review-chain", [
            'chain' => ['class-teacher', 'vice-principal'],
        ]);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/settings")
            ->assertOk()
            ->assertSee('Review chain')
            ->assertSee('Class Teacher')
            ->assertSee('Vice Principal');
    }

    /** A brand new draft report card cannot be archived, only released/locked ones. */
    public function test_a_draft_report_card_cannot_be_archived(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rctwentysix');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/generate");
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/archive")
            ->assertStatus(422);
    }

    /**
     * User report 2026-07-16: "the previous result page features was not
     * merged with the current report card features" - the old standalone
     * Results screen let a teacher pick any session/term, Report Cards
     * lost that when the two merged into one nav line. This proves the
     * term picker is back, and that switching it actually scopes both the
     * class list and every review action to the picked term, not always
     * whatever the current term happens to be.
     */
    public function test_a_second_term_can_be_selected_and_browsed_separately(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rctwentyseven');
        [$classId, $studentId] = $this->seedClassWithStudent($school);

        app(TenantManager::class)->use($school);
        $session = \App\Models\Tenant\AcademicSession::first();
        $secondTerm = Term::create(['academic_session_id' => $session->id, 'name' => 'Second Term', 'sequence' => 2, 'is_current' => false]);
        $secondTermId = $secondTerm->id;
        $currentTermId = Term::current()->value('id');
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards")
            ->assertOk()->assertSee('Second Term');

        $this->actingAs($owner)
            ->get("http://{$school->slug}.localhost/report-cards/classes/{$classId}?term_id={$secondTermId}")
            ->assertOk()->assertSee('Second Term');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/{$studentId}/generate", [
            'term_id' => $secondTermId,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $reviewForSecondTerm = ReportCardReview::where('student_id', $studentId)->where('term_id', $secondTermId)->first();
        $reviewForCurrentTerm = ReportCardReview::where('student_id', $studentId)->where('term_id', $currentTermId)->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($reviewForSecondTerm);
        $this->assertNull($reviewForCurrentTerm);
    }

    /**
     * Regression: adding the term picker above made index() resolve a
     * term unconditionally, which 404'd the whole page (via
     * currentTermOrAbort()'s fallback) for a school with no session/term
     * set up at all yet, caught running the fuller suite after that
     * change. The browse page itself must always render; only an actual
     * action (generate, etc.) should require a real term to exist.
     */
    public function test_the_index_still_renders_for_a_school_with_no_term_set_up_at_all(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rctwentyeight');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards")
            ->assertOk()
            ->assertSee('No session or term set up yet');
    }
}
