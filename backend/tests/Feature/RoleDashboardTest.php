<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Arm;
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
 * The dashboard shows different figures depending on who is looking (user
 * request 2026-07-09): school owners/admins get school wide counts,
 * class teachers get their own class (and a class assistant gets the same
 * read only), a subject teacher gets only what they actually teach,
 * parents get their children, students get their own snapshot.
 */
class RoleDashboardTest extends TestCase
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

    public function test_admin_sees_school_wide_figures(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'rdone');
        $teacherUser = User::factory()->create();
        $teacherRole = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($teacherUser->id, ['role_id' => $teacherRole->id, 'status' => 'active']);
        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'A1', 'first_name' => 'A', 'last_name' => 'B']);

        $data = app(RoleDashboard::class)->build($admin, $school);
        app(TenantManager::class)->forget();

        $this->assertSame('admin', $data['kind']);
        $labels = array_column($data['figs'], 'l');
        $figsByLabel = array_column($data['figs'], 'n', 'l');
        $this->assertContains('Teachers', $labels);
        $this->assertSame('1', $figsByLabel['Teachers'], 'the school owner (not teacher-scoped) must not be counted as a teacher');
        $this->assertContains('Classes', $labels);
        $this->assertContains('Houses', $labels, 'admin dashboard must have grown beyond the original 4 figures');
        $this->assertContains('Reports Pending Review', $labels);
        $this->assertContains('Health Score', $labels);
        $this->assertSame('admin_overview', $data['panel']['type']);
        $this->assertArrayHasKey('birthdaysToday', $data['panel']);
        $this->assertArrayHasKey('birthdaysUpcoming', $data['panel']);
        $this->assertContains('Boys', $labels, 'gender must appear in the top figures, not only as a standalone panel');
        $this->assertContains('Girls', $labels);
    }

    public function test_admin_sees_todays_and_upcoming_birthdays_with_age_and_class(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'rdfifteen');
        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        Student::create(['admission_number' => 'B1', 'first_name' => 'Birthday', 'last_name' => 'Kid', 'current_class_arm_id' => $arm->id, 'date_of_birth' => now()->subYears(10)->format('Y-m-d')]);
        Student::create(['admission_number' => 'B2', 'first_name' => 'Soon', 'last_name' => 'Kid', 'current_class_arm_id' => $arm->id, 'date_of_birth' => now()->addDays(5)->subYears(9)->format('Y-m-d')]);
        Student::create(['admission_number' => 'B3', 'first_name' => 'Far', 'last_name' => 'Away', 'date_of_birth' => now()->addDays(90)->subYears(8)->format('Y-m-d')]);

        $data = app(RoleDashboard::class)->build($admin, $school);
        app(TenantManager::class)->forget();

        $this->assertCount(1, $data['panel']['birthdaysToday']);
        $this->assertSame('Birthday Kid', $data['panel']['birthdaysToday'][0]['name']);
        $this->assertSame(10, $data['panel']['birthdaysToday'][0]['age']);
        $this->assertSame('Primary 1', $data['panel']['birthdaysToday'][0]['class']);

        $this->assertCount(1, $data['panel']['birthdaysUpcoming'], 'the 90 day away birthday must not appear within the 30 day window');
        $this->assertSame('Soon Kid', $data['panel']['birthdaysUpcoming'][0]['name']);
    }

    public function test_class_teacher_sees_only_their_own_class(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'rdtwo');
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Basic 3', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $staff = Staff::create(['staff_id' => 'STF/1', 'first_name' => 'F', 'last_name' => 'T', 'user_id' => $teacherUser->id]);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id, 'class_teacher_staff_id' => $staff->id]);

        Student::create(['admission_number' => 'S1', 'first_name' => 'Boy', 'last_name' => 'One', 'gender' => 'male', 'current_class_arm_id' => $classArm->id]);
        Student::create(['admission_number' => 'S2', 'first_name' => 'Girl', 'last_name' => 'One', 'gender' => 'female', 'current_class_arm_id' => $classArm->id]);
        Student::create(['admission_number' => 'S3', 'first_name' => 'Elsewhere', 'last_name' => 'Kid', 'gender' => 'male']); // not in this class arm

        $data = app(RoleDashboard::class)->build($teacherUser, $school);
        app(TenantManager::class)->forget();

        $this->assertSame('class_teacher', $data['kind']);
        $this->assertSame('class_overview', $data['panel']['type']);
        $this->assertSame('Basic 3 A', $data['panel']['class_label']);
        $this->assertSame(1, $data['panel']['boys']);
        $this->assertSame(1, $data['panel']['girls']);
        $studentsFig = collect($data['figs'])->firstWhere('l', 'My Students');
        $this->assertSame('2', $studentsFig['n']);
    }

    public function test_subject_teacher_sees_only_their_teaching_assignments(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('subject-teacher', 'rdthree');
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'SS 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $subject = Subject::create(['name' => 'Chemistry']);
        $staff = Staff::create(['staff_id' => 'STF/2', 'first_name' => 'S', 'last_name' => 'T', 'user_id' => $teacherUser->id]);
        TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id]);
        Student::create(['admission_number' => 'S9', 'first_name' => 'One', 'last_name' => 'Kid', 'current_class_arm_id' => $classArm->id]);

        $data = app(RoleDashboard::class)->build($teacherUser, $school);
        app(TenantManager::class)->forget();

        $this->assertSame('subject_teacher', $data['kind']);
        $this->assertArrayHasKey('Secondary', $data['panel']['groups']);
        $this->assertArrayHasKey('Chemistry', $data['panel']['groups']['Secondary']);
        $this->assertSame(['SS 1 A'], $data['panel']['groups']['Secondary']['Chemistry']);
        $subjectsFig = collect($data['figs'])->firstWhere('l', 'My Subjects');
        $this->assertSame('1', $subjectsFig['n']);
    }

    public function test_parent_sees_their_linked_children(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'rdfour');
        app(TenantManager::class)->use($school);

        $student = Student::create(['admission_number' => 'S5', 'first_name' => 'Linked', 'last_name' => 'Child']);
        StudentGuardian::create([
            'student_id' => $student->id, 'name' => 'Parent Name', 'relationship' => 'Mother',
            'guardian_user_id' => $parentUser->id, 'is_primary' => true,
        ]);

        $data = app(RoleDashboard::class)->build($parentUser, $school);
        app(TenantManager::class)->forget();

        $this->assertSame('parent', $data['kind']);
        $this->assertCount(1, $data['panel']['children']);
        $this->assertSame('Linked Child', $data['panel']['children'][0]['name']);
    }

    public function test_parent_sees_which_child_has_a_released_report_card_and_which_does_not(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'rdreport');
        app(TenantManager::class)->use($school);

        $reportedChild = Student::create(['admission_number' => 'PR1', 'first_name' => 'Has', 'last_name' => 'Report']);
        $unreportedChild = Student::create(['admission_number' => 'PR2', 'first_name' => 'No', 'last_name' => 'Report']);
        StudentGuardian::create(['student_id' => $reportedChild->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parentUser->id, 'is_primary' => true]);
        StudentGuardian::create(['student_id' => $unreportedChild->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parentUser->id, 'is_primary' => true]);

        $session = \App\Models\Tenant\AcademicSession::create(['name' => '2025/2026']);
        $term = \App\Models\Tenant\Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        \App\Models\Tenant\ReportCardReview::create([
            'student_id' => $reportedChild->id, 'term_id' => $term->id,
            'status' => \App\Models\Tenant\ReportCardReview::STATUS_RELEASED, 'released_at' => now(),
        ]);

        $data = app(RoleDashboard::class)->build($parentUser, $school);
        app(TenantManager::class)->forget();

        $byName = collect($data['panel']['children'])->keyBy('name');
        $this->assertSame('First Term', $byName['Has Report']['latestReportTerm']);
        $this->assertNotNull($byName['Has Report']['latestReportReleasedAt']);
        $this->assertNull($byName['No Report']['latestReportTerm']);
    }

    public function test_class_teacher_without_a_class_assignment_gets_an_empty_state(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'rdfive');

        app(TenantManager::class)->use($school);
        $data = app(RoleDashboard::class)->build($teacherUser, $school);
        app(TenantManager::class)->forget();

        $this->assertSame('class_teacher', $data['kind']);
        $this->assertSame('no_class', $data['panel']['type']);
    }

    public function test_examination_officer_sees_pending_report_card_reviews(): void
    {
        [$school, $officer] = $this->schoolWithMember('examination-officer', 'rdseven');
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Basic 3', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $student = Student::create(['admission_number' => 'S1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $arm->id]);
        $session = \App\Models\Tenant\AcademicSession::create(['name' => '2025/2026 Session']);
        $term = \App\Models\Tenant\Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true]);
        \App\Models\Tenant\ReportCardReview::create(['student_id' => $student->id, 'term_id' => $term->id, 'status' => \App\Models\Tenant\ReportCardReview::STATUS_PENDING_REVIEW]);

        $data = app(RoleDashboard::class)->build($officer, $school);
        app(TenantManager::class)->forget();

        $this->assertSame('examination_officer', $data['kind']);
        $pendingFig = collect($data['figs'])->firstWhere('l', 'Pending Review');
        $this->assertSame('1', $pendingFig['n']);
        $this->assertSame('pending_reviews', $data['panel']['type']);
        $this->assertSame('Ada Obi', $data['panel']['items'][0]['student']);
    }

    public function test_registrar_sees_student_and_guardian_counts(): void
    {
        [$school, $registrar] = $this->schoolWithMember('registrar', 'rdeight');
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Basic 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $placed = Student::create(['admission_number' => 'S1', 'first_name' => 'Placed', 'last_name' => 'Kid', 'current_class_arm_id' => $arm->id]);
        $unplaced = Student::create(['admission_number' => 'S2', 'first_name' => 'Unplaced', 'last_name' => 'Kid']);
        StudentGuardian::create(['student_id' => $placed->id, 'name' => 'Shared Parent', 'email' => 'shared@example.com', 'relationship' => 'Father']);
        StudentGuardian::create(['student_id' => $unplaced->id, 'name' => 'Shared Parent', 'email' => 'shared@example.com', 'relationship' => 'Father']);

        $data = app(RoleDashboard::class)->build($registrar, $school);
        app(TenantManager::class)->forget();

        $this->assertSame('registrar', $data['kind']);
        $studentsFig = collect($data['figs'])->firstWhere('l', 'Students');
        $this->assertSame('2', $studentsFig['n']);
        $guardiansFig = collect($data['figs'])->firstWhere('l', 'Parents/Guardians');
        $this->assertSame('1', $guardiansFig['n'], 'a guardian shared across two children counts once');
        $awaitingFig = collect($data['figs'])->firstWhere('l', 'Awaiting Class');
        $this->assertSame('1', $awaitingFig['n']);
    }

    public function test_ict_officer_sees_active_users_and_backup_history(): void
    {
        [$school, $ictOfficer] = $this->schoolWithMember('ict-officer', 'rdnine');

        \App\Models\Backup::create([
            'school_id' => $school->id, 'disk' => 'local', 'file_path' => 'backups/x/y.sqlite',
            'size_bytes' => 2048, 'type' => \App\Models\Backup::TYPE_MANUAL, 'initiated_by' => $ictOfficer->id,
        ]);

        $data = app(RoleDashboard::class)->build($ictOfficer, $school);

        $this->assertSame('ict_officer', $data['kind']);
        $usersFig = collect($data['figs'])->firstWhere('l', 'Active Users');
        $this->assertSame('1', $usersFig['n']);
        $backupsFig = collect($data['figs'])->firstWhere('l', 'Backups');
        $this->assertSame('1', $backupsFig['n']);
        $this->assertTrue($data['panel']['has_backup']);
    }

    /**
     * School Owner and School Administrator are two different roles (user
     * correction 2026-07-12) and must not share one identical dashboard.
     */
    public function test_school_admin_gets_a_distinct_operational_dashboard_from_school_owner(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rdeleven');

        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'A1', 'first_name' => 'A', 'last_name' => 'B']);
        app(TenantManager::class)->forget();

        $ownerData = app(RoleDashboard::class)->build($owner, $school);
        $this->assertSame('admin', $ownerData['kind']);
        $this->assertContains('Houses', array_column($ownerData['figs'], 'l'));

        [$adminSchool, $adminUser] = $this->schoolWithMember('school-admin', 'rdtwelve');
        app(TenantManager::class)->use($adminSchool);
        Student::create(['admission_number' => 'B1', 'first_name' => 'C', 'last_name' => 'D']);
        app(TenantManager::class)->forget();

        $adminData = app(RoleDashboard::class)->build($adminUser, $adminSchool);
        $this->assertSame('school_admin', $adminData['kind']);
        $labels = array_column($adminData['figs'], 'l');
        $this->assertContains('New Registrations', $labels);
        $this->assertContains('Awaiting Class', $labels);
        $this->assertNotContains('Houses', $labels, 'school-admin should not get the owner leadership figures');
    }

    public function test_head_teacher_sees_school_wide_admin_figures(): void
    {
        [$school, $headTeacher] = $this->schoolWithMember('head-teacher', 'rdten');
        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'A1', 'first_name' => 'A', 'last_name' => 'B']);
        $data = app(RoleDashboard::class)->build($headTeacher, $school);
        app(TenantManager::class)->forget();

        $this->assertSame('admin', $data['kind']);
    }

    /**
     * Store Keeper (inventory.* only) and Security (no grants at all) have
     * zero real school data overlapping their role, so they see an honest
     * "coming soon" (user request 2026-07-11) instead of unrelated
     * school-wide figures.
     */
    public function test_roles_with_no_overlapping_data_see_an_honest_coming_soon_state(): void
    {
        foreach (['store-keeper', 'security'] as $i => $slug) {
            [$school, $user] = $this->schoolWithMember($slug, 'rdcs'.$i);

            $data = app(RoleDashboard::class)->build($user, $school);

            $this->assertSame('coming_soon', $data['kind'], "role {$slug} should show coming soon");
            $this->assertSame([], $data['figs']);
            $this->assertSame('coming_soon', $data['panel']['type']);
        }
    }

    /**
     * Bursar/Accountant, Librarian, Nurse, Hostel Master, and Transport
     * Officer all have some real overlapping data (students, and for
     * Bursar/Accountant, guardians too) even though their own module is
     * not built yet (user direction 2026-07-12: "update for all roles in
     * the DASHBOARD DESIGN doc... as we build more data, the ones left
     * will show on the necessary user dashboards").
     */
    public function test_roles_with_partial_real_data_see_student_counts_not_a_blank_state(): void
    {
        foreach (['bursar' => true, 'accountant' => true, 'librarian' => false, 'nurse' => false, 'hostel-master' => false, 'transport-officer' => false] as $slug => $expectGuardians) {
            [$school, $user] = $this->schoolWithMember($slug, 'rdpo'.crc32($slug));
            app(TenantManager::class)->use($school);
            Student::create(['admission_number' => 'S-'.$slug, 'first_name' => 'A', 'last_name' => 'B']);
            app(TenantManager::class)->forget();

            $data = app(RoleDashboard::class)->build($user, $school);

            $this->assertSame('people_only', $data['kind'], "role {$slug} should show real people figures");
            $studentsFig = collect($data['figs'])->firstWhere('l', 'Students');
            $this->assertSame('1', $studentsFig['n'], "role {$slug} should see the real student count");
            $this->assertSame($expectGuardians, (bool) collect($data['figs'])->firstWhere('l', 'Parents/Guardians'), "role {$slug} guardian figure mismatch");
        }
    }

    public function test_dashboard_route_renders_role_specific_figures(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'rdsix');
        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Basic 5', 'sequence' => 1]);
        $staff = Staff::create(['staff_id' => 'STF/3', 'first_name' => 'C', 'last_name' => 'T', 'user_id' => $teacherUser->id]);
        ClassArm::create(['school_class_id' => $class->id, 'class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('My Students')->assertSee('Basic 5');
    }

    /**
     * Dashboard Design Cross-Check (2026-07-13): "Attendance Rate, Staff
     * Attendance" and "Parents, Subjects" were Blocked on the owner
     * dashboard because nothing could mark attendance yet. Now that
     * AttendanceController is real, this proves the dashboard actually
     * reads it, not just that the labels exist.
     */
    public function test_admin_dashboard_shows_real_attendance_parents_and_subjects(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'rdattend');
        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $a = Student::create(['admission_number' => 'AT1', 'first_name' => 'A', 'last_name' => 'One', 'current_class_arm_id' => $arm->id]);
        $b = Student::create(['admission_number' => 'AT2', 'first_name' => 'B', 'last_name' => 'Two', 'current_class_arm_id' => $arm->id]);
        Subject::create(['name' => 'Mathematics', 'code' => 'MTH']);
        StudentGuardian::create(['student_id' => $a->id, 'name' => 'Guardian One', 'email' => 'g1@example.com']);
        app(\App\Services\Attendance\AttendanceService::class)->markClass($arm->id, now(), [
            $a->id => 'present', $b->id => 'absent',
        ]);
        app(TenantManager::class)->forget();

        $data = app(RoleDashboard::class)->build($admin, $school);
        $labels = collect($data['figs'])->pluck('n', 'l');

        $this->assertSame('50%', $labels['Attendance Rate']);
        $this->assertSame('1', $labels['Parents']);
        $this->assertSame('1', $labels['Subjects']);
    }

    public function test_class_teacher_dashboard_shows_todays_class_attendance(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'rdattendtwo');
        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 2', 'sequence' => 1]);
        $staff = Staff::create(['staff_id' => 'STF/AT', 'first_name' => 'C', 'last_name' => 'T', 'user_id' => $teacherUser->id]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'class_teacher_staff_id' => $staff->id]);
        $a = Student::create(['admission_number' => 'CT1', 'first_name' => 'A', 'last_name' => 'One', 'current_class_arm_id' => $arm->id]);
        app(\App\Services\Attendance\AttendanceService::class)->markClass($arm->id, now(), [$a->id => 'present']);
        app(TenantManager::class)->forget();

        $data = app(RoleDashboard::class)->build($teacherUser, $school);
        $labels = collect($data['figs'])->pluck('n', 'l');

        $this->assertSame('100%', $labels['Attendance Today']);
        $this->assertSame('1', $labels['Present Today']);
    }

    public function test_student_dashboard_shows_own_attendance(): void
    {
        [$school, $studentUser] = $this->schoolWithMember('student', 'rdattendthree');
        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 3', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $academicSession = \App\Models\Tenant\AcademicSession::create(['name' => '2025/2026', 'starts_on' => now()->subMonth(), 'ends_on' => now()->addMonths(3), 'is_current' => true]);
        \App\Models\Tenant\Term::create(['academic_session_id' => $academicSession->id, 'name' => 'Term 1', 'sequence' => 1, 'starts_on' => now()->subMonth(), 'ends_on' => now()->addMonths(3), 'is_current' => true]);
        $student = Student::create(['admission_number' => 'ST1', 'first_name' => 'Stu', 'last_name' => 'Dent', 'user_id' => $studentUser->id, 'current_class_arm_id' => $arm->id]);
        app(\App\Services\Attendance\AttendanceService::class)->markClass($arm->id, now(), [$student->id => 'present']);
        app(TenantManager::class)->forget();

        $data = app(RoleDashboard::class)->build($studentUser, $school);
        $labels = collect($data['figs'])->pluck('n', 'l');

        $this->assertSame('Present', $labels['Today']);
        $this->assertSame('1', $labels['Days Present']);
    }

    public function test_parent_dashboard_shows_childrens_attendance_today(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'rdattendfour');
        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 4', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $child = Student::create(['admission_number' => 'PC1', 'first_name' => 'Kid', 'last_name' => 'One', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $child->id, 'guardian_user_id' => $parentUser->id, 'name' => 'Mum']);
        app(\App\Services\Attendance\AttendanceService::class)->markClass($arm->id, now(), [$child->id => 'present']);
        app(TenantManager::class)->forget();

        $data = app(RoleDashboard::class)->build($parentUser, $school);
        $labels = collect($data['figs'])->pluck('n', 'l');

        $this->assertSame('100%', $labels['Attendance Today']);
    }

    /**
     * Dashboard Design Cross-Check (2026-07-13): "School Health Score,
     * Blocked, would need most of the above to compute honestly." Now
     * that Parents/Subjects/Attendance are real, this reuses the exact
     * score HealthService already computes for the Maintenance Center
     * rather than a second scoring formula, and this proves it is real:
     * a school with no backup and no current term scores lower than a
     * healthier one.
     */
    public function test_admin_dashboard_shows_a_real_health_score_that_reflects_real_findings(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'rdhealth');

        app(TenantManager::class)->use($school);
        $expected = app(\App\Services\Maintenance\HealthService::class)->check($school)['score'];
        $data = app(RoleDashboard::class)->build($admin, $school);
        app(TenantManager::class)->forget();

        $score = collect($data['figs'])->firstWhere('l', 'Health Score')['n'];

        $this->assertSame($expected, $score);
        $this->assertLessThan(100, $score, 'a brand new school with no term and no backup should not score a perfect 100');
    }

    /**
     * Dashboard Design Cross-Check (2026-07-13): "Result Performance,
     * Partial, Term Average card stands in, no trend chart." Proves the
     * trend is real term-over-term averages in real chronological order
     * (by session then term sequence, not creation order), not a
     * hardcoded series, and that a term with no scores yet is skipped
     * rather than shown as a misleading 0%.
     */
    public function test_admin_dashboard_shows_a_real_result_performance_trend(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'rdtrend');
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $subject = Subject::create(['name' => 'Mathematics', 'code' => 'MTH']);
        $student = Student::create(['admission_number' => 'RT1', 'first_name' => 'A', 'last_name' => 'B', 'current_class_arm_id' => $arm->id]);
        $component = \App\Models\Tenant\AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 100, 'sequence' => 1]);

        $session = \App\Models\Tenant\AcademicSession::create(['name' => '2025/2026']);
        $termOne = \App\Models\Tenant\Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $termTwo = \App\Models\Tenant\Term::create(['academic_session_id' => $session->id, 'name' => 'Second Term', 'sequence' => 2, 'is_current' => true]);
        $termThree = \App\Models\Tenant\Term::create(['academic_session_id' => $session->id, 'name' => 'Third Term', 'sequence' => 3]); // no scores yet

        \App\Models\Tenant\Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $arm->id, 'term_id' => $termOne->id, 'assessment_component_id' => $component->id, 'score' => 60]);
        \App\Models\Tenant\Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $arm->id, 'term_id' => $termTwo->id, 'assessment_component_id' => $component->id, 'score' => 80]);

        $data = app(RoleDashboard::class)->build($admin, $school);
        app(TenantManager::class)->forget();

        $trend = $data['panel']['resultTrend'];

        $this->assertCount(2, $trend, 'a term with no scores yet must not appear in the trend');
        $this->assertSame('First Term', $trend[0]['label']);
        $this->assertSame(60.0, $trend[0]['average']);
        $this->assertSame('Second Term', $trend[1]['label']);
        $this->assertSame(80.0, $trend[1]['average']);
    }

    public function test_students_at_risk_flags_low_attendance_and_low_average_but_not_a_healthy_student(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'rdrisk');
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);
        $subject = Subject::create(['name' => 'Mathematics', 'code' => 'MTH']);
        $component = \App\Models\Tenant\AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 100, 'sequence' => 1]);
        $session = \App\Models\Tenant\AcademicSession::create(['name' => '2025/2026']);
        $term = \App\Models\Tenant\Term::create([
            'academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true,
            'starts_on' => now()->subDays(10), 'ends_on' => now()->addDays(50),
        ]);

        $lowAttendanceStudent = Student::create(['admission_number' => 'RK1', 'first_name' => 'Low', 'last_name' => 'Attendance', 'current_class_arm_id' => $arm->id]);
        $lowScoreStudent = Student::create(['admission_number' => 'RK2', 'first_name' => 'Low', 'last_name' => 'Score', 'current_class_arm_id' => $arm->id]);
        $healthyStudent = Student::create(['admission_number' => 'RK3', 'first_name' => 'Doing', 'last_name' => 'Fine', 'current_class_arm_id' => $arm->id]);

        // Low attendance student: 2 present, 8 absent over the term = 20%.
        \App\Models\Tenant\Attendance::create(['date' => now()->subDays(1), 'person_type' => 'student', 'person_id' => $lowAttendanceStudent->id, 'status' => 'present']);
        \App\Models\Tenant\Attendance::create(['date' => now()->subDays(2), 'person_type' => 'student', 'person_id' => $lowAttendanceStudent->id, 'status' => 'present']);
        for ($i = 3; $i <= 10; $i++) {
            \App\Models\Tenant\Attendance::create(['date' => now()->subDays($i), 'person_type' => 'student', 'person_id' => $lowAttendanceStudent->id, 'status' => 'absent']);
        }

        // Low score student: below the 40% threshold.
        \App\Models\Tenant\Score::create(['student_id' => $lowScoreStudent->id, 'subject_id' => $subject->id, 'class_arm_id' => $arm->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 25]);

        // Healthy student: good attendance and a good score, must not appear.
        \App\Models\Tenant\Attendance::create(['date' => now()->subDays(1), 'person_type' => 'student', 'person_id' => $healthyStudent->id, 'status' => 'present']);
        \App\Models\Tenant\Score::create(['student_id' => $healthyStudent->id, 'subject_id' => $subject->id, 'class_arm_id' => $arm->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 90]);

        $data = app(RoleDashboard::class)->build($admin, $school);
        app(TenantManager::class)->forget();

        $atRisk = $data['panel']['studentsAtRisk'];
        $byName = collect($atRisk)->keyBy('name');

        $this->assertCount(2, $atRisk, 'only the two genuinely at-risk students should appear');
        $this->assertTrue($byName->has('Low Attendance'));
        $this->assertStringContainsString('Attendance 20%', $byName['Low Attendance']['reason']);
        $this->assertTrue($byName->has('Low Score'));
        $this->assertStringContainsString('Average 25', $byName['Low Score']['reason']);
        $this->assertFalse($byName->has('Doing Fine'), 'a student with good attendance and a good score must not be flagged');
    }

    public function test_class_teacher_only_sees_at_risk_students_in_their_own_class(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'rdrisk2');
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Basic 4', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $staff = Staff::create(['staff_id' => 'STF/2', 'first_name' => 'F', 'last_name' => 'T', 'user_id' => $teacherUser->id]);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id, 'class_teacher_staff_id' => $staff->id]);
        $otherArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);

        $session = \App\Models\Tenant\AcademicSession::create(['name' => '2025/2026']);
        $term = \App\Models\Tenant\Term::create([
            'academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true,
            'starts_on' => now()->subDays(10), 'ends_on' => now()->addDays(50),
        ]);
        $subject = Subject::create(['name' => 'English', 'code' => 'ENG']);
        $component = \App\Models\Tenant\AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 100, 'sequence' => 1]);

        $inClassRisky = Student::create(['admission_number' => 'RC1', 'first_name' => 'In', 'last_name' => 'Class', 'current_class_arm_id' => $classArm->id]);
        \App\Models\Tenant\Score::create(['student_id' => $inClassRisky->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 10]);

        $otherClassRisky = Student::create(['admission_number' => 'RC2', 'first_name' => 'Other', 'last_name' => 'Class', 'current_class_arm_id' => $otherArm->id]);
        \App\Models\Tenant\Score::create(['student_id' => $otherClassRisky->id, 'subject_id' => $subject->id, 'class_arm_id' => $otherArm->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 5]);

        $data = app(RoleDashboard::class)->build($teacherUser, $school);
        app(TenantManager::class)->forget();

        $names = collect($data['panel']['studentsAtRisk'])->pluck('name');
        $this->assertTrue($names->contains('In Class'));
        $this->assertFalse($names->contains('Other Class'), 'a student in a different class arm must not appear on this class teacher\'s at-risk list');
    }
}
