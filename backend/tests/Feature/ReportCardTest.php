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
}
