<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\GradingBand;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\ProfileChangeRequest;
use App\Models\Tenant\ReportCardReview;
use App\Models\Tenant\Score;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TimetablePeriod;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The bulk-action retrofit from the 2026-07-20 UI/UX audit (Build Log:
 * "UI/UX audit fix batch"): one happy-path (plus, where the endpoint has
 * real branching, the interesting edge case) per newly added bulk
 * controller method, on top of the existing per-item single-action
 * coverage each of these already had.
 */
class UiAuditBulkActionsTest extends TestCase
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

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_admin_can_bulk_suspend_and_bulk_delete_schools(): void
    {
        $admin = $this->superAdmin();
        $a = School::factory()->create(['status' => 'active']);
        $b = School::factory()->create(['status' => 'active']);

        $this->actingAs($admin)->post(route('admin.schools.bulk-action'), [
            'school_ids' => [$a->id, $b->id],
            'action' => 'suspend',
        ])->assertRedirect();
        $this->assertSame('suspended', $a->fresh()->status);
        $this->assertSame('suspended', $b->fresh()->status);

        $this->actingAs($admin)->post(route('admin.schools.bulk-action'), [
            'school_ids' => [$a->id],
            'action' => 'delete',
        ])->assertRedirect();
        $this->assertSoftDeleted('schools', ['id' => $a->id]);
    }

    public function test_admin_can_bulk_restore_archived_schools(): void
    {
        $admin = $this->superAdmin();
        $school = School::factory()->create();
        $school->delete();

        $this->actingAs($admin)->post(route('admin.schools.archive.bulk-restore'), [
            'school_ids' => [$school->id],
        ])->assertRedirect();

        $this->assertNull($school->fresh()->deleted_at);
    }

    public function test_bulk_detach_subjects_removes_only_the_selected_ones(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'baa1');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary']);
        $class = \App\Models\Tenant\SchoolClass::create(['name' => 'JSS1', 'section_id' => $section->id]);
        $math = Subject::create(['name' => 'Mathematics']);
        $english = Subject::create(['name' => 'English']);
        $class->subjects()->attach([$math->id => ['is_mandatory' => true, 'is_examinable' => true], $english->id => ['is_mandatory' => true, 'is_examinable' => true]]);
        $classId = $class->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/classes/{$classId}/subjects/bulk-detach", [
            'subject_ids' => [$math->id],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $remaining = $class->fresh()->subjects()->pluck('subjects.id')->all();
        app(TenantManager::class)->forget();

        $this->assertSame([$english->id], $remaining);
    }

    public function test_bulk_destroy_grading_bands(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'baa2');

        app(TenantManager::class)->use($school);
        $scale = GradingScale::create(['name' => 'Standard']);
        $a = GradingBand::create(['grading_scale_id' => $scale->id, 'grade' => 'A', 'min_score' => 70, 'max_score' => 100]);
        $b = GradingBand::create(['grading_scale_id' => $scale->id, 'grade' => 'B', 'min_score' => 50, 'max_score' => 69]);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/examinations/grading/bands/bulk-destroy", [
            'band_ids' => [$a->id, $b->id],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $count = GradingBand::count();
        app(TenantManager::class)->forget();
        $this->assertSame(0, $count);
    }

    public function test_bulk_destroy_assessment_components_skips_a_scored_one(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'baa3');

        app(TenantManager::class)->use($school);
        $clean = AssessmentComponent::create(['name' => 'First CA', 'type' => AssessmentComponent::TYPE_CA, 'max_score' => 20, 'sequence' => 1]);
        $scored = AssessmentComponent::create(['name' => 'Second CA', 'type' => AssessmentComponent::TYPE_CA, 'max_score' => 20, 'sequence' => 2]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $section = Section::create(['name' => 'Secondary']);
        $class = \App\Models\Tenant\SchoolClass::create(['name' => 'JSS1', 'section_id' => $section->id]);
        $arm = \App\Models\Tenant\Arm::create(['name' => 'A']);
        $classArm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true]);
        $term = \App\Models\Tenant\Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'is_current' => true]);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id, 'term_id' => $term->id, 'assessment_component_id' => $scored->id, 'score' => 15]);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/examinations/structure/bulk-destroy", [
            'component_ids' => [$clean->id, $scored->id],
        ])->assertRedirect()->assertSessionHas('error');

        app(TenantManager::class)->use($school);
        $remaining = AssessmentComponent::pluck('id')->all();
        app(TenantManager::class)->forget();

        $this->assertSame([$scored->id], $remaining);
    }

    public function test_bulk_destroy_timetable_periods(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'baa4');

        app(TenantManager::class)->use($school);
        $p1 = TimetablePeriod::create(['name' => 'Period 1', 'sequence' => 1, 'starts_at' => '08:00:00', 'ends_at' => '08:40:00', 'is_teaching_period' => true]);
        $p2 = TimetablePeriod::create(['name' => 'Period 2', 'sequence' => 2, 'starts_at' => '08:40:00', 'ends_at' => '09:20:00', 'is_teaching_period' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/timetable/periods/bulk-destroy", [
            'period_ids' => [$p1->id, $p2->id],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $count = TimetablePeriod::count();
        app(TenantManager::class)->forget();
        $this->assertSame(0, $count);
    }

    public function test_bulk_unarchive_academic_sessions(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'baa5');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2024/2025']);
        $session->archive();
        $sessionId = $session->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/academics/sessions/bulk-unarchive", [
            'session_ids' => [$sessionId],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $restored = AcademicSession::find($sessionId);
        app(TenantManager::class)->forget();
        $this->assertFalse($restored->is_archived);
    }

    public function test_bulk_approve_profile_change_requests(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'baa6');

        app(TenantManager::class)->use($school);
        $applicant = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tolu', 'last_name' => 'Bello', 'status' => 'active']);
        $applicantUser = User::factory()->create(['email' => 'tolu@example.test']);
        $req = ProfileChangeRequest::create([
            'user_id' => $applicantUser->id,
            'requested' => ['name' => 'Tolu Bello Jr'],
            'original' => ['name' => 'Tolu Bello'],
            'status' => ProfileChangeRequest::STATUS_PENDING,
        ]);
        $reqId = $req->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/profile/approvals/bulk-approve", [
            'request_ids' => [$reqId],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = ProfileChangeRequest::find($reqId);
        $applicantUserAfter = \App\Models\User::find($applicantUser->id);
        app(TenantManager::class)->forget();

        $this->assertSame(ProfileChangeRequest::STATUS_APPROVED, $updated->status);
        $this->assertSame('Tolu Bello Jr', $applicantUserAfter->name);
    }

    public function test_bulk_reject_profile_change_requests_requires_a_shared_reason(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'baa7');

        app(TenantManager::class)->use($school);
        $applicantUser = User::factory()->create();
        $req = ProfileChangeRequest::create([
            'user_id' => $applicantUser->id,
            'requested' => ['name' => 'New Name'],
            'original' => ['name' => 'Old Name'],
            'status' => ProfileChangeRequest::STATUS_PENDING,
        ]);
        $reqId = $req->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/profile/approvals/bulk-reject", [
            'request_ids' => [$reqId],
        ])->assertSessionHasErrors('notes');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/profile/approvals/bulk-reject", [
            'request_ids' => [$reqId],
            'notes' => 'Not authorized to change this.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = ProfileChangeRequest::find($reqId);
        app(TenantManager::class)->forget();
        $this->assertSame(ProfileChangeRequest::STATUS_REJECTED, $updated->status);
    }

    public function test_bulk_approve_report_card_reviews(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'baa8');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true]);
        $term = \App\Models\Tenant\Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'is_current' => true]);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $review = ReportCardReview::create(['student_id' => $student->id, 'term_id' => $term->id, 'status' => ReportCardReview::STATUS_PENDING_REVIEW]);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/report-cards/bulk-approve", [
            'student_ids' => [$studentId],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = ReportCardReview::find($review->id);
        app(TenantManager::class)->forget();
        $this->assertSame(ReportCardReview::STATUS_APPROVED, $updated->status);
    }

    public function test_bulk_send_back_report_cards_skips_one_with_no_assignee(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'baa9');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true]);
        $term = \App\Models\Tenant\Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'is_current' => true]);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tolu', 'last_name' => 'Bello', 'status' => 'active']);
        $assigned = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $unassigned = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        ReportCardReview::create(['student_id' => $assigned->id, 'term_id' => $term->id, 'status' => ReportCardReview::STATUS_PENDING_REVIEW, 'assigned_to_staff_id' => $staff->id]);
        $assignedId = $assigned->id;
        $unassignedId = $unassigned->id;
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/report-cards/bulk-send-back", [
            'student_ids' => [$assignedId, $unassignedId],
            'notes' => 'Please fix the totals.',
        ])->assertRedirect()->assertSessionHas('status');

        app(TenantManager::class)->use($school);
        $assignedReview = ReportCardReview::where('student_id', $assignedId)->first();
        $unassignedReview = ReportCardReview::where('student_id', $unassignedId)->first();
        app(TenantManager::class)->forget();

        $this->assertSame(ReportCardReview::STATUS_NEEDS_CORRECTION, $assignedReview->status);
        $this->assertNull($unassignedReview, 'a student with no review and no assignee must not get one created');
    }

    public function test_a_teacher_without_permission_cannot_bulk_approve_report_cards(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'baa10');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $studentId = $student->id;
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/report-cards/bulk-approve", [
            'student_ids' => [$studentId],
        ])->assertForbidden();
    }
}
