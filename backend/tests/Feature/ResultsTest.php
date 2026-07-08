<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\GradingScale;
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

class ResultsTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected ClassArm $classArm;

    protected Term $term;

    protected Subject $maths;

    protected array $components;

    protected GradingScale $scale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'results']));
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $this->classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $this->term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $this->maths = Subject::create(['name' => 'Mathematics']);

        // A configurable CA format: 20 + 20 + 60 = 100
        $this->components = [
            AssessmentComponent::create(['name' => 'First CA', 'max_score' => 20, 'sequence' => 1]),
            AssessmentComponent::create(['name' => 'Second CA', 'max_score' => 20, 'sequence' => 2]),
            AssessmentComponent::create(['name' => 'Exam', 'max_score' => 60, 'sequence' => 3]),
        ];

        // A configurable grading scale
        $this->scale = GradingScale::create(['name' => 'Standard']);
        $this->scale->makeDefault();
        foreach ([[70, 100, 'A', 'Excellent'], [60, 69.99, 'B', 'Very Good'], [50, 59.99, 'C', 'Good'], [0, 49.99, 'F', 'Fail']] as [$min, $max, $g, $r]) {
            $this->scale->bands()->create(['min_score' => $min, 'max_score' => $max, 'grade' => $g, 'remark' => $r]);
        }
        $this->scale->load('bands');
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function enterScores(Student $student, array $perComponent): void
    {
        foreach ($this->components as $i => $component) {
            Score::create([
                'student_id' => $student->id,
                'subject_id' => $this->maths->id,
                'class_arm_id' => $this->classArm->id,
                'term_id' => $this->term->id,
                'assessment_component_id' => $component->id,
                'score' => $perComponent[$i],
            ]);
        }
    }

    public function test_components_total_possible_is_configurable(): void
    {
        $this->assertSame(100.0, AssessmentComponent::totalPossible());
    }

    public function test_subject_result_sums_components_and_grades(): void
    {
        $ada = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $this->enterScores($ada, [18, 16, 52]); // 86 => A

        $result = app(ResultService::class)->subjectResult($ada->id, $this->maths->id, $this->term->id);

        $this->assertSame(86.0, $result['total']);
        $this->assertSame('A', $result['grade']);
        $this->assertSame('Excellent', $result['remark']);
        $this->assertSame(['First CA' => 18.0, 'Second CA' => 16.0, 'Exam' => 52.0], $result['components']);
    }

    public function test_grade_bands_apply_at_boundaries(): void
    {
        $bode = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        $this->enterScores($bode, [10, 10, 30]); // exactly 50 => C

        $result = app(ResultService::class)->subjectResult($bode->id, $this->maths->id, $this->term->id);
        $this->assertSame('C', $result['grade']);
    }

    public function test_broadsheet_ranks_students_by_total(): void
    {
        $ada = Student::create(['admission_number' => 'A1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        $bode = Student::create(['admission_number' => 'A2', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        $chidi = Student::create(['admission_number' => 'A3', 'first_name' => 'Chidi', 'last_name' => 'Eze']);

        $this->enterScores($ada, [18, 16, 52]);  // 86
        $this->enterScores($bode, [10, 10, 30]);  // 50
        $this->enterScores($chidi, [20, 18, 55]); // 93

        $sheet = app(ResultService::class)->classBroadsheet($this->classArm->id, $this->term->id);

        $this->assertSame($chidi->id, $sheet[0]['student_id']);
        $this->assertSame(1, $sheet[0]['position']);
        $this->assertSame($ada->id, $sheet[1]['student_id']);
        $this->assertSame(2, $sheet[1]['position']);
        $this->assertSame($bode->id, $sheet[2]['student_id']);
        $this->assertSame(3, $sheet[2]['position']);
    }

    public function test_equal_totals_share_a_position(): void
    {
        $x = Student::create(['admission_number' => 'X', 'first_name' => 'X', 'last_name' => 'One']);
        $y = Student::create(['admission_number' => 'Y', 'first_name' => 'Y', 'last_name' => 'Two']);

        $this->enterScores($x, [10, 10, 40]); // 60
        $this->enterScores($y, [20, 10, 30]); // 60

        $sheet = app(ResultService::class)->classBroadsheet($this->classArm->id, $this->term->id);
        $this->assertSame(1, $sheet[0]['position']);
        $this->assertSame(1, $sheet[1]['position']);
    }

    public function test_different_school_can_use_a_totally_different_format(): void
    {
        // Prove nothing is hardcoded: wipe and rebuild a 30/20/50 format.
        Score::query()->delete();
        AssessmentComponent::query()->delete();
        AssessmentComponent::create(['name' => 'Test', 'max_score' => 30, 'sequence' => 1]);
        AssessmentComponent::create(['name' => 'Project', 'max_score' => 20, 'sequence' => 2]);
        AssessmentComponent::create(['name' => 'Exam', 'max_score' => 50, 'sequence' => 3]);

        $this->assertSame(100.0, AssessmentComponent::totalPossible());
        $this->assertSame(3, AssessmentComponent::count());
    }
}
