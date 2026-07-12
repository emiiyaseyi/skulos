<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A class or subject teacher's sidebar should only show what they can
 * actually use (user directive 2026-07-12, live QA: "should not be
 * seeing subjects and classes, houses, session and terms, assessment
 * structure, calendar, all admin features, communication, finance and
 * settings"). Every other role's nav is untouched by this, only
 * config('skulos.teacher_scoped_roles') is affected.
 */
class TeacherNavTest extends TestCase
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

    public function test_a_class_teachers_nav_hides_admin_only_items(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'navone');

        $response = $this->actingAs($teacher)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk()
            ->assertSee('Students') // students.view is genuinely held
            ->assertDontSee('Staff') // no staff.view
            ->assertDontSee('Houses')
            ->assertDontSee('Classes &amp; Subjects', false)
            ->assertDontSee('Sessions &amp; Terms', false)
            ->assertDontSee('Assessment Structure')
            ->assertDontSee('Grading')
            ->assertDontSee('Campuses')
            ->assertDontSee('Branding')
            ->assertDontSee('Backups')
            ->assertDontSee('Mail Settings')
            ->assertDontSee('Guided Setup')
            ->assertDontSee('Report settings')
            ->assertDontSee('Pending review');
    }

    public function test_a_subject_teachers_nav_hides_admin_only_items_too(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'navtwo');

        $response = $this->actingAs($teacher)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk()
            ->assertSee('Students')
            ->assertDontSee('Staff')
            ->assertDontSee('Houses')
            ->assertDontSee('Classes &amp; Subjects', false)
            ->assertDontSee('Backups');
    }

    /** A school owner's nav is completely unaffected by the new scoping, still sees everything their plan includes. */
    public function test_a_school_owners_nav_is_unaffected(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'navthree');

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk()
            ->assertSee('Students')
            ->assertSee('Staff')
            ->assertSee('Houses')
            ->assertSee('Guided Setup')
            ->assertSee('Backups');
    }

    /** Staff Extra Access granting the "students" module lifts the scope, matching TeacherScope's own override rule, the Students link itself was already visible either way, this confirms the grant path does not error. */
    public function test_a_class_teacher_with_a_students_module_override_still_renders_the_nav_cleanly(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'navfour');
        $this->rbac->grantModuleOverride($school, $teacher, 'students');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('Students');
    }

    /** Quick Actions on a teacher's own dashboard panel link to real, permission-appropriate screens only (user directive 2026-07-12: dashboards should answer "what must I complete before I leave"). */
    public function test_a_class_teachers_dashboard_shows_real_quick_actions(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'navfive');

        app(TenantManager::class)->use($school);
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        $staff = \App\Models\Tenant\Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'F', 'last_name' => 'T', 'position' => 'Class Teacher', 'status' => 'active', 'user_id' => $teacher->id]);
        $arm->update(['class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Enter CA &amp; exam scores', false)
            ->assertSee('Report cards')
            ->assertSee('My students');
    }

    public function test_a_subject_teachers_dashboard_shows_real_quick_actions(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'navsix');

        app(TenantManager::class)->use($school);
        $section = \App\Models\Tenant\Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'SS 1', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
        $subject = \App\Models\Tenant\Subject::create(['name' => 'Chemistry']);
        $staff = \App\Models\Tenant\Staff::create(['staff_id' => 'STF/0002', 'first_name' => 'S', 'last_name' => 'T', 'position' => 'Subject Teacher', 'status' => 'active', 'user_id' => $teacher->id]);
        \App\Models\Tenant\TeachingAssignment::create(['staff_id' => $staff->id, 'subject_id' => $subject->id, 'class_arm_id' => $arm->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Enter CA &amp; exam scores', false)
            ->assertSee('My students')
            ->assertDontSee('Report cards'); // subject-teacher holds no report_cards permission at all
    }
}
