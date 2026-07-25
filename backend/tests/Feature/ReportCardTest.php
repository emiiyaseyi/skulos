<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\GradingBand;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\Score;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\ReportCards\ReportCardService;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Report card templates/fields (admin editable, config catalog defaults)
 * and the term-by-term cumulative average (user request 2026-07-09: a
 * later term's report brings in earlier terms' totals as columns and an
 * average, generalised to any number of terms, not hardcoded to "third").
 */
class ReportCardTest extends TestCase
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

    public function test_admin_can_choose_a_template_and_toggle_fields(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'rcone');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/report-cards/settings", [
            'template' => 'formal',
            'fields' => ['admission_number' => '1', 'subject_position' => '1'],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $prefs = app(\App\Services\ReportCards\ReportCardPreferences::class);
        $template = $prefs->template();
        $shows = $prefs->shows('subject_position');
        $hidesGender = $prefs->shows('gender'); // not included in the posted fields, so should be off now
        app(TenantManager::class)->forget();

        $this->assertSame('formal', $template);
        $this->assertTrue($shows);
        $this->assertFalse($hidesGender);
    }

    public function test_preview_renders_every_template_without_error(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'rctwo');

        foreach (array_keys(config('skulos.report_card_templates')) as $template) {
            $this->actingAs($admin)->get("http://{$school->slug}.localhost/report-cards/preview/{$template}")
                ->assertOk()->assertSee('Ada Grace Obi');
        }
    }

    public function test_viewing_report_cards_requires_permission(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'rcthree');

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/report-cards/preview")->assertForbidden();
    }

    public function test_real_report_card_shows_cumulative_previous_terms_and_average(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'rcfour');
        app(TenantManager::class)->use($school);

        $session = AcademicSession::create(['name' => '2025/2026']);
        $term1 = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $term2 = Term::create(['academic_session_id' => $session->id, 'name' => 'Second Term', 'sequence' => 2]);
        $term3 = Term::create(['academic_session_id' => $session->id, 'name' => 'Third Term', 'sequence' => 3]);

        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 2', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);

        $subject = Subject::create(['name' => 'Mathematics']);
        $component = AssessmentComponent::create(['name' => 'Exam', 'max_score' => 100, 'sequence' => 1]);

        $scale = GradingScale::create(['name' => 'Standard', 'is_default' => true]);
        GradingBand::create(['grading_scale_id' => $scale->id, 'min_score' => 0, 'max_score' => 100, 'grade' => 'A', 'remark' => 'Good']);

        $student = Student::create([
            'admission_number' => 'ADM/900', 'first_name' => 'Chika', 'last_name' => 'Nwosu',
            'current_class_arm_id' => $classArm->id,
        ]);

        Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id, 'term_id' => $term1->id, 'assessment_component_id' => $component->id, 'score' => 70]);
        Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id, 'term_id' => $term2->id, 'assessment_component_id' => $component->id, 'score' => 80]);
        Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id, 'term_id' => $term3->id, 'assessment_component_id' => $component->id, 'score' => 90]);

        $data = app(ReportCardService::class)->forStudent($student, $term3);

        app(TenantManager::class)->forget();

        $maths = collect($data['subjects'])->firstWhere('name', 'Mathematics');
        $this->assertSame(90.0, $maths['total']);
        $this->assertSame(['First Term' => 70.0, 'Second Term' => 80.0], $maths['previous_terms']);
        $this->assertSame(80.0, $maths['cumulative_average']); // (70 + 80 + 90) / 3
    }

    /**
     * User request 2026-07-24: "add a Grade to this section which is
     * based on the overall average gotten by the student... check the
     * section grade structure to get the grade to be placed here... it
     * changes based on the section and grade set." Two students in two
     * different sections, each section assigned its own scale, must be
     * graded against their own section's scale, not one school wide
     * default for both.
     */
    public function test_the_overall_grade_uses_the_students_own_section_grading_scale(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'rcgrade');
        app(TenantManager::class)->use($school);

        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $component = AssessmentComponent::create(['name' => 'Exam', 'max_score' => 100, 'sequence' => 1]);

        $strictScale = GradingScale::create(['name' => 'Strict Scale', 'is_default' => true]);
        GradingBand::create(['grading_scale_id' => $strictScale->id, 'min_score' => 0, 'max_score' => 100, 'grade' => 'B']);

        $generousScale = GradingScale::create(['name' => 'Generous Scale']);
        GradingBand::create(['grading_scale_id' => $generousScale->id, 'min_score' => 0, 'max_score' => 100, 'grade' => 'A']);

        $sectionWithScale = Section::create(['name' => 'Secondary', 'sequence' => 1, 'grading_scale_id' => $generousScale->id]);
        $sectionWithoutScale = Section::create(['name' => 'Primary', 'sequence' => 2]);

        $classWithScale = SchoolClass::create(['section_id' => $sectionWithScale->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $classWithoutScale = SchoolClass::create(['section_id' => $sectionWithoutScale->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $armWithScale = ClassArm::create(['school_class_id' => $classWithScale->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $armWithoutScale = ClassArm::create(['school_class_id' => $classWithoutScale->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);

        $studentInScopedSection = Student::create(['admission_number' => 'G1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $armWithScale->id]);
        $studentInDefaultSection = Student::create(['admission_number' => 'G2', 'first_name' => 'Chi', 'last_name' => 'Eze', 'current_class_arm_id' => $armWithoutScale->id]);

        foreach ([$studentInScopedSection, $studentInDefaultSection] as $student) {
            Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $student->current_class_arm_id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 90]);
        }

        $scopedData = app(ReportCardService::class)->forStudent($studentInScopedSection, $term);
        $defaultData = app(ReportCardService::class)->forStudent($studentInDefaultSection, $term);
        app(TenantManager::class)->forget();

        $this->assertSame('A', $scopedData['overall']['grade'], 'a section with its own assigned scale must use it, not the school default');
        $this->assertSame('B', $defaultData['overall']['grade'], 'a section with no scale assigned falls back to the school default');
    }

    /**
     * Real bug found live on greenfield, 2026-07-24: JSS and SSS both
     * sit under one shared "Secondary" section, so a section level
     * scale alone cannot tell WAEC graded SSS apart from NECO/BECE
     * graded JSS ("i am viewing a report card in sss and it is showing
     * grade as excellent, good etc which is not what we have under the
     * grading structure for the section"). A class's own override must
     * win over its section's own scale.
     */
    public function test_a_classs_own_grading_scale_overrides_its_sections(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'rcclassgrade');
        app(TenantManager::class)->use($school);

        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $component = AssessmentComponent::create(['name' => 'Exam', 'max_score' => 100, 'sequence' => 1]);

        $juniorScale = GradingScale::create(['name' => 'Junior Secondary Scale']);
        GradingBand::create(['grading_scale_id' => $juniorScale->id, 'min_score' => 0, 'max_score' => 100, 'grade' => 'Merit']);

        $seniorScale = GradingScale::create(['name' => 'Senior Secondary Scale (WAEC)']);
        GradingBand::create(['grading_scale_id' => $seniorScale->id, 'min_score' => 0, 'max_score' => 100, 'grade' => 'A1']);

        // Both JSS and SSS share the one "Secondary" section, the exact real shape.
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $jssClass = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1, 'grading_scale_id' => $juniorScale->id]);
        $sssClass = SchoolClass::create(['section_id' => $section->id, 'name' => 'SSS 1', 'sequence' => 2, 'grading_scale_id' => $seniorScale->id]);
        $jssArm = ClassArm::create(['school_class_id' => $jssClass->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $sssArm = ClassArm::create(['school_class_id' => $sssClass->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);

        $jssStudent = Student::create(['admission_number' => 'JC1', 'first_name' => 'Ada', 'last_name' => 'Obi', 'current_class_arm_id' => $jssArm->id]);
        $sssStudent = Student::create(['admission_number' => 'SC1', 'first_name' => 'Chi', 'last_name' => 'Eze', 'current_class_arm_id' => $sssArm->id]);

        foreach ([$jssStudent, $sssStudent] as $student) {
            Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $student->current_class_arm_id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 90]);
        }

        $jssData = app(ReportCardService::class)->forStudent($jssStudent, $term);
        $sssData = app(ReportCardService::class)->forStudent($sssStudent, $term);
        app(TenantManager::class)->forget();

        $this->assertSame('Merit', $jssData['overall']['grade'], 'JSS must use its own class assigned Junior scale');
        $this->assertSame('A1', $sssData['overall']['grade'], 'SSS must use its own class assigned Senior/WAEC scale, not the shared sections scale');
    }

    /** User request 2026-07-24: "toggle any of the 5 items under summary on or off". */
    public function test_each_summary_box_item_is_independently_toggleable(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rcsummary');
        app(TenantManager::class)->use($school);

        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $term->makeCurrent();
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => Arm::create(['name' => 'A'])->id]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $component = AssessmentComponent::create(['name' => 'Exam', 'max_score' => 100, 'sequence' => 1]);
        $scale = GradingScale::create(['name' => 'Scale', 'is_default' => true]);
        GradingBand::create(['grading_scale_id' => $scale->id, 'min_score' => 0, 'max_score' => 100, 'grade' => 'A']);
        $student = Student::create(['admission_number' => 'S1', 'first_name' => 'Bola', 'last_name' => 'Ade', 'current_class_arm_id' => $arm->id]);
        Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_arm_id' => $arm->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 90]);
        $studentId = $student->id;
        $termId = $term->id;
        app(TenantManager::class)->forget();

        // Turn off everything except Grade.
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings", [
            'template' => 'classic',
            'fields' => ['summary_grade' => '1'],
        ])->assertRedirect();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$studentId}/{$termId}");
        $response->assertOk()
            ->assertDontSee('Total Score')
            ->assertDontSee('>Average<', false)
            ->assertDontSee('>Position<', false)
            ->assertDontSee('Class Size')
            ->assertSee('>Grade<', false);
    }

    /**
     * Class Position & Ranking (Advance plan feature 'class_position_
     * ranking', wired into the nav 2026-07-18): the underlying computation
     * (ResultService::classBroadsheet()/subjectStats()) already existed
     * and was already rendered on the report card ("overall_position"
     * defaults on), just never mapped to a nav link. Proves it with a
     * real 3-student class, not the single-student fixture above, where
     * a trivial "1st of 1" would not actually prove ranking works.
     */
    public function test_the_real_report_card_shows_a_students_actual_class_position(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'rcposition');
        app(TenantManager::class)->use($school);

        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 3', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $component = AssessmentComponent::create(['name' => 'Exam', 'max_score' => 100, 'sequence' => 1]);

        $top = Student::create(['admission_number' => 'P1', 'first_name' => 'Top', 'last_name' => 'Scorer', 'current_class_arm_id' => $classArm->id]);
        $middle = Student::create(['admission_number' => 'P2', 'first_name' => 'Middle', 'last_name' => 'Scorer', 'current_class_arm_id' => $classArm->id]);
        $last = Student::create(['admission_number' => 'P3', 'first_name' => 'Last', 'last_name' => 'Scorer', 'current_class_arm_id' => $classArm->id]);

        Score::create(['student_id' => $top->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 95]);
        Score::create(['student_id' => $middle->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 70]);
        Score::create(['student_id' => $last->id, 'subject_id' => $subject->id, 'class_arm_id' => $classArm->id, 'term_id' => $term->id, 'assessment_component_id' => $component->id, 'score' => 40]);

        $middleData = app(ReportCardService::class)->forStudent($middle, $term);
        app(TenantManager::class)->forget();

        $this->assertSame(2, $middleData['overall']['position']);
        $this->assertSame(3, $middleData['overall']['class_size']);
    }

    /** User request 2026-07-24: "beside the school address, the same row let's have the school website, email address and all phone numbers". */
    public function test_header_shows_website_email_and_phone_when_toggled_on(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'rccontact');
        $school->update(['address' => '1 Unity Road', 'website' => 'https://greenfield.example', 'email' => 'info@greenfield.example', 'phone' => '0800 000 0000']);

        $response = $this->actingAs($admin)->get("http://{$school->slug}.localhost/report-cards/preview/classic");

        $response->assertOk()
            ->assertSee('1 Unity Road')
            ->assertSee('https://greenfield.example')
            ->assertSee('info@greenfield.example')
            ->assertSee('0800 000 0000');
    }

    public function test_header_contact_fields_can_be_turned_off_individually(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'rccontactoff');
        $school->update(['address' => '1 Unity Road', 'website' => 'https://greenfield.example', 'email' => 'info@greenfield.example']);

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/report-cards/settings", [
            'template' => 'classic',
            'fields' => ['school_address' => '1'],
        ])->assertRedirect();

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/report-cards/preview/classic")
            ->assertOk()
            ->assertSee('1 Unity Road')
            ->assertDontSee('https://greenfield.example')
            ->assertDontSee('info@greenfield.example');
    }

    /**
     * User request 2026-07-24: "a footer for report card where the
     * school type what they want as the footer but by default picks
     * the motto under school profile and use".
     */
    public function test_footer_defaults_to_the_school_motto_and_can_be_overridden(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'rcfooter');
        $school->update(['motto' => 'Knowledge and Character']);

        $this->actingAs($admin)->get("http://{$school->slug}.localhost/report-cards/preview/classic")
            ->assertOk()->assertSee('Knowledge and Character');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/report-cards/settings", [
            'template' => 'classic',
            'fields' => ['school_address' => '1'],
            'footer_text' => 'Custom footer line',
            'footer_align' => 'right',
        ])->assertRedirect();
        $school->update(['address' => '1 Unity Road']); // so the header's own motto fallback does not also fire

        $response = $this->actingAs($admin)->get("http://{$school->slug}.localhost/report-cards/preview/classic");
        $response->assertOk()
            ->assertSee('Custom footer line')
            ->assertSee('text-align:right', false)
            ->assertDontSee('Knowledge and Character');
    }
}
