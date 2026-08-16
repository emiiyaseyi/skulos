<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\ReportCardReview;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
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
 * Deliberate attempts to break access control, written as the two
 * attacks that matter most for a live multi-tenant platform holding
 * children's records (2026-08-15, after go-live):
 *
 *  1. Cross-tenant: a real, signed-in user at school A editing the URL
 *     to school B's subdomain.
 *  2. IDOR: a parent swapping the student id in a URL for another
 *     family's child.
 *
 * These are written as permanent tests rather than a one-off manual
 * check specifically so a future change cannot silently reopen either
 * hole without a red test.
 */
class AccessControlAttackTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config([
            'tenancy.sqlite_path' => $this->tenantDir,
            'tenancy.driver' => 'sqlite',
            'app.domain' => 'localhost',
        ]);
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function makeSchool(string $slug): School
    {
        return app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
    }

    protected function attach(School $school, User $user, string $roleSlug): void
    {
        $role = app(Rbac::class)->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);
    }

    /** Minimal real academic scaffolding so a student can exist and be reported on. */
    protected function seedStudent(School $school, string $name, string $admissionNo): Student
    {
        return app(TenantManager::class)->connectTo($school, function () use ($name, $admissionNo) {
            $session = AcademicSession::create(['name' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-07-31', 'is_current' => true]);
            Term::firstOrCreate(
                ['academic_session_id' => $session->id, 'sequence' => 1],
                ['name' => 'First Term', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-15', 'is_current' => true]
            );
            $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
            $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
            $arm = ClassArm::create(['school_class_id' => $class->id, 'name' => 'A']);

            return Student::create([
                'first_name' => $name,
                'last_name' => 'Test',
                'admission_number' => $admissionNo,
                'gender' => 'Female',
                'current_class_arm_id' => $arm->id,
                'status' => Student::STATUS_ACTIVE,
            ]);
        });
    }

    public function test_a_signed_in_user_at_one_school_cannot_reach_another_schools_pages(): void
    {
        $schoolA = $this->makeSchool('attack-a');
        $schoolB = $this->makeSchool('attack-b');

        $staffAtA = User::factory()->create();
        $this->attach($schoolA, $staffAtA, 'school-owner');

        // Signed in perfectly legitimately at their OWN school...
        $this->actingAs($staffAtA)
            ->get("http://{$schoolA->slug}.localhost/dashboard")
            ->assertOk();

        // ...then simply edits the hostname to the other school.
        foreach (['dashboard', 'students', 'staff', 'audit-trail'] as $path) {
            $response = $this->actingAs($staffAtA)->get("http://{$schoolB->slug}.localhost/{$path}");

            $this->assertContains(
                $response->getStatusCode(),
                [403, 404, 302],
                "Cross-tenant access to /{$path} at another school returned {$response->getStatusCode()}; it must never be 200."
            );
        }
    }

    public function test_a_parent_cannot_open_another_familys_child_by_changing_the_id(): void
    {
        $school = $this->makeSchool('attack-idor');

        $ownChild = $this->seedStudent($school, 'Own', 'IDOR/0001');
        $otherChild = app(TenantManager::class)->connectTo($school, fn () => Student::create([
            'first_name' => 'Other',
            'last_name' => 'Family',
            'admission_number' => 'IDOR/0002',
            'gender' => 'Male',
            'current_class_arm_id' => $ownChild->current_class_arm_id,
            'status' => Student::STATUS_ACTIVE,
        ]));

        $parent = User::factory()->create();
        $this->attach($school, $parent, 'parent');
        app(TenantManager::class)->connectTo($school, fn () => StudentGuardian::create([
            'student_id' => $ownChild->id,
            'name' => 'Own Parent',
            'guardian_user_id' => $parent->id,
            'relationship' => 'Mother',
            'is_primary' => true,
        ]));

        $term = app(TenantManager::class)->connectTo($school, fn () => Term::first());

        // Both children's report cards released, so the ONLY thing
        // standing between this parent and another family's child is
        // the ownership check itself, not a release-status accident.
        app(TenantManager::class)->connectTo($school, function () use ($ownChild, $otherChild, $term) {
            foreach ([$ownChild, $otherChild] as $s) {
                ReportCardReview::create([
                    'student_id' => $s->id,
                    'term_id' => $term->id,
                    'report_type' => ReportCardReview::REPORT_TYPE_FULL_TERM,
                    'status' => ReportCardReview::STATUS_RELEASED,
                    'review_stage' => 0,
                ]);
            }
        });

        $host = "http://{$school->slug}.localhost";

        $this->actingAs($parent)->get("{$host}/report-cards/{$ownChild->id}/{$term->id}")->assertOk();

        $response = $this->actingAs($parent)->get("{$host}/report-cards/{$otherChild->id}/{$term->id}");
        $this->assertContains(
            $response->getStatusCode(),
            [403, 404],
            "A parent loaded another family's child report card (status {$response->getStatusCode()})."
        );

        // The student's own profile page is the other obvious id swap.
        $profile = $this->actingAs($parent)->get("{$host}/students/{$otherChild->id}");
        $this->assertContains(
            $profile->getStatusCode(),
            [403, 404],
            "A parent opened another family's child profile (status {$profile->getStatusCode()})."
        );
    }

    public function test_a_student_cannot_open_another_students_records(): void
    {
        $school = $this->makeSchool('attack-student');

        $me = $this->seedStudent($school, 'Me', 'STU/0001');
        $peer = app(TenantManager::class)->connectTo($school, fn () => Student::create([
            'first_name' => 'Peer',
            'last_name' => 'Test',
            'admission_number' => 'STU/0002',
            'gender' => 'Male',
            'current_class_arm_id' => $me->current_class_arm_id,
            'status' => Student::STATUS_ACTIVE,
        ]));

        $studentUser = User::factory()->create();
        $this->attach($school, $studentUser, 'student');
        app(TenantManager::class)->connectTo($school, fn () => $me->forceFill(['user_id' => $studentUser->id])->save());

        $term = app(TenantManager::class)->connectTo($school, fn () => Term::first());
        app(TenantManager::class)->connectTo($school, fn () => ReportCardReview::create([
            'student_id' => $peer->id,
            'term_id' => $term->id,
            'report_type' => ReportCardReview::REPORT_TYPE_FULL_TERM,
            'status' => ReportCardReview::STATUS_RELEASED,
            'review_stage' => 0,
        ]));

        $response = $this->actingAs($studentUser)
            ->get("http://{$school->slug}.localhost/report-cards/{$peer->id}/{$term->id}");

        $this->assertContains(
            $response->getStatusCode(),
            [403, 404],
            "A student opened a classmate's report card (status {$response->getStatusCode()})."
        );
    }

    public function test_a_suspended_membership_loses_access_immediately(): void
    {
        $school = $this->makeSchool('attack-suspended');
        $staff = User::factory()->create();
        $this->attach($school, $staff, 'school-owner');

        $this->actingAs($staff)->get("http://{$school->slug}.localhost/dashboard")->assertOk();

        $school->users()->updateExistingPivot($staff->id, ['status' => 'suspended']);

        // A fresh User instance on purpose, and the guards forgotten:
        // User::isMemberOf() memoizes per instance (added 2026-08-13 to
        // kill an N+1 on permission checks), and actingAs() keeps ONE
        // instance alive across every request in a test method, so
        // without this the assertion below would pass on a stale cached
        // "yes" and prove nothing. Real production never hits that: a
        // fresh User is resolved from the session each request, so the
        // cache starts empty. Re-resolving here is what makes this test
        // exercise the same path a genuine second request would.
        app('auth')->forgetGuards();
        $staff = User::find($staff->id);

        $response = $this->actingAs($staff)->get("http://{$school->slug}.localhost/dashboard");
        $this->assertContains(
            $response->getStatusCode(),
            [403, 404, 302],
            "A suspended member still reached the dashboard (status {$response->getStatusCode()})."
        );
    }
}
