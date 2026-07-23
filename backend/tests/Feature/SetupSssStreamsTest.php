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
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * php artisan sss:setup-streams (user report 2026-07-23, live on
 * greenfield: "in SSS, all subjects are not done by the all students as
 * we have 3 streams per class... the only mandatory subjects are Maths,
 * English and Civic... update the classes streams... to have the three
 * streams, set the mandatory subjects and divide the students into the
 * three streams per class"). Splits every Senior Secondary class into
 * Science & Technology / Arts & Humanities / Commercial, each with its
 * own subject list on top of the class's own shared mandatory subjects.
 */
class SetupSssStreamsTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    /** Builds one SSS class with the full "ss" catalog attached at the class level, every student on every subject, exactly the pre-fix bug. */
    protected function seedUnstreamedSss(School $school, int $studentCount = 9): array
    {
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Senior Secondary', 'sequence' => 3]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'SSS 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id]);

        $catalog = config('skulos.default_subjects.ss');
        $subjects = [];
        foreach ($catalog['subjects'] as $i => $entry) {
            $subjects[$entry['name']] = Subject::create(['name' => $entry['name'], 'sequence' => $i + 1]);
            $class->subjects()->attach($subjects[$entry['name']]->id, ['is_mandatory' => $entry['mandatory'], 'is_examinable' => $entry['examinable']]);
        }

        $students = collect();
        for ($i = 1; $i <= $studentCount; $i++) {
            $students->push(Student::create(['admission_number' => sprintf('SSS1/%04d', $i), 'first_name' => "Student{$i}", 'last_name' => 'Test', 'current_class_arm_id' => $arm->id]));
        }

        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $term->makeCurrent();
        $component = AssessmentComponent::create(['name' => 'Exam', 'max_score' => 100, 'sequence' => 1]);

        // Every student scored on every one of the 24 subjects, the exact
        // real world bug this command exists to correct.
        foreach ($students as $student) {
            foreach ($subjects as $subject) {
                Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $arm->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 70]);
            }
        }

        $classId = $class->id;
        $armId = $arm->id;
        $studentIds = $students->pluck('id');
        app(TenantManager::class)->forget();

        return compact('classId', 'armId', 'studentIds');
    }

    public function test_it_splits_a_class_into_its_three_streams_with_the_correct_subjects(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'ssssetup']));
        $this->seedUnstreamedSss($school);

        $this->artisan('sss:setup-streams', ['school' => 'ssssetup'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $class = SchoolClass::where('name', 'SSS 1')->first();
        $arms = ClassArm::where('school_class_id', $class->id)->with('arm')->get();
        app(TenantManager::class)->forget();

        $this->assertCount(3, $arms);
        $labels = $arms->map(fn (ClassArm $a) => $a->arm->name)->sort()->values()->all();
        $this->assertSame(['Arts & Humanities', 'Commercial', 'Science & Technology'], $labels);

        app(TenantManager::class)->use($school);
        $classLevelNames = $class->fresh()->subjects->pluck('name')->sort()->values()->all();
        app(TenantManager::class)->forget();
        $this->assertSame(['Civic Education', 'Digital Technologies', 'English Language', 'Mathematics', 'Trade Subject'], $classLevelNames);
    }

    public function test_only_mathematics_english_and_civic_are_mandatory(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'ssssetuptwo']));
        $this->seedUnstreamedSss($school);

        $this->artisan('sss:setup-streams', ['school' => 'ssssetuptwo'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $class = SchoolClass::where('name', 'SSS 1')->first();
        $mandatory = $class->subjects->filter(fn ($s) => $s->pivot->is_mandatory)->pluck('name')->sort()->values()->all();
        app(TenantManager::class)->forget();

        $this->assertSame(['Civic Education', 'English Language', 'Mathematics'], $mandatory);
    }

    /** A subject teacher's own real complaint: no student should end up scored on a subject outside their actual stream. */
    public function test_students_are_split_and_their_scores_are_cleaned_up_to_match_their_own_stream(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'ssssetupthree']));
        ['classId' => $classId, 'studentIds' => $studentIds] = $this->seedUnstreamedSss($school, 9);

        $this->artisan('sss:setup-streams', ['school' => 'ssssetupthree'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $arms = ClassArm::where('school_class_id', $classId)->get()->keyBy(fn (ClassArm $a) => $a->arm->name);
        $this->assertSame(9, Student::whereIn('id', $studentIds)->count(), 'no student should be lost in the split');

        foreach (Student::whereIn('id', $studentIds)->get() as $student) {
            $arm = ClassArm::find($student->current_class_arm_id);
            $offeredIds = $arm->offeredSubjects()->pluck('id');
            $scoredIds = Score::where('student_id', $student->id)->distinct()->pluck('subject_id');

            $this->assertEmpty($scoredIds->diff($offeredIds)->all(), "student {$student->id} has a score for a subject outside their own stream ({$arm->label()})");
            $this->assertEmpty($offeredIds->diff($scoredIds)->all(), "student {$student->id} is missing a score for a subject their own stream offers");
            // Every remaining score row must point at the student's real, current arm, not the old pre-split one.
            $this->assertSame(0, Score::where('student_id', $student->id)->where('class_arm_id', '!=', $arm->id)->count());
        }

        // Reasonably even split: 9 students across 3 streams, none left empty.
        foreach ($arms as $arm) {
            $count = Student::where('current_class_arm_id', $arm->id)->count();
            $this->assertGreaterThanOrEqual(2, $count);
        }
        app(TenantManager::class)->forget();
    }

    public function test_running_it_twice_does_not_duplicate_streams_or_lose_data(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'ssssetupfour']));
        ['classId' => $classId] = $this->seedUnstreamedSss($school, 6);

        $this->artisan('sss:setup-streams', ['school' => 'ssssetupfour'])->assertSuccessful();
        $this->artisan('sss:setup-streams', ['school' => 'ssssetupfour'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $armCount = ClassArm::where('school_class_id', $classId)->count();
        $studentCount = Student::whereIn('current_class_arm_id', ClassArm::where('school_class_id', $classId)->pluck('id'))->count();
        $scoreCount = Score::whereIn('class_arm_id', ClassArm::where('school_class_id', $classId)->pluck('id'))->count();
        app(TenantManager::class)->forget();

        $this->assertSame(3, $armCount);
        $this->assertSame(6, $studentCount);
        $this->assertGreaterThan(0, $scoreCount);
    }

    /** The bug this command shipped with once: "ss" match keywords ('ss1', 'ss 1', ...) are also substrings of "JSS 1"/"JSS 2"/"JSS 3". */
    public function test_a_jss_class_is_never_mistaken_for_a_senior_secondary_class(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'ssssetupfive']));

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        $jss = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $jssArm = ClassArm::create(['school_class_id' => $jss->id]);
        $jssArmId = $jssArm->id;
        app(TenantManager::class)->forget();

        $this->artisan('sss:setup-streams', ['school' => 'ssssetupfive'])->assertSuccessful();

        app(TenantManager::class)->use($school);
        $armCount = ClassArm::where('school_class_id', $jss->id)->count();
        app(TenantManager::class)->forget();
        $this->assertSame(1, $armCount, 'JSS 1 must never be split into SSS streams');
    }
}
