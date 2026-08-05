<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtAssessmentQuestion;
use App\Models\Tenant\CbtSecurityProfile;
use App\Models\Tenant\CbtSession;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * User directive 2026-08-05: "parent view of assessments should be for
 * each child... each child card shows if a child has an assessment,
 * how many, completed, left etc. and when parent opens each one can
 * see the complete details, score, pass mark, etc." A deliberately
 * separate screen from CbtTakeController::available() — see
 * CbtParentAssessmentController's own docblock for why.
 */
class CbtParentAssessmentTest extends TestCase
{
    use \Tests\Concerns\MakesCbtQuestions, RefreshDatabase;

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

    /**
     * A parent with two children (one with a real login, one without),
     * one active assessment open to everyone. Returns
     * [school, parentUser, childWithLogin, childWithoutLogin, assessment].
     */
    protected function schoolWithParentAndChildren(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);

        $parentUser = User::factory()->create();
        $parentRole = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parentUser->id, ['role_id' => $parentRole->id, 'status' => 'active']);

        $childUser = User::factory()->create();
        $studentRole = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($childUser->id, ['role_id' => $studentRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $childWithLogin = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'user_id' => $childUser->id]);
        $childWithoutLogin = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Obi']);
        StudentGuardian::create(['student_id' => $childWithLogin->id, 'name' => $parentUser->name, 'relationship' => 'Mother', 'guardian_user_id' => $parentUser->id, 'is_primary' => true]);
        StudentGuardian::create(['student_id' => $childWithoutLogin->id, 'name' => $parentUser->name, 'relationship' => 'Mother', 'guardian_user_id' => $parentUser->id, 'is_primary' => true]);

        CbtSecurityProfile::ensureSeeded();
        $basic = CbtSecurityProfile::where('name', 'Basic')->first();
        $assessment = CbtAssessment::create([
            'title' => 'Term Quiz', 'duration_minutes' => 20, 'security_profile_id' => $basic->id,
            'audience' => 'student', 'pass_mark' => 5,
        ]);
        $mcq = $this->makeChoiceQuestion('2+2?', ['3', '4', '5'], ['4'], 10);
        CbtAssessmentQuestion::create(['assessment_id' => $assessment->id, 'question_id' => $mcq->id, 'sequence' => 0]);
        app(TenantManager::class)->forget();

        return [$school, $parentUser, $childWithLogin, $childWithoutLogin, $assessment];
    }

    public function test_the_index_shows_a_card_per_child_with_real_counts(): void
    {
        [$school, $parentUser, $childWithLogin] = $this->schoolWithParentAndChildren('cpaone');

        $this->actingAs($parentUser)->get(route('school.cbt.parent.index', ['school' => $school->slug]))
            ->assertOk()
            ->assertSee('Ada Obi')
            ->assertSee('Bode Obi');
    }

    public function test_a_childs_completed_and_left_counts_reflect_their_own_session(): void
    {
        [$school, $parentUser, $childWithLogin, , $assessment] = $this->schoolWithParentAndChildren('cpatwo');

        app(TenantManager::class)->use($school);
        CbtSession::forceCreate([
            'assessment_id' => $assessment->id, 'takeable_type' => User::class, 'takeable_id' => $childWithLogin->user_id,
            'status' => CbtSession::STATUS_GRADED, 'total_score' => 10, 'max_score' => 10,
            'started_at' => now()->subMinutes(20), 'ends_at' => now(), 'submitted_at' => now(),
        ]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($parentUser)->get(route('school.cbt.parent.index', ['school' => $school->slug]));
        $response->assertOk();

        // Ada (has a session, graded) should read 1 total / 1 completed / 0 left;
        // Bode (no login at all, so never eligible/attempted) reads 0 total.
        $response->assertSeeInOrder(['Ada Obi', '1', 'Total', '1', 'Completed', '0', 'Left']);
    }

    public function test_the_child_detail_screen_shows_the_score_and_pass_fail(): void
    {
        [$school, $parentUser, $childWithLogin, , $assessment] = $this->schoolWithParentAndChildren('cpathree');

        app(TenantManager::class)->use($school);
        CbtSession::forceCreate([
            'assessment_id' => $assessment->id, 'takeable_type' => User::class, 'takeable_id' => $childWithLogin->user_id,
            'status' => CbtSession::STATUS_GRADED, 'total_score' => 10, 'max_score' => 10,
            'started_at' => now()->subMinutes(20), 'ends_at' => now(), 'submitted_at' => now(),
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->get(route('school.cbt.parent.child', ['school' => $school->slug, 'studentId' => $childWithLogin->id]))
            ->assertOk()
            ->assertSee('Term Quiz')
            ->assertSee('10.00/10.00')
            ->assertSee('Passed');
    }

    public function test_a_parent_cannot_open_a_child_that_is_not_theirs(): void
    {
        [$school, $parentUser] = $this->schoolWithParentAndChildren('cpafour');

        app(TenantManager::class)->use($school);
        $stranger = Student::create(['admission_number' => 'Z1', 'first_name' => 'Chi', 'last_name' => 'Eze']);
        $strangerId = $stranger->id;
        app(TenantManager::class)->forget();

        $this->actingAs($parentUser)->get(route('school.cbt.parent.child', ['school' => $school->slug, 'studentId' => $strangerId]))
            ->assertNotFound();
    }

    public function test_a_non_parent_role_cannot_reach_this_screen(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'cpafive']));
        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);
        $teacher = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'subject-teacher');
        $school->users()->attach($teacher->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($teacher)->get(route('school.cbt.parent.index', ['school' => $school->slug]))
            ->assertForbidden();
    }
}
