<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Arm;
use App\Models\Tenant\Assignment;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Models\User;
use App\Services\Dashboard\RoleDashboard;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Assignments (Dashboard Design Cross-Check, scope decision 2026-07-15:
 * "Skip Behaviour entirely for now, build Assignments only"). A teacher
 * posts homework for one subject/class arm; students and parents see it
 * on their own dashboards, scoped to their own class/family only.
 */
class AssignmentTest extends TestCase
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

    public function test_an_owner_can_post_an_assignment(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'asgone');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 4', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $subject = Subject::create(['name' => 'Mathematics', 'code' => 'MTH']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.assignments.store', ['school' => $school->slug]), [
            'title' => 'Fractions worksheet', 'subject_id' => $subject->id, 'class_arm_id' => $arm->id,
            'due_on' => now()->addDays(7)->toDateString(), 'description' => 'Complete exercises 1 to 10.',
        ])->assertRedirect(route('school.assignments.index', ['school' => $school->slug]));

        app(TenantManager::class)->use($school);
        $assignment = Assignment::where('title', 'Fractions worksheet')->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($assignment);
        $this->assertSame($subject->id, $assignment->subject_id);
        $this->assertSame($arm->id, $assignment->class_arm_id);
    }

    /**
     * Audit finding L-1 (2026-07-17): destroy() previously typed
     * $assignmentId as a scalar method parameter, which this route's
     * domain wildcard ({school}.<domain>) caused Laravel to bind
     * positionally instead of by name, silently receiving the school
     * slug instead of the assignment id, so this delete always 404'd.
     */
    public function test_an_owner_can_actually_delete_an_assignment(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'asgten');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 4', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $subject = Subject::create(['name' => 'Mathematics', 'code' => 'MTH']);
        $assignment = Assignment::create(['title' => 'To be deleted', 'subject_id' => $subject->id, 'class_arm_id' => $arm->id]);
        $assignmentId = $assignment->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->delete(route('school.assignments.destroy', ['school' => $school->slug, 'assignmentId' => $assignmentId]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertNull(Assignment::find($assignmentId));
        app(TenantManager::class)->forget();
    }

    public function test_a_subject_teacher_cannot_post_to_a_class_they_do_not_teach(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'asgtwo');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $ownArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $otherArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);
        $subject = Subject::create(['name' => 'Basic Science', 'code' => 'BSC']);
        $staff = Staff::create(['staff_id' => 'STF/1', 'first_name' => 'S', 'last_name' => 'T', 'user_id' => $teacher->id]);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $subject->id, 'class_arm_id' => $ownArm->id]);
        app(TenantManager::class)->forget();

        // Their own class: allowed.
        $this->actingAs($teacher)->post(route('school.assignments.store', ['school' => $school->slug]), [
            'title' => 'Mine', 'subject_id' => $subject->id, 'class_arm_id' => $ownArm->id,
        ])->assertRedirect();

        // A class they do not teach: 404 (not 403, matching the scoping
        // pattern that never leaks whether the row exists).
        $this->actingAs($teacher)->post(route('school.assignments.store', ['school' => $school->slug]), [
            'title' => 'Not mine', 'subject_id' => $subject->id, 'class_arm_id' => $otherArm->id,
        ])->assertNotFound();

        app(TenantManager::class)->use($school);
        $this->assertSame(1, Assignment::count());
        app(TenantManager::class)->forget();
    }

    public function test_a_student_only_sees_assignments_for_their_own_class_arm(): void
    {
        [$school, $studentUser] = $this->schoolWithMember('student', 'asgthree');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 5', 'sequence' => 1]);
        $myArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $otherArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);
        $subject = Subject::create(['name' => 'English', 'code' => 'ENG']);
        Student::create(['admission_number' => 'ST1', 'first_name' => 'My', 'last_name' => 'Student', 'current_class_arm_id' => $myArm->id, 'user_id' => $studentUser->id]);

        Assignment::create(['title' => 'My homework', 'subject_id' => $subject->id, 'class_arm_id' => $myArm->id, 'due_on' => now()->addDays(3)]);
        Assignment::create(['title' => 'Other class homework', 'subject_id' => $subject->id, 'class_arm_id' => $otherArm->id, 'due_on' => now()->addDays(3)]);
        app(TenantManager::class)->forget();

        $data = app(RoleDashboard::class)->build($studentUser, $school);
        $titles = collect($data['panel']['upcomingAssignments'])->pluck('title');

        $this->assertTrue($titles->contains('My homework'));
        $this->assertFalse($titles->contains('Other class homework'), 'a student must not see another class arm\'s assignment');

        // The index screen enforces the same scoping, not just the dashboard.
        $this->actingAs($studentUser)->get(route('school.assignments.index', ['school' => $school->slug]))
            ->assertOk()
            ->assertSee('My homework')
            ->assertDontSee('Other class homework');
    }

    public function test_a_parent_sees_upcoming_assignments_for_their_children(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'asgfour');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 6', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $subject = Subject::create(['name' => 'Maths', 'code' => 'MTH']);
        $child = Student::create(['admission_number' => 'CH1', 'first_name' => 'Child', 'last_name' => 'One', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parentUser->id, 'is_primary' => true]);
        Assignment::create(['title' => 'Reading log', 'subject_id' => $subject->id, 'class_arm_id' => $arm->id, 'due_on' => now()->addDays(2)]);
        app(TenantManager::class)->forget();

        $data = app(RoleDashboard::class)->build($parentUser, $school);
        $titles = collect($data['panel']['upcomingAssignments'])->pluck('title');
        $this->assertTrue($titles->contains('Reading log'));
    }

    public function test_a_student_cannot_reach_the_create_form(): void
    {
        [$school, $studentUser] = $this->schoolWithMember('student', 'asgfive');

        $this->actingAs($studentUser)->get(route('school.assignments.create', ['school' => $school->slug]))->assertForbidden();
    }

    /**
     * User directive 2026-07-16: "CLASS TEACHER CAN SEE ALL THE ASSIGNMENT
     * FOR THE CLASS, ACROSS ALL SUBJECTS WHILE OTHER USERS CAN ONLY SEE THE
     * ONW FOR THEM". A subject teacher who teaches two different subjects
     * in the same class arm must not see the other subject's assignment,
     * even though TeacherScope already lets them into that class arm.
     */
    public function test_a_subject_teacher_only_sees_their_own_subjects_assignments_in_a_shared_class(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'asgsix');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 2', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $mySubject = Subject::create(['name' => 'Basic Science', 'code' => 'BSC']);
        $otherSubject = Subject::create(['name' => 'Basic Technology', 'code' => 'BTC']);
        $staff = Staff::create(['staff_id' => 'STF/6', 'first_name' => 'S', 'last_name' => 'T', 'user_id' => $teacher->id]);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $mySubject->id, 'class_arm_id' => $arm->id]);
        Assignment::create(['title' => 'Science homework', 'subject_id' => $mySubject->id, 'class_arm_id' => $arm->id]);
        Assignment::create(['title' => 'Tech homework', 'subject_id' => $otherSubject->id, 'class_arm_id' => $arm->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->get(route('school.assignments.index', ['school' => $school->slug]))
            ->assertOk()->assertSee('Science homework')->assertDontSee('Tech homework');

        // Posting for the subject they don't teach in this class is
        // rejected too, not just hidden from the list.
        $this->actingAs($teacher)->post(route('school.assignments.store', ['school' => $school->slug]), [
            'title' => 'Not mine', 'subject_id' => $otherSubject->id, 'class_arm_id' => $arm->id,
        ])->assertForbidden();
    }

    /** A class teacher, unlike a subject teacher, keeps seeing every subject for their own class. */
    public function test_a_class_teacher_sees_every_subjects_assignment_in_their_class(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'asgseven');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 3', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $subjectA = Subject::create(['name' => 'Maths', 'code' => 'MTH']);
        $subjectB = Subject::create(['name' => 'English', 'code' => 'ENG']);
        $staff = Staff::create(['staff_id' => 'STF/7', 'first_name' => 'C', 'last_name' => 'T', 'user_id' => $teacher->id]);
        $arm->update(['class_teacher_staff_id' => $staff->id]);
        Assignment::create(['title' => 'Maths homework', 'subject_id' => $subjectA->id, 'class_arm_id' => $arm->id]);
        Assignment::create(['title' => 'English homework', 'subject_id' => $subjectB->id, 'class_arm_id' => $arm->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->get(route('school.assignments.index', ['school' => $school->slug]))
            ->assertOk()->assertSee('Maths homework')->assertSee('English homework');
    }

    /** User directive 2026-07-16: "ADMIN CAN SEE ALL ACTIVE ASSIGNMENTS BY SECTION, CLASS, THE REPORTS ETC". */
    public function test_an_admin_can_filter_by_section_and_class_and_sees_a_summary(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'asgeight');

        app(TenantManager::class)->use($school);
        $sectionA = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $sectionB = Section::create(['name' => 'Secondary', 'sequence' => 2]);
        $classA = SchoolClass::create(['section_id' => $sectionA->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $classB = SchoolClass::create(['section_id' => $sectionB->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $armA = ClassArm::create(['school_class_id' => $classA->id]);
        $armB = ClassArm::create(['school_class_id' => $classB->id]);
        $subject = Subject::create(['name' => 'Maths', 'code' => 'MTH']);
        Assignment::create(['title' => 'Primary homework', 'subject_id' => $subject->id, 'class_arm_id' => $armA->id, 'due_on' => now()->addDays(2)]);
        Assignment::create(['title' => 'Secondary homework', 'subject_id' => $subject->id, 'class_arm_id' => $armB->id, 'due_on' => now()->subDays(2)]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get(route('school.assignments.index', ['school' => $school->slug, 'section_id' => $sectionA->id]))
            ->assertOk()->assertSee('Primary homework')->assertDontSee('Secondary homework');

        $this->actingAs($owner)->get(route('school.assignments.index', ['school' => $school->slug]))
            ->assertOk()->assertSee('Active assignments')->assertSee('Overdue')->assertSee('Upcoming');
    }

    /**
     * User report 2026-07-18: a plain "Teacher" role account (distinct
     * from "Subject Teacher"/"Class Teacher") was seeing every subject in
     * the school under Assignments, since only those two specific role
     * slugs were ever scoped here. Proves the generic "teacher" role now
     * gets the exact same subject-only narrowing a subject teacher gets.
     */
    public function test_a_plain_teacher_role_only_sees_their_own_subjects_assignments_in_a_shared_class(): void
    {
        [$school, $teacher] = $this->schoolWithMember('teacher', 'asgten2');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 2', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $mySubject = Subject::create(['name' => 'Basic Science', 'code' => 'BSC']);
        $otherSubject = Subject::create(['name' => 'Basic Technology', 'code' => 'BTC']);
        $staff = Staff::create(['staff_id' => 'STF/10', 'first_name' => 'S', 'last_name' => 'T', 'user_id' => $teacher->id]);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $mySubject->id, 'class_arm_id' => $arm->id]);
        Assignment::create(['title' => 'Science homework', 'subject_id' => $mySubject->id, 'class_arm_id' => $arm->id]);
        Assignment::create(['title' => 'Tech homework', 'subject_id' => $otherSubject->id, 'class_arm_id' => $arm->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->get(route('school.assignments.index', ['school' => $school->slug]))
            ->assertOk()->assertSee('Science homework')->assertDontSee('Tech homework');

        $this->actingAs($teacher)->post(route('school.assignments.store', ['school' => $school->slug]), [
            'title' => 'Not mine', 'subject_id' => $otherSubject->id, 'class_arm_id' => $arm->id,
        ])->assertForbidden();
    }

    /**
     * The same "teacher" role account, but also the class teacher for one
     * of their arms: every subject in THAT arm stays unrestricted, exactly
     * like the "Class Teacher" role already gets, while a different arm
     * they only hold a TeachingAssignment for stays subject-narrowed.
     */
    public function test_a_plain_teacher_who_is_also_a_class_teacher_sees_every_subject_for_their_own_class_only(): void
    {
        [$school, $teacher] = $this->schoolWithMember('teacher', 'asgeleven');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 3', 'sequence' => 1]);
        $ownArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $otherArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);
        $subjectA = Subject::create(['name' => 'Maths', 'code' => 'MTH']);
        $subjectB = Subject::create(['name' => 'English', 'code' => 'ENG']);
        $staff = Staff::create(['staff_id' => 'STF/11', 'first_name' => 'C', 'last_name' => 'T', 'user_id' => $teacher->id]);
        $ownArm->update(['class_teacher_staff_id' => $staff->id]);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $subjectA->id, 'class_arm_id' => $otherArm->id]);
        Assignment::create(['title' => 'Own class Maths', 'subject_id' => $subjectA->id, 'class_arm_id' => $ownArm->id]);
        Assignment::create(['title' => 'Own class English', 'subject_id' => $subjectB->id, 'class_arm_id' => $ownArm->id]);
        Assignment::create(['title' => 'Other class Maths', 'subject_id' => $subjectA->id, 'class_arm_id' => $otherArm->id]);
        Assignment::create(['title' => 'Other class English', 'subject_id' => $subjectB->id, 'class_arm_id' => $otherArm->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->get(route('school.assignments.index', ['school' => $school->slug]))
            ->assertOk()
            ->assertSee('Own class Maths')->assertSee('Own class English') // their own class, every subject
            ->assertSee('Other class Maths') // taught there, allowed
            ->assertDontSee('Other class English'); // not their subject there, not their class
    }

    /**
     * User report 2026-07-18: "a class teacher can see all the details of
     * the assignment for her class, submission, who has and not... if
     * she is assigned a subject, she can create and check submission
     * details". A student can submit their own work, a teacher sees the
     * whole class roster's submission status.
     */
    public function test_a_student_can_submit_and_the_class_teacher_sees_who_has_and_has_not(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'asgsub1');
        $studentUser = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($studentUser->id, ['role_id' => $role->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $subject = Subject::create(['name' => 'Maths', 'code' => 'MTH']);
        $staff = Staff::create(['staff_id' => 'STF/20', 'first_name' => 'C', 'last_name' => 'T', 'user_id' => $teacher->id]);
        $arm->update(['class_teacher_staff_id' => $staff->id]);
        $submitter = Student::create(['admission_number' => 'SB1', 'first_name' => 'Sub', 'last_name' => 'Mitter', 'current_class_arm_id' => $arm->id, 'user_id' => $studentUser->id]);
        $nonSubmitter = Student::create(['admission_number' => 'SB2', 'first_name' => 'No', 'last_name' => 'Sub', 'current_class_arm_id' => $arm->id]);
        $assignment = Assignment::create(['title' => 'Algebra worksheet', 'subject_id' => $subject->id, 'class_arm_id' => $arm->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($studentUser)->post(route('school.assignments.submit', ['school' => $school->slug, 'assignmentId' => $assignment->id]), [
            'content' => 'Here is my completed worksheet.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $submission = \App\Models\Tenant\AssignmentSubmission::where('assignment_id', $assignment->id)->where('student_id', $submitter->id)->first();
        app(TenantManager::class)->forget();
        $this->assertNotNull($submission);
        $this->assertSame('Here is my completed worksheet.', $submission->content);

        $this->actingAs($teacher)->get(route('school.assignments.show', ['school' => $school->slug, 'assignmentId' => $assignment->id]))
            ->assertOk()
            ->assertSeeInOrder(['Sub Mitter', 'Submitted'])
            ->assertSeeInOrder(['No Sub', 'Not submitted']);

        // The student's own view shows their submission, not the whole roster.
        $this->actingAs($studentUser)->get(route('school.assignments.show', ['school' => $school->slug, 'assignmentId' => $assignment->id]))
            ->assertOk()
            ->assertSee('Here is my completed worksheet.')
            ->assertDontSee('No Sub');
    }

    public function test_a_parent_cannot_submit_on_their_childs_behalf(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'asgsub2');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 3', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $subject = Subject::create(['name' => 'English', 'code' => 'ENG']);
        $child = Student::create(['admission_number' => 'CH2', 'first_name' => 'Child', 'last_name' => 'Two', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Parent', 'relationship' => 'Father', 'guardian_user_id' => $parentUser->id, 'is_primary' => true]);
        $assignment = Assignment::create(['title' => 'Spelling list', 'subject_id' => $subject->id, 'class_arm_id' => $arm->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->post(route('school.assignments.submit', ['school' => $school->slug, 'assignmentId' => $assignment->id]), [
            'content' => 'Submitted by parent.',
        ])->assertForbidden();
    }

    /** User directive 2026-07-16: "ASSIGNMENT MENU SHOULD BE MOVED TO ACADEMIC SECTION". */
    public function test_assignments_appears_under_academic_in_the_nav(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        [$school, $owner] = $this->schoolWithMember('school-owner', 'asgnine');
        $school->update(['plan_id' => \App\Models\Plan::where('is_default', true)->first()->id]);

        $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]))
            ->assertOk()
            ->assertSeeInOrder(['Academic', 'Assignments']);
    }
}
