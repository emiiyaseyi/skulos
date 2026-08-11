<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Notification;
use App\Models\Tenant\SchoolClass as TenantSchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\Tenant\StudentProfileChangeRequest;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A parent's requested change to their child's profile, held for the class
 * teacher then the school admin to decide in order, before it applies to
 * the real student record (user request 2026-07-26: "parent should be
 * able to edit their child details (everything there except admission
 * number, class and house) - class teacher has to approve the changes
 * first then the school admin also have to do the final approval before
 * it takes effect on the parent and student details").
 */
class StudentProfileChangeTest extends TestCase
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

    /**
     * A student with a real class arm, a class teacher (with their own
     * login), and a parent guardian linked to it. Assumes the tenant
     * connection for $school is already active.
     */
    protected function studentWithClassTeacherAndParent(School $school): array
    {
        $teacherUser = User::factory()->create();
        $teacherRole = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($teacherUser->id, ['role_id' => $teacherRole->id, 'status' => 'active']);
        $staff = Staff::create(['staff_id' => 'STF1', 'first_name' => 'Class', 'last_name' => 'Teacher', 'user_id' => $teacherUser->id]);

        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = TenantSchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'class_teacher_staff_id' => $staff->id]);

        $student = Student::create([
            'admission_number' => 'ADM/001', 'first_name' => 'Ada', 'last_name' => 'Obi',
            'gender' => 'Female', 'current_class_arm_id' => $arm->id, 'status' => 'active',
        ]);

        [, $parentUser] = $this->schoolWithMemberReuse($school, 'parent');
        StudentGuardian::create([
            'student_id' => $student->id, 'name' => $parentUser->name, 'relationship' => 'Mother',
            'email' => $parentUser->email, 'is_primary' => true, 'guardian_user_id' => $parentUser->id,
        ]);

        return [$student, $parentUser, $teacherUser, $staff];
    }

    /** Attaches a NEW user to an already-provisioned school under a role, without re-provisioning. */
    protected function schoolWithMemberReuse(School $school, string $roleSlug, array $userAttrs = []): array
    {
        $user = User::factory()->create($userAttrs);
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_a_parent_can_view_the_request_changes_form_for_their_own_child(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'pctest1');
        app(TenantManager::class)->use($school);
        [$student, $parentUser] = $this->studentWithClassTeacherAndParent($school);
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->get("http://{$school->slug}.localhost/students/{$student->id}/request-changes")
            ->assertOk()->assertSee('Request Changes');
    }

    public function test_a_parent_cannot_reach_the_form_for_a_child_that_is_not_theirs(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'pctest2');
        app(TenantManager::class)->use($school);
        [$student] = $this->studentWithClassTeacherAndParent($school);
        app(TenantManager::class)->forget();

        [, $otherParent] = $this->schoolWithMemberReuse($school, 'parent');

        $this->actingAs($otherParent)->get("http://{$school->slug}.localhost/students/{$student->id}/request-changes")
            ->assertNotFound();
    }

    public function test_submitting_a_change_with_a_class_teacher_assigned_routes_to_them_and_notifies_them(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'pctest3');
        app(TenantManager::class)->use($school);
        [$student, $parentUser, $teacherUser] = $this->studentWithClassTeacherAndParent($school);

        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", [
            'first_name' => 'Ada', 'last_name' => 'Obinna',
        ])->assertRedirect();

        $request = StudentProfileChangeRequest::first();
        $this->assertNotNull($request);
        $this->assertSame(StudentProfileChangeRequest::STATUS_PENDING_CLASS_TEACHER, $request->status);
        $this->assertSame('Obinna', $request->requested['last_name']);
        $this->assertSame('Obi', $request->original['last_name']);
        $this->assertSame('Ada', $student->fresh()->first_name, 'must not apply until fully approved');

        $notified = Notification::where('user_id', $teacherUser->id)
            ->where('category', 'student_profile_change_pending_class_teacher')->exists();
        $this->assertTrue($notified);
    }

    public function test_submitting_a_change_with_no_class_teacher_assigned_goes_straight_to_admin(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'pctest4');
        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'ADM/002', 'first_name' => 'Bola', 'last_name' => 'Ade', 'status' => 'active']);
        [, $parentUser] = $this->schoolWithMemberReuse($school, 'parent');
        StudentGuardian::create([
            'student_id' => $student->id, 'name' => $parentUser->name, 'relationship' => 'Father',
            'email' => $parentUser->email, 'is_primary' => true, 'guardian_user_id' => $parentUser->id,
        ]);

        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", [
            'first_name' => 'Bola', 'last_name' => 'Adeyemi',
        ])->assertRedirect();

        $request = StudentProfileChangeRequest::first();
        $this->assertSame(StudentProfileChangeRequest::STATUS_PENDING_ADMIN, $request->status);
        $this->assertNull($request->class_teacher_staff_id);

        $notified = Notification::where('user_id', $admin->id)
            ->where('category', 'student_profile_change_pending_admin')->exists();
        $this->assertTrue($notified);
    }

    public function test_a_second_submission_is_blocked_while_one_is_already_pending(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'pctest5');
        app(TenantManager::class)->use($school);
        [$student, $parentUser] = $this->studentWithClassTeacherAndParent($school);

        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", ['first_name' => 'Ada', 'last_name' => 'First']);
        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", ['first_name' => 'Ada', 'last_name' => 'Second'])
            ->assertStatus(422);

        $this->assertSame(1, StudentProfileChangeRequest::count());
    }

    public function test_submitting_with_nothing_actually_changed_creates_no_request(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'pctest6');
        app(TenantManager::class)->use($school);
        [$student, $parentUser] = $this->studentWithClassTeacherAndParent($school);

        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", [
            'first_name' => $student->first_name, 'last_name' => $student->last_name, 'gender' => 'female',
        ])->assertRedirect()->assertSessionHas('status', 'Nothing changed.');

        $this->assertSame(0, StudentProfileChangeRequest::count(), 'a lowercase gender resubmission of the same value must not be treated as a change');
    }

    public function test_the_class_teacher_can_approve_and_it_moves_to_admin(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'pctest7');
        app(TenantManager::class)->use($school);
        [$student, $parentUser, $teacherUser] = $this->studentWithClassTeacherAndParent($school);
        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", ['first_name' => 'Ada', 'last_name' => 'Chukwu']);
        $request = StudentProfileChangeRequest::first();
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->post("http://{$school->slug}.localhost/students/change-requests/{$request->id}/class-teacher/approve")
            ->assertRedirect();

        $this->assertSame(StudentProfileChangeRequest::STATUS_PENDING_ADMIN, $request->fresh()->status);
        $this->assertSame('Obi', $student->fresh()->last_name, 'still not applied, admin has not decided yet');
    }

    public function test_the_class_teacher_can_reject_with_a_reason(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'pctest8');
        app(TenantManager::class)->use($school);
        [$student, $parentUser, $teacherUser] = $this->studentWithClassTeacherAndParent($school);
        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", ['first_name' => 'Ada', 'last_name' => 'Chukwu']);
        $request = StudentProfileChangeRequest::first();
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->post("http://{$school->slug}.localhost/students/change-requests/{$request->id}/class-teacher/reject", [
            'notes' => 'Spelling does not match the birth certificate on file.',
        ])->assertRedirect();

        $this->assertSame(StudentProfileChangeRequest::STATUS_REJECTED_BY_CLASS_TEACHER, $request->fresh()->status);

        $notified = Notification::where('user_id', $parentUser->id)->where('category', 'student_profile_change_rejected')->exists();
        $this->assertTrue($notified);
    }

    public function test_a_staff_member_who_is_not_the_class_teacher_cannot_decide_it(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'pctest9');
        app(TenantManager::class)->use($school);
        [$student, $parentUser] = $this->studentWithClassTeacherAndParent($school);
        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", ['first_name' => 'Ada', 'last_name' => 'Chukwu']);
        $request = StudentProfileChangeRequest::first();
        app(TenantManager::class)->forget();

        [, $otherTeacher] = $this->schoolWithMemberReuse($school, 'subject-teacher');

        $this->actingAs($otherTeacher)->post("http://{$school->slug}.localhost/students/change-requests/{$request->id}/class-teacher/approve")
            ->assertForbidden();
    }

    public function test_admin_approval_applies_the_change_to_the_real_student_record(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'pctest10');
        app(TenantManager::class)->use($school);
        [$student, $parentUser, $teacherUser] = $this->studentWithClassTeacherAndParent($school);
        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", ['first_name' => 'Ada', 'last_name' => 'Chukwu']);
        $request = StudentProfileChangeRequest::first();
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->post("http://{$school->slug}.localhost/students/change-requests/{$request->id}/class-teacher/approve");

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/change-requests/{$request->id}/admin/approve")
            ->assertRedirect();

        $this->assertSame(StudentProfileChangeRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertSame('Chukwu', $student->fresh()->last_name, 'now applied, both stages approved');

        $notified = Notification::where('user_id', $parentUser->id)->where('category', 'student_profile_change_approved')->exists();
        $this->assertTrue($notified);
    }

    public function test_admin_can_reject_with_a_reason_and_nothing_is_applied(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'pctest11');
        app(TenantManager::class)->use($school);
        [$student, $parentUser, $teacherUser] = $this->studentWithClassTeacherAndParent($school);
        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", ['first_name' => 'Ada', 'last_name' => 'Chukwu']);
        $request = StudentProfileChangeRequest::first();
        app(TenantManager::class)->forget();
        $this->actingAs($teacherUser)->post("http://{$school->slug}.localhost/students/change-requests/{$request->id}/class-teacher/approve");

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/change-requests/{$request->id}/admin/reject", [
            'notes' => 'Please provide supporting documentation first.',
        ])->assertRedirect();

        $this->assertSame(StudentProfileChangeRequest::STATUS_REJECTED_BY_ADMIN, $request->fresh()->status);
        $this->assertSame('Obi', $student->fresh()->last_name);
    }

    public function test_admin_index_shows_requests_still_pending_with_the_class_teacher_too(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'pctest10b');
        app(TenantManager::class)->use($school);
        [$student, $parentUser] = $this->studentWithClassTeacherAndParent($school);
        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", ['first_name' => 'Ada', 'last_name' => 'Chukwu']);
        app(TenantManager::class)->forget();

        // Previously invisible here: adminIndex() only ever queried
        // STATUS_PENDING_ADMIN, so a request still sitting with the
        // class teacher (as this one is, no approve/reject yet) never
        // showed up on the admin's own review screen at all.
        $this->actingAs($admin)->get("http://{$school->slug}.localhost/students/change-requests/admin")
            ->assertOk()
            ->assertSee('Ada')
            ->assertSee('Awaiting class teacher');
    }

    public function test_admin_can_nudge_a_class_teacher_stage_request(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'pctest10c');
        app(TenantManager::class)->use($school);
        [$student, $parentUser, $teacherUser] = $this->studentWithClassTeacherAndParent($school);
        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", ['first_name' => 'Ada', 'last_name' => 'Chukwu']);
        $request = StudentProfileChangeRequest::first();
        app(TenantManager::class)->forget();

        $before = Notification::where('user_id', $teacherUser->id)->count();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/change-requests/{$request->id}/admin/nudge-class-teacher")
            ->assertRedirect();

        $this->assertSame($before + 1, Notification::where('user_id', $teacherUser->id)->count());
    }

    public function test_admin_can_override_approve_a_class_teacher_stage_request_directly(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'pctest10d');
        app(TenantManager::class)->use($school);
        [$student, $parentUser] = $this->studentWithClassTeacherAndParent($school);
        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", ['first_name' => 'Ada', 'last_name' => 'Chukwu']);
        $request = StudentProfileChangeRequest::first();
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/change-requests/{$request->id}/admin/override-approve")
            ->assertRedirect();

        $this->assertSame(StudentProfileChangeRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertSame('Chukwu', $student->fresh()->last_name, 'skipped straight past the class teacher stage');

        $notified = Notification::where('user_id', $parentUser->id)->where('category', 'student_profile_change_approved')->exists();
        $this->assertTrue($notified);
    }

    public function test_admin_can_override_reject_a_class_teacher_stage_request_and_nothing_is_applied(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'pctest10e');
        app(TenantManager::class)->use($school);
        [$student, $parentUser] = $this->studentWithClassTeacherAndParent($school);
        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", ['first_name' => 'Ada', 'last_name' => 'Chukwu']);
        $request = StudentProfileChangeRequest::first();
        app(TenantManager::class)->forget();

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/change-requests/{$request->id}/admin/override-reject", [
            'notes' => 'Not needed, spoke to the parent directly.',
        ])->assertRedirect();

        $this->assertSame(StudentProfileChangeRequest::STATUS_REJECTED_BY_ADMIN, $request->fresh()->status);
        $this->assertSame('Obi', $student->fresh()->last_name);
    }

    public function test_a_non_admin_tier_staff_member_cannot_reach_the_admin_queue(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'pctest12');
        app(TenantManager::class)->use($school);
        app(TenantManager::class)->forget();

        [, $teacher] = $this->schoolWithMemberReuse($school, 'subject-teacher');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/students/change-requests/admin")
            ->assertForbidden();
    }

    public function test_class_teacher_bulk_approve_advances_every_ticked_request(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'pctest13');
        app(TenantManager::class)->use($school);
        [$studentA, $parentA, $teacherUser] = $this->studentWithClassTeacherAndParent($school);
        $this->actingAs($parentA)->post("http://{$school->slug}.localhost/students/{$studentA->id}/request-changes", ['first_name' => 'Ada', 'last_name' => 'One']);
        $requestA = StudentProfileChangeRequest::first();
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->post("http://{$school->slug}.localhost/students/change-requests/class-teacher/bulk-approve", [
            'request_ids' => [$requestA->id],
        ])->assertRedirect();

        $this->assertSame(StudentProfileChangeRequest::STATUS_PENDING_ADMIN, $requestA->fresh()->status);
    }

    public function test_admin_bulk_approve_applies_every_ticked_request(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'pctest14');
        app(TenantManager::class)->use($school);
        [$student, $parentUser, $teacherUser] = $this->studentWithClassTeacherAndParent($school);
        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", ['first_name' => 'Ada', 'last_name' => 'Bulk']);
        $request = StudentProfileChangeRequest::first();
        app(TenantManager::class)->forget();
        $this->actingAs($teacherUser)->post("http://{$school->slug}.localhost/students/change-requests/{$request->id}/class-teacher/approve");

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/change-requests/admin/bulk-approve", [
            'request_ids' => [$request->id],
        ])->assertRedirect();

        $this->assertSame(StudentProfileChangeRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertSame('Bulk', $student->fresh()->last_name);
    }

    public function test_admission_number_class_and_house_are_never_editable_through_this_form(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'pctest15');
        app(TenantManager::class)->use($school);
        [$student, $parentUser] = $this->studentWithClassTeacherAndParent($school);
        $originalArmId = $student->current_class_arm_id;

        $this->actingAs($parentUser)->post("http://{$school->slug}.localhost/students/{$student->id}/request-changes", [
            'last_name' => 'Obi',
            'admission_number' => 'HACKED/999',
            'current_class_arm_id' => 999999,
        ])->assertRedirect();

        // Nothing actually changed (last_name resubmitted identically and
        // the two smuggled fields are not in editableFieldRules() at
        // all), so no request should even have been created.
        $this->assertSame(0, StudentProfileChangeRequest::count());
        $this->assertSame('ADM/001', $student->fresh()->admission_number);
        $this->assertSame($originalArmId, $student->fresh()->current_class_arm_id);
    }

    /**
     * User correction 2026-08-05: "subject teachers should not have
     * access to student profile change request as its meant for only
     * class teachers and admin. This only shows if the subject teacher
     * is given the access."
     */
    public function test_a_subject_teacher_cannot_reach_the_class_teacher_queue_by_default(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'pctest16');
        app(TenantManager::class)->use($school);
        [, $subjectTeacher] = $this->schoolWithMemberReuse($school, 'subject-teacher');
        app(TenantManager::class)->forget();

        $this->actingAs($subjectTeacher)->get("http://{$school->slug}.localhost/students/change-requests/class-teacher")
            ->assertForbidden();
    }

    public function test_a_class_teacher_can_still_reach_the_queue(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'pctest17');
        app(TenantManager::class)->use($school);
        [, , $teacherUser] = $this->studentWithClassTeacherAndParent($school);
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/students/change-requests/class-teacher")
            ->assertOk();
    }

    public function test_a_subject_teacher_granted_the_students_extra_access_override_can_reach_the_queue(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'pctest18');
        app(TenantManager::class)->use($school);
        [, $subjectTeacher] = $this->schoolWithMemberReuse($school, 'subject-teacher');
        $this->rbac->grantModuleOverride($school, $subjectTeacher, 'students');
        app(TenantManager::class)->forget();

        $this->actingAs($subjectTeacher)->get("http://{$school->slug}.localhost/students/change-requests/class-teacher")
            ->assertOk();
    }
}
