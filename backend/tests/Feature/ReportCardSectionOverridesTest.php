<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\ClassNarrativeTerm;
use App\Models\Tenant\ReportCardSkillArea;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\SchoolSectionBranding;
use App\Models\Tenant\Section;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentSkillRemark;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Academics\AcademicDefaults;
use App\Services\Rbac\Rbac;
use App\Services\ReportCards\ReportCardPreferences;
use App\Services\Tenancy\TenantManager;
use App\Support\TermLabel;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Per-section report card templates, the section-logo watermark, the
 * "Key Assessment Areas" (skill based) narrative style, and the
 * school-configurable "Term" wording override -- all general, opt-in
 * platform capabilities (user directive 2026-09-21), built alongside a
 * one-off command applying real data to one specific school (Potter's
 * Prowess Model School), not tested here since it only ever runs
 * against that school's own production database.
 */
class ReportCardSectionOverridesTest extends TestCase
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

    protected function seedOneClass(School $school): array
    {
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Nursery', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'KG One', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $term->makeCurrent();
        $student = Student::create(['admission_number' => 'K1', 'first_name' => 'Kingsley', 'last_name' => 'Adegoke', 'current_class_arm_id' => $classArm->id]);

        app(TenantManager::class)->forget();

        return compact('section', 'class', 'classArm', 'term', 'student');
    }

    public function test_a_section_without_an_override_uses_the_school_default_template(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'sectiontplone');
        ['student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        app(ReportCardPreferences::class)->setTemplate('classic');
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}")
            ->assertOk()
            ->assertDontSee('rc-sheet rc-bordered', false);
    }

    public function test_a_sections_own_template_override_wins_over_the_school_default(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'sectiontpltwo');
        ['section' => $section, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        app(ReportCardPreferences::class)->setTemplate('classic');
        SchoolSectionBranding::create(['section_id' => $section->id, 'report_card_template' => 'bordered']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}")
            ->assertOk()
            ->assertSee('rc-sheet rc-bordered', false);
    }

    public function test_the_watermark_only_renders_once_the_section_turns_it_on(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'sectionwmone');
        ['section' => $section, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        $off = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}");
        $off->assertOk()->assertDontSee('class="rc-watermark"', false);

        app(TenantManager::class)->use($school);
        $school->logo_path = 'schools/1/logo.png';
        $school->save();
        SchoolSectionBranding::create(['section_id' => $section->id, 'watermark_enabled' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}")
            ->assertOk()
            ->assertSee('class="rc-watermark"', false);
    }

    public function test_a_narrative_class_in_a_skills_section_renders_key_assessment_areas_instead_of_subject_comments(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'sectionskillone');
        ['section' => $section, 'class' => $class, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        ClassNarrativeTerm::create(['school_class_id' => $class->id, 'term_id' => $term->id]);
        SchoolSectionBranding::create(['section_id' => $section->id, 'narrative_style' => 'skills']);
        $area = ReportCardSkillArea::create(['category' => 'Literacy & Phonics', 'name' => 'Letter Recognition & Sound', 'sequence' => 1]);
        StudentSkillRemark::create(['student_id' => $student->id, 'term_id' => $term->id, 'skill_area_id' => $area->id, 'remark' => 'He identifies letters a to p.']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}");
        $response->assertOk()
            ->assertSee('Key Assessment Areas')
            ->assertSee('Literacy &amp; Phonics', false)
            ->assertSee('Letter Recognition &amp; Sound', false)
            ->assertSee('He identifies letters a to p.')
            ->assertDontSee('Subject Comments');
    }

    public function test_a_narrative_class_without_the_skills_style_still_renders_the_pre_existing_subject_comments_table(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'sectionskilltwo');
        ['class' => $class, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        ClassNarrativeTerm::create(['school_class_id' => $class->id, 'term_id' => $term->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}")
            ->assertOk()
            ->assertSee('Subject Comments')
            ->assertDontSee('Key Assessment Areas');
    }

    public function test_the_class_entry_screen_shows_skill_area_columns_and_saves_remarks_for_a_skills_section(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'sectionskillentry');
        ['section' => $section, 'class' => $class, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        ClassNarrativeTerm::create(['school_class_id' => $class->id, 'term_id' => $term->id]);
        SchoolSectionBranding::create(['section_id' => $section->id, 'narrative_style' => 'skills']);
        $area = ReportCardSkillArea::create(['category' => 'Numeracy Concepts', 'name' => 'Number Recognition', 'sequence' => 1]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/entry?term_id={$term->id}")
            ->assertOk()
            ->assertSee('Numeracy Concepts')
            ->assertDontSee('Download template');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/entry", [
            'term_id' => $term->id,
            'students' => [
                $student->id => [
                    'skill_remarks' => [$area->id => 'Counts confidently to 20.'],
                ],
            ],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $remark = StudentSkillRemark::where('student_id', $student->id)->where('skill_area_id', $area->id)->first();
        app(TenantManager::class)->forget();

        $this->assertSame('Counts confidently to 20.', $remark->remark);
    }

    public function test_term_label_defaults_to_term_and_drives_new_term_names_once_changed(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'termlabelone');

        app(TenantManager::class)->use($school);
        $this->assertSame('Term', TermLabel::current());

        $session = app(AcademicDefaults::class)->createSessionWithTerms(2030);
        $this->assertSame('First Term 2030/2031', $session->terms()->orderBy('sequence')->first()->name);

        Setting::put('term_label', 'Trimester');
        $this->assertSame('Trimester', TermLabel::current());

        $session2 = app(AcademicDefaults::class)->createSessionWithTerms(2031);
        $this->assertSame('First Trimester 2031/2032', $session2->terms()->orderBy('sequence')->first()->name);
        app(TenantManager::class)->forget();
    }

    public function test_the_sessions_and_terms_page_renders_with_the_schools_own_term_wording(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'termlabeltwo');

        app(TenantManager::class)->use($school);
        Setting::put('term_label', 'Trimester');
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/settings/calendar")
            ->assertOk()
            ->assertSee('Sessions &amp; Trimesters', false);
    }

    public function test_school_profile_saves_the_term_label_without_touching_other_school_fields(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'termlabelthree');
        $school->update(['motto' => 'Knowledge and Character']);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/profile", [
            'term_label' => 'Semester',
            'motto' => 'Knowledge and Character',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame('Semester', TermLabel::current());
        app(TenantManager::class)->forget();

        $this->assertSame('Knowledge and Character', $school->fresh()->motto);
    }
}
