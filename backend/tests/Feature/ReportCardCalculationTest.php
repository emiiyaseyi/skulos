<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Score;
use App\Models\Tenant\Section;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\ReportCards\ReportCardCumulativePolicy;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Term Calculation settings (Examinations > Term Calculation, user
 * request 2026-07-24): "for third term - the school can decide to add
 * first term total and second term total plus the third term total then
 * find the average for each subject offered by the class - admin can
 * toggle it off if the school decide otherwise... alternatively, the
 * school can decide to add the total from previous terms but it wont
 * find the average, it just display it for comparison purpose only."
 */
class ReportCardCalculationTest extends TestCase
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

    /** One class, one subject, two terms (First/Second), a student scored 60 in term 1 and 100 in term 2. */
    protected function seedTwoTermsWithScores(School $school): array
    {
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $class->subjects()->attach($subject->id, ['is_mandatory' => true, 'is_examinable' => true]);
        $component = AssessmentComponent::create(['name' => 'Exam', 'max_score' => 100, 'sequence' => 1]);

        $session = AcademicSession::create(['name' => '2025/2026']);
        $termOne = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $termTwo = Term::create(['academic_session_id' => $session->id, 'name' => 'Second Term', 'sequence' => 2]);
        $termTwo->makeCurrent();

        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm->id]);

        Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id, 'term_id' => $termOne->id, 'assessment_component_id' => $component->id, 'score' => 60]);
        Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id, 'term_id' => $termTwo->id, 'assessment_component_id' => $component->id, 'score' => 100]);

        $studentId = $student->id;
        $termTwoId = $termTwo->id;
        app(TenantManager::class)->forget();

        return compact('studentId', 'termTwoId');
    }

    public function test_the_index_lists_every_distinct_term_sequence(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'calcone');
        $this->seedTwoTermsWithScores($school);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/examinations/report-card-calculation")
            ->assertOk()
            ->assertSee('First Term')
            ->assertSee('Second Term')
            ->assertSee('This term only, nothing comes before it.');
    }

    public function test_an_admin_can_save_a_mode_for_a_sequence(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'calctwo');
        $this->seedTwoTermsWithScores($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/examinations/report-card-calculation", [
            'sequence' => [2 => 'comparison'],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $policy = app(ReportCardCumulativePolicy::class)->all();
        app(TenantManager::class)->forget();

        $this->assertSame('comparison', $policy[2]);
    }

    public function test_a_plain_teacher_cannot_reach_term_calculation_settings(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('teacher', 'calcthree');

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/examinations/report-card-calculation")
            ->assertForbidden();
    }

    public function test_the_nav_shows_term_calculation_under_examinations_for_an_admin(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'calcfour');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Term Calculation');
    }

    public function test_average_mode_shows_previous_term_and_a_real_average(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'calcfive');
        ['studentId' => $studentId, 'termTwoId' => $termTwoId] = $this->seedTwoTermsWithScores($school);

        app(TenantManager::class)->use($school);
        app(ReportCardCumulativePolicy::class)->setPolicy([2 => 'average']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$studentId}/{$termTwoId}");

        $response->assertOk()->assertSee('First Term')->assertSee('Average', false)
            ->assertSee('60', false) // first term's own total, shown as a comparison column
            ->assertSee('80', false); // (60 + 100) / 2
    }

    public function test_comparison_mode_shows_previous_term_but_no_average(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'calcsix');
        ['studentId' => $studentId, 'termTwoId' => $termTwoId] = $this->seedTwoTermsWithScores($school);

        app(TenantManager::class)->use($school);
        app(ReportCardCumulativePolicy::class)->setPolicy([2 => 'comparison']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$studentId}/{$termTwoId}");

        // A bare 'Average' is too broad, the overall average summary box
        // ("98.09% Average") renders regardless of this policy; the
        // subjects table's own Average column header is this specific
        // markup only 'average' mode ever renders.
        $response->assertOk()->assertSee('First Term')->assertDontSee('<th class="num">Average</th>', false);
    }

    public function test_off_mode_shows_neither_previous_term_columns_nor_an_average(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'calcseven');
        ['studentId' => $studentId, 'termTwoId' => $termTwoId] = $this->seedTwoTermsWithScores($school);

        app(TenantManager::class)->use($school);
        app(ReportCardCumulativePolicy::class)->setPolicy([2 => 'off']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$studentId}/{$termTwoId}");

        $response->assertOk()->assertDontSee('First Term')->assertDontSee('<th class="num">Average</th>', false);
    }

    /** A session's own first term always shows this term alone, regardless of any saved policy for that sequence (there is nothing earlier to add or compare). */
    public function test_the_first_term_of_a_session_is_always_this_term_only(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'calceight');
        $this->seedTwoTermsWithScores($school);

        app(TenantManager::class)->use($school);
        $termOne = Term::where('sequence', 1)->first();
        $mode = app(ReportCardCumulativePolicy::class)->modeFor($termOne);
        app(TenantManager::class)->forget();

        $this->assertSame('off', $mode);
    }
}
