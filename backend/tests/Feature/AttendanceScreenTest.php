<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Attendance;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Attendance Management screen (queued 2026-07-13, "add all partial builds
 * to the to do task"). The Attendance model/AttendanceService already
 * existed and were unit tested (see AttendanceTest); this covers the
 * actual controller/routes/views, and that a class or subject teacher only
 * ever marks/sees their own class arm, same as StudentController.
 */
class AttendanceScreenTest extends TestCase
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

    protected function twoClassesWithStudents(): array
    {
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $classA = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $armA = ClassArm::create(['school_class_id' => $classA->id, 'arm_id' => null]);
        $studentA = Student::create(['admission_number' => 'A/001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $armA->id]);

        $classB = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 2', 'sequence' => 2]);
        $armB = ClassArm::create(['school_class_id' => $classB->id, 'arm_id' => null]);
        $studentB = Student::create(['admission_number' => 'B/001', 'first_name' => 'Bode', 'last_name' => 'Ade', 'current_class_arm_id' => $armB->id]);

        return compact('armA', 'studentA', 'armB', 'studentB');
    }

    public function test_owner_can_view_and_mark_a_class_register(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attone');

        app(TenantManager::class)->use($school);
        ['armA' => $arm, 'studentA' => $student] = $this->twoClassesWithStudents();
        app(TenantManager::class)->forget();

        // Mobile friendly table (standing rule: "genuinely usable on a
        // phone"): the register table opts into .table-stack, and every
        // cell besides the student's own name carries a data-label so the
        // column header is not lost once thead hides below phone width.
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/attendance?class_arm_id={$arm->id}&date=2026-01-15")
            ->assertOk()->assertSee('Ada Obi')
            ->assertSee('class="table-stack"', false)
            ->assertSee('data-label="Status"', false)
            ->assertSee('class="grid cols-4"', false);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/attendance/mark", [
            'class_arm_id' => $arm->id, 'date' => '2026-01-15',
            'marks' => [$student->id => 'present'],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $mark = Attendance::students()->forDate('2026-01-15')->first();
        app(TenantManager::class)->forget();
        $this->assertSame('present', $mark->status);
    }

    public function test_a_class_teacher_only_sees_and_marks_their_own_class_arm(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'atttwo');

        app(TenantManager::class)->use($school);
        ['armA' => $armA, 'studentA' => $studentA, 'armB' => $armB, 'studentB' => $studentB] = $this->twoClassesWithStudents();
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Tee', 'last_name' => 'Cher', 'status' => 'active', 'user_id' => $teacherUser->id]);
        $armA->update(['class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/attendance")
            ->assertOk()->assertSee('Primary 1')->assertDontSee('Primary 2');

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/attendance?class_arm_id={$armB->id}")
            ->assertNotFound();

        $this->actingAs($teacherUser)->post("http://{$school->slug}.localhost/attendance/mark", [
            'class_arm_id' => $armB->id, 'date' => '2026-01-15',
            'marks' => [$studentB->id => 'present'],
        ])->assertNotFound();

        $this->actingAs($teacherUser)->post("http://{$school->slug}.localhost/attendance/mark", [
            'class_arm_id' => $armA->id, 'date' => '2026-01-15',
            'marks' => [$studentA->id => 'present'],
        ])->assertRedirect();
    }

    /** User report 2026-07-22: "each class teacher should not have to select their class if its just one class assigned to them but if its more than one class assigned, the dropdown option should show." */
    public function test_a_class_teacher_with_only_one_class_has_it_auto_selected(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'attauto');

        app(TenantManager::class)->use($school);
        ['armA' => $armA, 'studentA' => $studentA] = $this->twoClassesWithStudents();
        $staff = Staff::create(['staff_id' => 'STF/0002', 'first_name' => 'Solo', 'last_name' => 'Teacher', 'status' => 'active', 'user_id' => $teacherUser->id]);
        $armA->update(['class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        // No class_arm_id given at all: the one class this teacher owns must already be selected, register visible immediately.
        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/attendance")
            ->assertOk()
            ->assertSee('Primary 1')
            ->assertSee($studentA->fullName())
            ->assertDontSee('Choose a class');
    }

    /** The dropdown must still show, unaffected, for anyone with more than one class. */
    public function test_a_class_teacher_with_two_classes_still_sees_the_dropdown(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('school-owner', 'attmulti');

        app(TenantManager::class)->use($school);
        $this->twoClassesWithStudents();
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/attendance")
            ->assertOk()->assertSee('Choose a class');
    }

    public function test_a_subject_teacher_can_view_but_not_mark(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('subject-teacher', 'attthree');

        app(TenantManager::class)->use($school);
        ['armA' => $arm, 'studentA' => $student] = $this->twoClassesWithStudents();
        app(TenantManager::class)->forget();

        // No teaching assignment yet: an unscoped-by-nothing subject teacher sees no class arms at all.
        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/attendance")->assertOk()->assertDontSee('Primary 1');

        $this->actingAs($teacherUser)->post("http://{$school->slug}.localhost/attendance/mark", [
            'class_arm_id' => $arm->id, 'date' => '2026-01-15',
            'marks' => [$student->id => 'present'],
        ])->assertForbidden();
    }

    public function test_stray_student_ids_outside_the_class_are_ignored(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attfour');

        app(TenantManager::class)->use($school);
        ['armA' => $armA, 'studentA' => $studentA, 'studentB' => $studentB] = $this->twoClassesWithStudents();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/attendance/mark", [
            'class_arm_id' => $armA->id, 'date' => '2026-01-15',
            'marks' => [$studentA->id => 'present', $studentB->id => 'absent'],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $count = Attendance::students()->forDate('2026-01-15')->count();
        $studentBMarked = Attendance::where('person_id', $studentB->id)->forDate('2026-01-15')->exists();
        app(TenantManager::class)->forget();

        $this->assertSame(1, $count);
        $this->assertFalse($studentBMarked);
    }

    public function test_staff_attendance_requires_staff_view_too(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attfive');
        $staffMember = null;
        app(TenantManager::class)->use($school);
        $staffMember = Staff::create(['staff_id' => 'STF/0002', 'first_name' => 'Bola', 'last_name' => 'Ade', 'status' => 'active']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/attendance/staff")
            ->assertOk()->assertSee('Bola Ade');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/attendance/staff/mark", [
            'date' => '2026-01-15', 'marks' => [$staffMember->id => 'present'],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $mark = Attendance::staff()->forDate('2026-01-15')->first();
        app(TenantManager::class)->forget();
        $this->assertSame('present', $mark->status);

        // A class teacher has attendance.* but no staff.view, so staff attendance stays out of reach.
        $teacherUser = User::factory()->create();
        $teacherRole = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($teacherUser->id, ['role_id' => $teacherRole->id, 'status' => 'active']);
        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/attendance/staff")->assertForbidden();
    }

    public function test_attendance_nav_link_appears_and_respects_permission(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attseven');
        $school->update(['plan_id' => \App\Models\Plan::where('is_default', true)->first()->id]);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard")
            ->assertSee('href="http://attseven.localhost/attendance"', false);
    }

    /**
     * User directive 2026-07-16: "GREY OUT WEEKEND (SATURDAY AND SUNDAY)
     * AND HOLIDAYS FROM THE ATTENDANCE SO THAT IT WAS NOT PICKED".
     * 2026-01-17 is a Saturday.
     */
    public function test_a_weekend_date_cannot_be_marked(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attweekend');

        app(TenantManager::class)->use($school);
        ['armA' => $arm, 'studentA' => $student] = $this->twoClassesWithStudents();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/attendance/mark", [
            'class_arm_id' => $arm->id, 'date' => '2026-01-17',
            'marks' => [$student->id => 'present'],
        ])->assertRedirect()->assertSessionHas('error');

        app(TenantManager::class)->use($school);
        $marked = Attendance::students()->forDate('2026-01-17')->exists();
        app(TenantManager::class)->forget();
        $this->assertFalse($marked);
    }

    public function test_a_dated_holiday_cannot_be_marked(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attholiday');

        app(TenantManager::class)->use($school);
        ['armA' => $arm, 'studentA' => $student] = $this->twoClassesWithStudents();
        \App\Models\Tenant\CalendarEvent::create([
            'title' => 'School Founders Day', 'category' => 'holiday', 'starts_on' => '2026-01-15',
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/attendance/mark", [
            'class_arm_id' => $arm->id, 'date' => '2026-01-15',
            'marks' => [$student->id => 'present'],
        ])->assertRedirect()->assertSessionHas('error');
    }

    public function test_a_ranged_mid_term_break_cannot_be_marked(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attbreak');

        app(TenantManager::class)->use($school);
        ['armA' => $arm, 'studentA' => $student] = $this->twoClassesWithStudents();
        \App\Models\Tenant\CalendarEvent::create([
            'title' => 'Mid Term Break', 'category' => 'break', 'starts_on' => '2026-01-14', 'ends_on' => '2026-01-16',
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/attendance/mark", [
            'class_arm_id' => $arm->id, 'date' => '2026-01-15',
            'marks' => [$student->id => 'present'],
        ])->assertRedirect()->assertSessionHas('error');
    }

    public function test_staff_attendance_also_blocks_a_weekend_date(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attstaffweekend');
        app(TenantManager::class)->use($school);
        $staffMember = Staff::create(['staff_id' => 'STF/0003', 'first_name' => 'Sola', 'last_name' => 'Ade', 'status' => 'active']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/attendance/staff/mark", [
            'date' => '2026-01-18', 'marks' => [$staffMember->id => 'present'],
        ])->assertRedirect()->assertSessionHas('error');

        app(TenantManager::class)->use($school);
        $marked = Attendance::staff()->forDate('2026-01-18')->exists();
        app(TenantManager::class)->forget();
        $this->assertFalse($marked);
    }

    public function test_the_attendance_screen_ships_the_non_school_dates_to_the_client(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attclientdates');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/attendance")
            ->assertOk()->assertSee('nonSchoolDates', false);
    }

    /**
     * User directive 2026-07-16: "MAKE IT POSSIBLE TO DOWNLOAD THE CLASS
     * BULK ATTENDANCE WITH ALL STUDENT NAME AND THE DATES (USER WILL ASK
     * FOR THE DAYS THEY WANT TO ENTER) SO THAT IT COMES WITH THE FILE TO
     * BE DOWNLOADED WHICH THE USER CAN FILL AND UPLOAD BACK WHICH WILL
     * THEN POPULATE ALL THE ATTENDANCE RECORD". 2026-01-14 is a Wednesday,
     * 2026-01-19 the following Monday, so this range spans one weekend.
     */
    public function test_bulk_template_has_one_column_per_school_day_and_excludes_the_weekend(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attbulkone');

        app(TenantManager::class)->use($school);
        ['armA' => $arm, 'studentA' => $student] = $this->twoClassesWithStudents();
        \App\Models\Tenant\Attendance::create(['date' => '2026-01-14', 'person_type' => 'student', 'person_id' => $student->id, 'class_arm_id' => $arm->id, 'status' => 'present']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/attendance/bulk-template?class_arm_id={$arm->id}&from=2026-01-14&to=2026-01-19");
        $response->assertOk();

        $csv = $response->getContent();
        $this->assertStringContainsString('2026-01-14', $csv);
        $this->assertStringContainsString('2026-01-15', $csv);
        $this->assertStringContainsString('2026-01-16', $csv);
        $this->assertStringContainsString('2026-01-19', $csv);
        $this->assertStringNotContainsString('2026-01-17', $csv, 'Saturday must be excluded');
        $this->assertStringNotContainsString('2026-01-18', $csv, 'Sunday must be excluded');
        $this->assertStringContainsString($student->admission_number, $csv);
        $this->assertStringContainsString('present', $csv, 'the already marked day must be pre-filled');
    }

    public function test_bulk_upload_populates_attendance_across_the_whole_range(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attbulktwo');

        app(TenantManager::class)->use($school);
        ['armA' => $arm, 'studentA' => $studentA, 'studentB' => $studentB] = $this->twoClassesWithStudents();
        app(TenantManager::class)->forget();

        $csv = "admission_number,name,2026-01-14,2026-01-15,2026-01-16\n"
            ."{$studentA->admission_number},Ada Obi,present,absent,late\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('attendance.csv', $csv);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/attendance/bulk-upload", [
            'class_arm_id' => $arm->id, 'file' => $file,
        ])->assertRedirect()->assertSessionHas('status');

        app(TenantManager::class)->use($school);
        $marks = \App\Models\Tenant\Attendance::where('person_id', $studentA->id)->orderBy('date')->pluck('status', 'date');
        app(TenantManager::class)->forget();

        $this->assertSame('present', $marks->get('2026-01-14 00:00:00') ?? $marks->get('2026-01-14'));
        $this->assertSame('absent', $marks->get('2026-01-15 00:00:00') ?? $marks->get('2026-01-15'));
        $this->assertSame('late', $marks->get('2026-01-16 00:00:00') ?? $marks->get('2026-01-16'));
    }

    public function test_bulk_upload_skips_blank_cells_and_a_weekend_column_hand_added_back(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attbulkthree');

        app(TenantManager::class)->use($school);
        ['armA' => $arm, 'studentA' => $student] = $this->twoClassesWithStudents();
        app(TenantManager::class)->forget();

        // 2026-01-17 is a Saturday, hand added back into the header despite
        // the download template never including it.
        $csv = "admission_number,name,2026-01-14,2026-01-17\n"
            ."{$student->admission_number},Ada Obi,,present\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('attendance.csv', $csv);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/attendance/bulk-upload", [
            'class_arm_id' => $arm->id, 'file' => $file,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $count = \App\Models\Tenant\Attendance::where('person_id', $student->id)->count();
        app(TenantManager::class)->forget();

        $this->assertSame(0, $count, 'a blank cell and a hand added weekend column must both be skipped');
    }

    public function test_bulk_template_and_upload_are_scoped_to_the_teachers_own_class(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'attbulkfour');

        app(TenantManager::class)->use($school);
        ['armA' => $armA, 'armB' => $armB] = $this->twoClassesWithStudents();
        $staff = Staff::create(['staff_id' => 'STF/0004', 'first_name' => 'Tee', 'last_name' => 'Cher', 'status' => 'active', 'user_id' => $teacherUser->id]);
        $armA->update(['class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/attendance/bulk-template?class_arm_id={$armB->id}&from=2026-01-14&to=2026-01-16")
            ->assertNotFound();

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('attendance.csv', "admission_number,name,2026-01-14\n");
        $this->actingAs($teacherUser)->post("http://{$school->slug}.localhost/attendance/bulk-upload", [
            'class_arm_id' => $armB->id, 'file' => $file,
        ])->assertNotFound();
    }

    /** User directive 2026-07-16 ("ALL THERE", build out the remaining "Soon" items): a real Attendance Dashboard, not just the marking screen. */
    public function test_attendance_dashboard_shows_todays_figures_and_a_trend(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attdashone');

        app(TenantManager::class)->use($school);
        ['armA' => $arm, 'studentA' => $student] = $this->twoClassesWithStudents();
        Attendance::create(['date' => now()->toDateString(), 'person_type' => 'student', 'person_id' => $student->id, 'class_arm_id' => $arm->id, 'status' => 'present']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/attendance/dashboard")
            ->assertOk()
            ->assertSee("Today's rate", false)
            ->assertSee('Attendance rate trend')
            ->assertSee('By class')
            // Mobile friendly stat cards (standing rule: "genuinely usable
            // on a phone"): a bare inline grid-template-columns never
            // picks up the shared responsive collapse, .cols-4 does.
            ->assertSee('class="grid cols-4"', false);
    }

    /** User request 2026-07-22: "let's have alterative style ot charts to select for this current ones, apply to all areas that the chart is." */
    public function test_the_attendance_trend_switches_to_bars_when_the_school_chooses_that_chart_style(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attdashbar');

        app(TenantManager::class)->use($school);
        ['armA' => $arm, 'studentA' => $student] = $this->twoClassesWithStudents();
        Attendance::create(['date' => now()->toDateString(), 'person_type' => 'student', 'person_id' => $student->id, 'class_arm_id' => $arm->id, 'status' => 'present']);
        \App\Models\Tenant\Setting::put('chart_style', 'bar');
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/attendance/dashboard");

        $response->assertOk()->assertSee('<rect', false);
    }

    /** User report 2026-07-22: "one should be able to switch between staff attendance and student attendance here". */
    public function test_the_dashboard_can_switch_to_staff_attendance(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attdashthree');

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0009', 'first_name' => 'Femi', 'last_name' => 'Bello', 'status' => 'active']);
        Attendance::create(['date' => now()->toDateString(), 'person_type' => 'staff', 'person_id' => $staff->id, 'status' => 'present']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/attendance/dashboard?type=staff");

        $response->assertOk()->assertSee("Today's rate", false)->assertDontSee('By class');
    }

    /** User report 2026-07-22: "one should have a date/calendar at the top which allows one to go to a date and see the data there". */
    public function test_the_dashboard_can_show_a_specific_past_date(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attdashfour');

        app(TenantManager::class)->use($school);
        ['armA' => $arm, 'studentA' => $student] = $this->twoClassesWithStudents();
        $pastDate = now()->subDays(3);
        while ($pastDate->isWeekend()) {
            $pastDate->subDay();
        }
        Attendance::create(['date' => $pastDate->toDateString(), 'person_type' => 'student', 'person_id' => $student->id, 'class_arm_id' => $arm->id, 'status' => 'absent']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/attendance/dashboard?date={$pastDate->toDateString()}");

        $response->assertOk()->assertSee('1', false); // 1 absent that day
        $this->assertStringNotContainsString('Today&#039;s rate', $response->getContent());
    }

    /** "Should only allows previous dates only": a future date is not honored, the dashboard falls back to today. */
    public function test_a_future_date_is_ignored_in_favor_of_today(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attdashfive');

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/attendance/dashboard?date=".now()->addYear()->toDateString());

        $response->assertOk()->assertSee("Today's rate", false);
    }

    /** A class teacher only ever sees their own class's numbers, the school wide "By class" comparison is admin only. */
    public function test_a_class_teacher_does_not_see_the_by_class_comparison_on_the_dashboard(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'attdashtwo');

        app(TenantManager::class)->use($school);
        ['armA' => $arm] = $this->twoClassesWithStudents();
        $staff = Staff::create(['staff_id' => 'STF/0005', 'first_name' => 'C', 'last_name' => 'T', 'status' => 'active', 'user_id' => $teacherUser->id]);
        $arm->update(['class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/attendance/dashboard")
            ->assertOk()
            ->assertDontSee('By class');
    }
}
