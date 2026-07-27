<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Notification;
use App\Models\Tenant\ReportCardFeedback;
use App\Models\Tenant\ReportCardReview;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The full Student and Parent Portal (user request 2026-07-13: "BUILD THE
 * FULL STUDENT AND PARENT PORTAL"): a student's own login, family-scoped
 * data access (a parent sees only their linked children, a student sees
 * only themselves), and the restricted nav both roles now get, matching
 * the same shape TeacherScope already established for class/subject
 * teachers.
 */
class StudentParentPortalTest extends TestCase
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

    protected function schoolWithMember(string $roleSlug, string $slug, array $userAttrs = []): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $user = User::factory()->create($userAttrs);
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    /** Assumes the tenant connection is already active; caller switches it back afterward. */
    protected function currentTerm(): Term
    {
        $session = AcademicSession::create(['name' => '2025/2026']);

        return Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true]);
    }

    /** An admin can create a student's own login, mirroring the guardian pattern exactly. */
    public function test_admin_can_create_a_students_login(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'portalone');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$studentId}/login", [
            'email' => 'ada.obi@example.com',
        ])->assertRedirect()->assertSessionHas('status');

        app(TenantManager::class)->use($school);
        $updated = Student::find($studentId);
        app(TenantManager::class)->forget();

        $this->assertNotNull($updated->user_id);
        $this->assertSame('ada.obi@example.com', $updated->email);

        $loginUser = User::find($updated->user_id);
        $this->assertTrue($loginUser->isMemberOf($school));
    }

    /** A student with exactly one linked record is sent straight to their own profile, not a list. */
    public function test_a_student_visiting_students_is_redirected_straight_to_their_own_profile(): void
    {
        [$school, $studentUser] = $this->schoolWithMember('student', 'portaltwo');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'user_id' => $studentUser->id]);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($studentUser)->get("http://{$school->slug}.localhost/students")
            ->assertRedirect(route('school.students.show', ['school' => $school->slug, 'studentId' => $studentId]));
    }

    /** A student can view their own report, but a different student's page 404s, not 403s (never confirm it exists). */
    public function test_a_student_can_view_their_own_report_but_not_another_students(): void
    {
        [$school, $studentUser] = $this->schoolWithMember('student', 'portalthree');

        app(TenantManager::class)->use($school);
        $own = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'user_id' => $studentUser->id]);
        $other = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        $ownId = $own->id;
        $otherId = $other->id;
        app(TenantManager::class)->forget();

        $this->actingAs($studentUser)->get("http://{$school->slug}.localhost/students/{$ownId}")
            ->assertOk()->assertSee('Ada Obi');

        $this->actingAs($studentUser)->get("http://{$school->slug}.localhost/students/{$otherId}")
            ->assertNotFound();
    }

    /** A parent sees a list of only their own linked children. */
    public function test_a_parent_sees_only_their_linked_children(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portalfour');

        app(TenantManager::class)->use($school);
        $ownChild = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $otherChild = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        StudentGuardian::create(['student_id' => $ownChild->id, 'name' => 'Mrs. Obi', 'guardian_user_id' => $parentUser->id]);
        $otherChildId = $otherChild->id;
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students")
            ->assertOk()->assertSee('Ada Obi')->assertDontSee('Bode Ade');

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students/{$otherChildId}")
            ->assertNotFound();
    }

    /** A parent with no linked children yet sees a helpful empty state, not an error. */
    public function test_a_parent_with_no_linked_children_sees_a_helpful_message(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portalfive');

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students")
            ->assertOk()->assertSee('No child is linked to your account yet');
    }

    /** A parent/student never sees admin-only nav items, but does see their own portal link. */
    public function test_a_parent_does_not_see_admin_only_nav_items(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portalsix');

        $response = $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk()
            ->assertSee('My Children')
            ->assertDontSee('Staff Records', false)
            ->assertDontSee('Backups', false)
            ->assertDontSee('Landing Pages', false);
    }

    /** A parent/student cannot manage (edit) a student even within their own family scope, students.update was never granted. */
    public function test_a_parent_cannot_edit_their_childs_record(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portalseven');

        app(TenantManager::class)->use($school);
        $child = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Mrs. Obi', 'guardian_user_id' => $parentUser->id]);
        $childId = $child->id;
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students/{$childId}/edit")
            ->assertForbidden();
    }

    /**
     * User request 2026-07-26: "parent should not be able to see report
     * cards that are yet to be release under the child Academic report."
     * No ReportCardReview row at all means never released.
     */
    public function test_a_parent_does_not_see_an_unreleased_report_card(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portalnine');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true]);
        $child = Student::create(['admission_number' => 'RC1', 'first_name' => 'Ada', 'last_name' => 'Card']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Mrs. Card', 'guardian_user_id' => $parentUser->id]);
        $childId = $child->id;
        app(TenantManager::class)->forget();

        $response = $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students/{$childId}/academic-report");

        // The "not released yet" notice does name the current term (good
        // UX, tells the parent which term is missing), so the check here
        // is that no SCORES table renders, not that the term name is
        // absent from the page entirely.
        $response->assertOk()
            ->assertSee('First Term&rsquo;s report card has not been released yet', false)
            ->assertDontSee('<th>Subject</th>', false);
    }

    public function test_a_parent_sees_a_released_report_card(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portalten');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true]);
        $child = Student::create(['admission_number' => 'RC2', 'first_name' => 'Bode', 'last_name' => 'Card']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Mr. Card', 'guardian_user_id' => $parentUser->id]);
        ReportCardReview::create(['student_id' => $child->id, 'term_id' => $term->id, 'status' => ReportCardReview::STATUS_RELEASED, 'review_stage' => 0]);
        $childId = $child->id;
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students/{$childId}/academic-report")
            ->assertOk()->assertSee('First Term')->assertDontSee('has not been released yet');
    }

    /** User request 2026-07-26: "parent should be able to switch to previous terms report cards that has been release." */
    public function test_a_parent_can_see_a_previous_released_term_via_the_switcher_even_though_the_current_term_is_not_released(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portaleleven');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $termOne = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => false]);
        $termTwo = Term::create(['academic_session_id' => $session->id, 'name' => 'Second Term', 'sequence' => 2, 'is_current' => true]);
        $child = Student::create(['admission_number' => 'RC3', 'first_name' => 'Chidi', 'last_name' => 'Card']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Mrs. Card', 'guardian_user_id' => $parentUser->id]);
        // Only the first, earlier term was ever released, the current (second) term has not been.
        ReportCardReview::create(['student_id' => $child->id, 'term_id' => $termOne->id, 'status' => ReportCardReview::STATUS_RELEASED, 'review_stage' => 0]);
        $childId = $child->id;
        app(TenantManager::class)->forget();

        // Default view (no term_id) lands on the released term, not the current unreleased one.
        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students/{$childId}/academic-report")
            ->assertOk()->assertSee('First Term')->assertSee('Second Term&rsquo;s report card has not been released yet', false);
    }

    /** A crafted term_id for a term that has not been released must never reveal that term's data, whatever the client sends. */
    public function test_a_parent_cannot_reach_an_unreleased_term_via_a_crafted_query_string(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portaltwelve');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true]);
        $child = Student::create(['admission_number' => 'RC4', 'first_name' => 'Dumebi', 'last_name' => 'Card']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Mrs. Card', 'guardian_user_id' => $parentUser->id]);
        $childId = $child->id;
        $termId = $term->id;
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students/{$childId}/academic-report?term_id={$termId}")
            ->assertOk()->assertSee('has not been released yet');
    }

    /** A teacher (staff) still sees the current term's live, in-progress scores regardless of release status, the gating is family-scoped only. */
    public function test_a_class_teacher_still_sees_the_current_term_regardless_of_release_status(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'portalthirteen');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => \App\Models\Tenant\SchoolClass::create(['section_id' => \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1])->id, 'name' => 'Primary 1', 'sequence' => 1])->id, 'class_teacher_staff_id' => \App\Models\Tenant\Staff::create(['staff_id' => 'STF1', 'first_name' => 'T', 'last_name' => 'Teacher', 'user_id' => $teacherUser->id])->id]);
        $child = Student::create(['admission_number' => 'RC5', 'first_name' => 'Emeka', 'last_name' => 'Card', 'current_class_arm_id' => $arm->id]);
        $childId = $child->id;
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/students/{$childId}/academic-report")
            ->assertOk()->assertSee('First Term')->assertDontSee('has not been released yet');
    }

    public function test_a_parent_can_submit_an_optional_comment_on_a_released_report_card(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portalfourteen');
        $teacherUser = User::factory()->create();
        $teacherRole = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($teacherUser->id, ['role_id' => $teacherRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => \App\Models\Tenant\SchoolClass::create(['section_id' => \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1])->id, 'name' => 'Primary 1', 'sequence' => 1])->id, 'class_teacher_staff_id' => \App\Models\Tenant\Staff::create(['staff_id' => 'STF2', 'first_name' => 'T', 'last_name' => 'Teacher', 'user_id' => $teacherUser->id])->id]);
        $child = Student::create(['admission_number' => 'RC6', 'first_name' => 'Fola', 'last_name' => 'Card', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Mrs. Card', 'guardian_user_id' => $parentUser->id]);
        ReportCardReview::create(['student_id' => $child->id, 'term_id' => $term->id, 'status' => ReportCardReview::STATUS_RELEASED, 'review_stage' => 0]);
        $childId = $child->id;
        $termId = $term->id;
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$childId}/report-feedback", [
            'term_id' => $termId, 'message' => 'Great progress this term, thank you.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $feedback = ReportCardFeedback::first();
        $this->assertNotNull($feedback);
        $this->assertSame('Great progress this term, thank you.', $feedback->message);
        $this->assertSame('parent', $feedback->submitted_by);
        $this->assertGreaterThan(0, Notification::where('category', 'report_card_feedback')->count());
        app(TenantManager::class)->forget();

        // A second visit shows the already-sent comment, not the form again.
        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students/{$childId}/academic-report")
            ->assertOk()->assertSee('You already sent a comment', false);
    }

    public function test_an_empty_comment_is_not_saved(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portalfifteen');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true]);
        $child = Student::create(['admission_number' => 'RC7', 'first_name' => 'Gozie', 'last_name' => 'Card']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Mrs. Card', 'guardian_user_id' => $parentUser->id]);
        ReportCardReview::create(['student_id' => $child->id, 'term_id' => $term->id, 'status' => ReportCardReview::STATUS_RELEASED, 'review_stage' => 0]);
        $childId = $child->id;
        $termId = $term->id;
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$childId}/report-feedback", [
            'term_id' => $termId, 'message' => '   ',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(0, ReportCardFeedback::count());
        app(TenantManager::class)->forget();
    }

    /** Never trust the client: a crafted term id for a card that was never released must be rejected outright, not silently saved. */
    public function test_a_parent_cannot_submit_feedback_for_an_unreleased_term(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portalsixteen');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true]);
        $child = Student::create(['admission_number' => 'RC8', 'first_name' => 'Halima', 'last_name' => 'Card']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Mrs. Card', 'guardian_user_id' => $parentUser->id]);
        $childId = $child->id;
        $termId = $term->id;
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$childId}/report-feedback", [
            'term_id' => $termId, 'message' => 'Trying to sneak this in.',
        ])->assertNotFound();
    }

    /** User request 2026-07-26: "there should be a button to view the detailed report card which is the pdf format." */
    public function test_a_parent_can_open_the_full_report_card_once_released(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portalseventeen');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $child = Student::create(['admission_number' => 'RC9', 'first_name' => 'Ijeoma', 'last_name' => 'Card']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Mrs. Card', 'guardian_user_id' => $parentUser->id]);
        ReportCardReview::create(['student_id' => $child->id, 'term_id' => $term->id, 'status' => ReportCardReview::STATUS_RELEASED, 'review_stage' => 0]);
        $childId = $child->id;
        $termId = $term->id;
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students/{$childId}/academic-report")
            ->assertOk()->assertSee(route('school.report-cards.show', ['school' => $school->slug, 'studentId' => $childId, 'termId' => $termId]), false);

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/report-cards/{$childId}/{$termId}")
            ->assertOk();
    }

    /** A parent must never reach the full report card for a term that has not been released, whatever the URL says. */
    public function test_a_parent_cannot_open_the_full_report_card_for_an_unreleased_term(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portaleighteen');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $child = Student::create(['admission_number' => 'RC10', 'first_name' => 'Kelechi', 'last_name' => 'Card']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Mrs. Card', 'guardian_user_id' => $parentUser->id]);
        $childId = $child->id;
        $termId = $term->id;
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/report-cards/{$childId}/{$termId}")
            ->assertNotFound();
    }

    /** User request 2026-07-26: "parent should not be seeing the remark column if its not enabled in the school setting." */
    public function test_the_academic_report_hides_the_remark_column_when_disabled(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portalnineteen');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $child = Student::create(['admission_number' => 'RC11', 'first_name' => 'Lolade', 'last_name' => 'Card']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Mrs. Card', 'guardian_user_id' => $parentUser->id]);
        ReportCardReview::create(['student_id' => $child->id, 'term_id' => $term->id, 'status' => ReportCardReview::STATUS_RELEASED, 'review_stage' => 0]);
        app(\App\Services\ReportCards\ReportCardPreferences::class)->setFields(['subject_remark' => false]);
        $childId = $child->id;
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students/{$childId}/academic-report")
            ->assertOk()->assertDontSee('<th>Remark</th>', false);
    }

    /** User request 2026-07-26: "Academic report should be removed from the student profile, it should have a stand alone submenu under student portal." */
    public function test_a_parent_with_one_child_is_taken_straight_to_their_academic_report(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portaltwenty');

        app(TenantManager::class)->use($school);
        $child = Student::create(['admission_number' => 'RC12', 'first_name' => 'Ngozi', 'last_name' => 'Card']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Mrs. Card', 'guardian_user_id' => $parentUser->id]);
        $childId = $child->id;
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/student-portal/report")
            ->assertRedirect(route('school.students.academic-report', ['school' => $school->slug, 'studentId' => $childId]));
    }

    public function test_a_parent_with_multiple_children_sees_a_picker(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portaltwentyone');

        app(TenantManager::class)->use($school);
        $childA = Student::create(['admission_number' => 'RC13', 'first_name' => 'Obinna', 'last_name' => 'Card']);
        $childB = Student::create(['admission_number' => 'RC14', 'first_name' => 'Peace', 'last_name' => 'Card']);
        StudentGuardian::create(['student_id' => $childA->id, 'name' => 'Mrs. Card', 'guardian_user_id' => $parentUser->id]);
        StudentGuardian::create(['student_id' => $childB->id, 'name' => 'Mrs. Card', 'guardian_user_id' => $parentUser->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/student-portal/report")
            ->assertOk()->assertSee('Obinna Card')->assertSee('Peace Card');
    }

    public function test_the_academic_report_nav_link_shows_for_a_parent(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'portaltwentytwo');

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('Academic Report');
    }

    public function test_a_teacher_without_students_update_cannot_create_a_student_login(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'portaleight');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/students/{$studentId}/login", [
            'email' => 'ada.obi@example.com',
        ])->assertForbidden();
    }

    /** Resetting/toggling a student's login access, same as the guardian equivalent. */
    public function test_admin_can_reset_and_toggle_a_students_login(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'portalnine');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$studentId}/login", ['email' => 'ada.obi@example.com']);

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$studentId}/login/reset-password")
            ->assertRedirect()->assertSessionHas('status');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/{$studentId}/login/toggle-access")
            ->assertRedirect()->assertSessionHas('status');

        app(TenantManager::class)->use($school);
        $updated = Student::find($studentId);
        $pivotStatus = $school->users()->whereKey($updated->user_id)->first()->pivot->status;
        app(TenantManager::class)->forget();

        $this->assertSame('suspended', $pivotStatus);
    }
}
