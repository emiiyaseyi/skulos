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
use App\Services\Results\ResultService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * schools:rescale-scores (2026-07-16, user directive: "THE 62.2% Term
 * Average SHOULD STILL GO HIGHER UP TO 80% (UPDATE THE STUDENT SEEDED
 * ASSESSMENT RECORD TO ACHIEVE THIS)"). PopulateStudentScores never
 * overwrites an already-scored subject, so an already-fully-seeded school
 * needed a one-off rescale instead of a fresh generation.
 */
class RescaleStudentScoresTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    /** One student, one subject, CA/20 + Exam/80, scored at 40% (CA 8, Exam 32). */
    protected function seedLowScoringSchool(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $subject = Subject::create(['name' => 'Mathematics']);

        $ca = AssessmentComponent::create(['name' => 'CA', 'type' => 'ca', 'max_score' => 20, 'sequence' => 1]);
        $exam = AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 80, 'sequence' => 2]);

        $session = AcademicSession::create(['name' => '2025/2026 Session']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $term->makeCurrent();

        $student = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm->id]);

        Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id, 'term_id' => $term->id, 'assessment_component_id' => $ca->id, 'score' => 8]);
        Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id, 'term_id' => $term->id, 'assessment_component_id' => $exam->id, 'score' => 32]);

        app(TenantManager::class)->forget();

        return [$school, $student, $term, $ca, $exam];
    }

    public function test_rescaling_raises_the_average_to_the_target_and_clamps_at_each_components_max(): void
    {
        [$school, , $term, $ca, $exam] = $this->seedLowScoringSchool('rescaleone');

        app(TenantManager::class)->use($school);
        $before = app(ResultService::class)->termAveragePercentage($term->id);
        app(TenantManager::class)->forget();
        $this->assertSame(40.0, $before);

        $this->artisan('schools:rescale-scores', ['school' => 'rescaleone', '--target' => 80])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $after = app(ResultService::class)->termAveragePercentage($term->id);
        $caScore = Score::where('assessment_component_id', $ca->id)->first();
        $examScore = Score::where('assessment_component_id', $exam->id)->first();
        app(TenantManager::class)->forget();

        $this->assertSame(80.0, $after);
        $this->assertLessThanOrEqual(20, (float) $caScore->score, 'a rescaled CA score must never exceed its own 20 point max');
        $this->assertLessThanOrEqual(80, (float) $examScore->score, 'a rescaled Exam score must never exceed its own 80 point max');
    }

    public function test_a_school_already_at_or_above_the_target_is_left_untouched(): void
    {
        [$school, , , $ca, $exam] = $this->seedLowScoringSchool('rescaletwo');

        $this->artisan('schools:rescale-scores', ['school' => 'rescaletwo', '--target' => 10])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $caScore = Score::where('assessment_component_id', $ca->id)->first();
        $examScore = Score::where('assessment_component_id', $exam->id)->first();
        app(TenantManager::class)->forget();

        $this->assertSame(8.0, (float) $caScore->score, 'already above a 10% target, must not be touched');
        $this->assertSame(32.0, (float) $examScore->score);
    }
}
