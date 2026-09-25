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
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term 2025/2026', 'sequence' => 1]);
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

    /**
     * The watermark defaults to the section's own logo (unchanged), but
     * can instead be plain text (user directive 2026-09-22: "make it
     * possible for the school to upload another or add a text").
     */
    public function test_a_section_can_use_a_text_watermark_instead_of_its_logo(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'sectionwmtext');
        ['section' => $section, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        SchoolSectionBranding::create([
            'section_id' => $section->id, 'watermark_enabled' => true,
            'watermark_mode' => 'text', 'watermark_text' => 'GREENFIELD',
        ]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}");
        $response->assertOk();
        $response->assertSee('class="rc-watermark-text"', false);
        $response->assertSee('GREENFIELD');
        $response->assertDontSee('class="rc-watermark"', false);
    }

    /**
     * The admin form itself, a real round trip: choosing "custom image"
     * uploads a separate file, not the section's real logo.
     */
    public function test_an_admin_can_upload_a_custom_watermark_image_via_the_settings_form(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'sectionwmupload');
        ['section' => $section, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        SchoolSectionBranding::create(['section_id' => $section->id, 'watermark_enabled' => true]);
        $school->logo_path = 'schools/'.$school->id.'/logo.png';
        $school->save();
        app(TenantManager::class)->forget();

        \Illuminate\Support\Facades\Storage::fake('public');
        $file = \Illuminate\Http\UploadedFile::fake()->image('mark.png', 200, 200);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/sections/{$section->id}/watermark", [
            'watermark_mode' => 'custom',
            'watermark_image' => $file,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $branding = SchoolSectionBranding::where('section_id', $section->id)->first();
        app(TenantManager::class)->forget();

        $this->assertSame('custom', $branding->watermark_mode);
        $this->assertNotNull($branding->watermark_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($branding->watermark_path);

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}");
        $response->assertOk();
        $response->assertSee('class="rc-watermark"', false);
        // The watermark shows the separately uploaded file, not the
        // section's real logo (still legitimately shown in the header
        // itself, so a bare assertDontSee('logo.png') would be wrong).
        $response->assertSee($branding->watermark_path);
        $html = $response->getContent();
        preg_match('/class="rc-watermark" style="background-image:url\(\'([^\']+)\'\)"/', $html, $m);
        $this->assertStringNotContainsString('logo.png', $m[1] ?? '');
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

    /**
     * The direct audit case: two separate schools, one opts fully into
     * every feature in this batch (template override, watermark,
     * Key Assessment Areas narrative style, custom term wording -- the
     * same shape of change applied to PPMS in production), the other
     * never touches any of it. Since each school lives in its own
     * physically separate tenant database (this codebase's whole
     * multi-tenancy model), the un-opted-in school cannot see any of
     * school one's data by construction -- this proves it end to end
     * through real HTTP requests rather than just asserting the schema.
     */
    public function test_one_schools_opt_in_to_every_feature_in_this_batch_leaves_a_second_school_completely_unaffected(): void
    {
        [$schoolOne, $ownerOne] = $this->schoolWithMember('school-owner', 'auditschoolone');
        ['section' => $sectionOne, 'class' => $classOne, 'student' => $studentOne, 'term' => $termOne] = $this->seedOneClass($schoolOne);

        [$schoolTwo, $ownerTwo] = $this->schoolWithMember('school-owner', 'auditschooltwo');
        ['student' => $studentTwo, 'term' => $termTwo] = $this->seedOneClass($schoolTwo);

        // School one opts into everything: the watermark, the skill-based
        // narrative style with its own Key Assessment Areas, and custom
        // term wording -- exactly PPMS's own real setup (a section's
        // report_card_template override is a SEPARATE, non-narrative
        // concern; a narrative-evaluation class always renders the
        // 'narrative' look regardless of that override, so it is not
        // combined with narrative mode here, matching how the real
        // ppms:setup-report-card command itself never sets it either).
        app(TenantManager::class)->use($schoolOne);
        $schoolOne->logo_path = 'schools/1/logo.png';
        $schoolOne->save();
        Setting::put('term_label', 'Trimester');
        ClassNarrativeTerm::create(['school_class_id' => $classOne->id, 'term_id' => $termOne->id]);
        SchoolSectionBranding::create([
            'section_id' => $sectionOne->id,
            'narrative_style' => 'skills',
            'watermark_enabled' => true,
        ]);
        $area = ReportCardSkillArea::create(['category' => 'Literacy & Phonics', 'name' => 'Letter Recognition & Sound', 'sequence' => 1]);
        StudentSkillRemark::create(['student_id' => $studentOne->id, 'term_id' => $termOne->id, 'skill_area_id' => $area->id, 'remark' => 'He identifies letters a to p.']);
        app(TenantManager::class)->forget();

        // School one's own report card shows every opted-in feature.
        $this->actingAs($ownerOne)->get("http://{$schoolOne->slug}.localhost/report-cards/{$studentOne->id}/{$termOne->id}")
            ->assertOk()
            ->assertSee('class="rc-watermark"', false)
            ->assertSee('Key Assessment Areas')
            ->assertSee('He identifies letters a to p.');

        $this->actingAs($ownerOne)->get("http://{$schoolOne->slug}.localhost/settings/calendar")
            ->assertOk()
            ->assertSee('Sessions &amp; Trimesters', false);

        // School two never touched any of this: plain template, no
        // watermark, plain subject comments (no narrative mode at all
        // here, so no "Key Assessment Areas" or "Subject Comments"
        // block either), the stock "Term" wording, and specifically NOT
        // school one's own skill-area catalog entry or remark text --
        // the strongest possible proof nothing leaked across schools.
        $twoResponse = $this->actingAs($ownerTwo)->get("http://{$schoolTwo->slug}.localhost/report-cards/{$studentTwo->id}/{$termTwo->id}");
        $twoResponse->assertOk()
            ->assertDontSee('class="rc-watermark"', false)
            ->assertDontSee('Key Assessment Areas')
            ->assertDontSee('Letter Recognition & Sound')
            ->assertDontSee('He identifies letters a to p.');

        $this->actingAs($ownerTwo)->get("http://{$schoolTwo->slug}.localhost/settings/calendar")
            ->assertOk()
            ->assertSee('Sessions &amp; Terms', false)
            ->assertDontSee('Sessions &amp; Trimesters', false);

        app(TenantManager::class)->use($schoolTwo);
        $this->assertSame('Term', TermLabel::current());
        $this->assertSame(0, ReportCardSkillArea::count());
        $this->assertNull(SchoolSectionBranding::first());
        app(TenantManager::class)->forget();
    }

    /**
     * The general, any-school command built for the greenfield request
     * (user directive 2026-09-21, asked right after PPMS's own one-off
     * command was applied): same shape of setup, auto-detecting real
     * section/class names and seeding the shared starter catalog,
     * without touching the school's own address/phone/website (that
     * stays real PPMS-only data, never copied by this general command).
     */
    public function test_the_general_enable_skills_section_command_auto_detects_and_seeds_the_starter_catalog(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'greenfieldstyle');
        ['section' => $section, 'class' => $class, 'term' => $term] = $this->seedOneClass($school);
        $originalAddress = $school->address;

        $this->artisan('report-card:enable-skills-section', ['slug' => $school->slug, '--term-label' => 'Trimester'])
            ->assertExitCode(0);

        app(TenantManager::class)->use($school);
        $this->assertSame('Trimester', TermLabel::current());
        $this->assertStringContainsString('Trimester', Term::find($term->id)->name);
        $branding = SchoolSectionBranding::where('section_id', $section->id)->first();
        $this->assertSame('skills', $branding->narrative_style);
        $this->assertTrue((bool) $branding->watermark_enabled);
        $this->assertTrue($class->fresh()->isNarrativeEvaluationForTerm($term->id));
        $this->assertSame(13, ReportCardSkillArea::count());
        app(TenantManager::class)->forget();

        // Address/phone/website are real PPMS-only data, never touched
        // by this general, reusable command for a different school.
        $this->assertSame($originalAddress, $school->fresh()->address);
    }

    /**
     * Live user directive 2026-09-21, confirmed via AskUserQuestion:
     * "creche, reception and KG classes of nursery" should ALL be in
     * the narrative style, not just one auto-detected "KG One" class.
     */
    public function test_all_classes_in_section_switches_every_class_not_just_one(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'allclassesone');
        ['section' => $section, 'class' => $kg1, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        $creche = SchoolClass::create(['section_id' => $section->id, 'name' => 'Creche', 'sequence' => 0]);
        $reception = SchoolClass::create(['section_id' => $section->id, 'name' => 'Reception', 'sequence' => 1]);
        app(TenantManager::class)->forget();

        $this->artisan('report-card:enable-skills-section', ['slug' => $school->slug, '--all-classes-in-section' => true])
            ->assertExitCode(0);

        app(TenantManager::class)->use($school);
        $this->assertTrue($kg1->fresh()->isNarrativeEvaluationForTerm($term->id));
        $this->assertTrue($creche->fresh()->isNarrativeEvaluationForTerm($term->id));
        $this->assertTrue($reception->fresh()->isNarrativeEvaluationForTerm($term->id));
        app(TenantManager::class)->forget();
    }

    public function test_the_general_enable_skills_section_command_never_overwrites_an_already_customized_catalog(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'greenfieldstyletwo');
        $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        ReportCardSkillArea::create(['category' => 'Custom Category', 'name' => 'Custom Skill', 'sequence' => 1]);
        app(TenantManager::class)->forget();

        $this->artisan('report-card:enable-skills-section', ['slug' => $school->slug, '--no-seed-catalog' => true])->assertExitCode(0);

        app(TenantManager::class)->use($school);
        $this->assertSame(1, ReportCardSkillArea::count());
        app(TenantManager::class)->forget();
    }

    /**
     * The class-level extension (user directive 2026-09-21: "one can
     * tick the section or classes the report card is meant to be for").
     * A class's own override wins over its section's, which itself
     * would otherwise win over the school default.
     */
    public function test_a_classs_own_template_override_wins_over_its_section_and_the_school_default(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'classoverrideone');
        ['section' => $section, 'class' => $class, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        app(ReportCardPreferences::class)->setTemplate('classic');
        SchoolSectionBranding::create(['section_id' => $section->id, 'report_card_template' => 'vibrant']);
        $class->update(['report_card_template' => 'bordered']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}")
            ->assertOk()
            ->assertSee('rc-sheet rc-bordered', false)
            ->assertDontSee('rc-sheet rc-vibrant', false);
    }

    /**
     * A class's own watermark choice is genuinely tri-state: here it
     * explicitly opts OUT even though its own section turned the
     * watermark on for every class in it by default.
     */
    public function test_a_class_can_opt_out_of_its_sections_own_watermark(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'classoverridetwo');
        ['section' => $section, 'class' => $class, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        $school->logo_path = 'schools/1/logo.png';
        $school->save();
        SchoolSectionBranding::create(['section_id' => $section->id, 'watermark_enabled' => true]);
        $class->update(['watermark_enabled' => false]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}")
            ->assertOk()
            ->assertDontSee('class="rc-watermark"', false);
    }

    public function test_the_ppms_command_gives_the_other_sections_the_same_look_but_not_the_narrative_style(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'ppmslooktest');
        ['section' => $nursery] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        $secondary = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        SchoolSectionBranding::create(['section_id' => $secondary->id, 'field_overrides' => ['principal_remark' => true]]);
        app(TenantManager::class)->forget();

        $this->artisan('ppms:setup-report-card', ['--slug' => $school->slug])->assertExitCode(0);

        app(TenantManager::class)->use($school);
        $other = SchoolSectionBranding::where('section_id', $secondary->id)->first();
        $this->assertSame('evaluation', $other->report_card_template);
        $this->assertNull($other->narrative_style);
        $this->assertTrue($other->watermark_enabled);
        $this->assertTrue($other->tracks_physical_stats_start);
        // Added to, never replaced.
        $this->assertEquals([
            'principal_remark' => true, 'physical_stats' => true, 'stamp_signature' => true,
            'behavioural_traits' => true, 'overall_remark' => false, 'subject_groups' => false,
        ], $other->field_overrides);
        // The nursery section keeps the narrative style and no scored template of its own.
        $nurseryBranding = SchoolSectionBranding::where('section_id', $nursery->id)->first();
        $this->assertSame('skills', $nurseryBranding->narrative_style);
        $this->assertNull($nurseryBranding->report_card_template);
        app(TenantManager::class)->forget();

        // --nursery-only leaves other sections alone.
        $this->artisan('ppms:setup-report-card', ['--slug' => $school->slug, '--nursery-only' => true])->assertExitCode(0);
    }

    public function test_the_grouped_cognitive_domain_and_the_two_domain_grids_render_from_subject_groups_and_trait_domains(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'basiclayoutone');
        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Basic', 'sequence' => 2]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Basic 2', 'sequence' => 1]);
        $english = \App\Models\Tenant\Subject::create(['name' => 'English Language', 'category' => 'Languages', 'sequence' => 1]);
        $maths = \App\Models\Tenant\Subject::create(['name' => 'Mathematics', 'category' => 'Maths & Sciences', 'sequence' => 2]);
        $class->subjects()->attach([$english->id => ['is_mandatory' => true, 'is_examinable' => true], $maths->id => ['is_mandatory' => true, 'is_examinable' => true]]);
        $session = AcademicSession::create(['name' => '2025/2026']);
        Term::create(['academic_session_id' => $session->id, 'name' => 'First Term 2025/2026', 'sequence' => 1])->makeCurrent();
        \App\Models\Tenant\AssessmentComponent::create(['name' => 'Test 1', 'type' => 'ca', 'max_score' => 20, 'sequence' => 1]);
        \App\Models\Tenant\AssessmentComponent::create(['name' => 'Exam', 'type' => 'exam', 'max_score' => 80, 'sequence' => 2]);
        \App\Models\Tenant\BehaviouralTrait::create(['name' => 'Sports', 'type' => 'remark', 'domain' => 'psychomotor', 'sequence' => 1]);
        \App\Models\Tenant\BehaviouralTrait::create(['name' => 'Punctuality', 'type' => 'remark', 'domain' => 'affective', 'sequence' => 2]);
        \App\Models\Tenant\BehaviouralTrait::create(['name' => 'Honesty', 'type' => 'remark', 'sequence' => 3]);
        SchoolSectionBranding::create(['section_id' => $section->id, 'report_card_template' => 'evaluation', 'field_overrides' => ['behavioural_traits' => true]]);
        app(\App\Services\ReportCards\ReportCardLayout::class)->setSectionOverride($section, ['enabled' => false, 'body_order' => ['cognitive_domain', 'skills_domains']]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview/class/{$class->id}")
            ->assertOk()
            ->assertSee('Cognitive Domain')
            ->assertSee('Marks Obtainable')
            // Average of the subject totals across the subjects offered: (57 + 64) / 2, no percent sign.
            ->assertSee('<strong>60.5</strong>', false)
            ->assertDontSee('60.5%')
            ->assertSee('Test 1')
            ->assertSee('English Language')
            ->assertSee('<span>Languages</span>', false)
            ->assertSee('<span>Maths &amp; Sciences</span>', false)
            ->assertSee('Psychomotor Domain')
            ->assertSee('Affective Domain')
            ->assertSee('Sports')
            ->assertSee('Punctuality')
            // A trait with no domain is not part of the two domain grids.
            ->assertDontSee('Honesty');
    }

    public function test_teachers_rate_psychomotor_and_affective_traits_from_the_schools_own_words_by_screen_or_template(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'domainentryone');
        ['class' => $class, 'term' => $term, 'student' => $student] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        \App\Models\Tenant\BehaviouralTrait::create(['name' => 'Punctuality', 'type' => 'remark', 'domain' => 'affective', 'sequence' => 1]);
        $sports = \App\Models\Tenant\BehaviouralTrait::create(['name' => 'Sports', 'type' => 'remark', 'domain' => 'psychomotor', 'sequence' => 2]);
        $honesty = \App\Models\Tenant\BehaviouralTrait::create(['name' => 'Honesty', 'type' => 'remark', 'sequence' => 3]);
        app(TenantManager::class)->forget();

        // The admin sets the words teachers pick from.
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/traits/rating-options", [
            'options' => "Fair\nGood\nVery Good\nExcellent\ngood",
        ])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantManager::class)->use($school);
        $this->assertSame(['Fair', 'Good', 'Very Good', 'Excellent'], \App\Support\TraitRemarkScale::options());
        app(TenantManager::class)->forget();

        // The entry screen: columns grouped under the two domains, dropdown from those words only.
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/entry?term_id={$term->id}")
            ->assertOk()
            ->assertSee('Psychomotor Domain')
            ->assertSee('Affective Domain')
            ->assertSee('<option value="Very Good"', false)
            ->assertDontSee('<option value="Weak"', false);

        // The bulk template: Psychomotor, then Affective, then the rest, labelled by domain.
        $template = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/entry/template?term_id={$term->id}")->assertOk();
        $header = strtok($template->streamedContent(), "\n");
        $this->assertStringContainsString('"Psychomotor: Sports","Affective: Punctuality",Honesty', $header);

        // Upload fills every student; the school's words match whatever the capitals, anything else is left out and reported.
        $csv = "Admission Number,Student Name,Height,Weight,Club,Psychomotor: Sports,Affective: Punctuality,Honesty,Overall Remark,Class Teacher Remark\n"
            ."{$student->admission_number},{$student->fullName()},,,,very good,EXCELLENT,Bogus,,\n";
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/entry/upload", [
            'term_id' => $term->id,
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('filled.csv', $csv),
        ])->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, '1 rating(s) were left out'));

        app(TenantManager::class)->use($school);
        $rating = fn ($traitId) => \App\Models\Tenant\StudentBehaviouralRating::where('student_id', $student->id)->where('term_id', $term->id)->where('behavioural_trait_id', $traitId)->value('value');
        $this->assertSame('Very Good', $rating($sports->id));
        $this->assertNull($rating($honesty->id));
        app(TenantManager::class)->forget();
    }

    public function test_the_cognitive_table_lists_mandatory_subjects_but_only_scored_optional_ones_and_can_drop_the_group_labels(): void
    {
        $data = [
            'component_columns' => ['Test 1', 'Exam'],
            'component_max' => ['Test 1' => 20.0, 'Exam' => 80.0],
            'offered_subjects' => [
                ['name' => 'English Language', 'category' => 'Languages', 'mandatory' => true],
                ['name' => 'Physics', 'category' => null, 'mandatory' => false],
                ['name' => 'Biology', 'category' => null, 'mandatory' => false],
            ],
            'subjects' => [
                ['name' => 'Physics', 'category' => null, 'components' => ['Test 1' => 15, 'Exam' => 60], 'total' => 75, 'grade' => 'A', 'remark' => 'Excellent', 'class_average' => 60],
            ],
            'overall' => ['average' => 0],
        ];

        $grouped = view('school.report-cards._body-cognitive_domain', ['data' => $data, 'show' => fn ($f) => true])->render();
        $this->assertStringContainsString('English Language', $grouped);
        $this->assertStringContainsString('Physics', $grouped);          // optional but this student has a score
        $this->assertStringNotContainsString('Biology', $grouped);       // optional and never taken
        $this->assertStringContainsString('<span>Languages</span>', $grouped);
        // (0 + 75) / 2 listed subjects, a plain number
        $this->assertStringContainsString('<strong>37.5</strong>', $grouped);
        $this->assertStringNotContainsString('37.5%', $grouped);

        $plain = view('school.report-cards._body-cognitive_domain', ['data' => $data, 'show' => fn ($f) => $f !== 'subject_groups'])->render();
        $this->assertStringNotContainsString('<span>Languages</span>', $plain);
        $this->assertStringContainsString('English Language', $plain);
    }

    public function test_the_generic_sample_carries_groups_and_domains_so_the_grouped_sections_still_show_something_full(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'genericsampleone');
        app(TenantManager::class)->use($school);
        $data = app(\App\Services\ReportCards\ReportCardService::class)->sample();
        app(TenantManager::class)->forget();

        $this->assertSame(['Languages', 'Maths & Sciences', 'Maths & Sciences', 'Arts & Culture'], array_column($data['offered_subjects'], 'category'));
        $this->assertSame(['First CA' => 20.0, 'Second CA' => 20.0, 'Exam' => 60.0], $data['component_max']);
        $domains = array_unique(array_column($data['traits'], 'domain'));
        sort($domains);
        $this->assertSame(['affective', 'psychomotor'], $domains);
    }

    public function test_the_ppms_command_makes_nursery_1_and_2_scored_while_the_kg_classes_stay_narrative(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ppmsnurseryscored');
        ['section' => $nursery, 'class' => $kg, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        $n1 = SchoolClass::create(['section_id' => $nursery->id, 'name' => 'Nursery 1', 'sequence' => 2]);
        ClassNarrativeTerm::create(['school_class_id' => $n1->id, 'term_id' => $term->id]);
        app(TenantManager::class)->forget();

        $this->artisan('ppms:setup-report-card', ['--slug' => $school->slug])->assertExitCode(0);

        app(TenantManager::class)->use($school);
        $n1 = SchoolClass::find($n1->id);
        $this->assertFalse($n1->isNarrativeEvaluationForTerm($term->id));
        $this->assertSame('evaluation', $n1->report_card_template);
        $this->assertSame(['physical_stats', 'cognitive_domain', 'skills_domains', 'remarks'], $n1->layout_override['body_order']);
        $this->assertFalse($n1->field_overrides['subject_groups']);
        // The KG class in the same section keeps its narrative setup.
        $this->assertTrue(SchoolClass::find($kg->id)->isNarrativeEvaluationForTerm($term->id));
        $this->assertNull(SchoolClass::find($kg->id)->report_card_template);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview/class/{$n1->id}")
            ->assertOk()
            ->assertSee('Cognitive Domain')
            ->assertDontSee('Key Assessment Areas');
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview/class/{$kg->id}")
            ->assertOk()
            ->assertDontSee('Cognitive Domain');
    }

    public function test_the_ppms_command_sets_up_the_basic_section_with_the_grouped_layout_and_domain_traits(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'ppmsbasictest');
        $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        $basic = Section::create(['name' => 'Basic', 'sequence' => 2]);
        $secondary = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        \App\Models\Tenant\BehaviouralTrait::create(['name' => 'punctuality', 'type' => 'remark', 'sequence' => 1]);
        app(TenantManager::class)->forget();

        $this->artisan('ppms:setup-report-card', ['--slug' => $school->slug])->assertExitCode(0);

        app(TenantManager::class)->use($school);
        $b = SchoolSectionBranding::where('section_id', $basic->id)->first();
        $this->assertSame('evaluation', $b->report_card_template);
        $this->assertFalse($b->field_overrides['overall_remark']);
        $this->assertTrue($b->field_overrides['behavioural_traits']);
        $this->assertSame(['physical_stats', 'cognitive_domain', 'skills_domains', 'remarks'], $b->layout_override['body_order']);
        // Secondary gets the same layout (their own subjects), but without Basic's side group labels.
        $this->assertTrue($b->field_overrides['subject_groups']);
        $s = SchoolSectionBranding::where('section_id', $secondary->id)->first();
        $this->assertSame(['physical_stats', 'cognitive_domain', 'skills_domains', 'remarks'], $s->layout_override['body_order']);
        $this->assertFalse($s->field_overrides['subject_groups']);
        $this->assertSame('evaluation', $s->report_card_template);
        // 16 traits, and the one the school already had is reused, not duplicated.
        $this->assertSame(16, \App\Models\Tenant\BehaviouralTrait::count());
        $this->assertSame('affective', \App\Models\Tenant\BehaviouralTrait::where('name', 'punctuality')->value('domain'));
        $this->assertSame(6, \App\Models\Tenant\BehaviouralTrait::where('domain', 'psychomotor')->count());
        app(TenantManager::class)->forget();
    }

    public function test_the_class_preview_shows_a_sample_report_card_reflecting_the_classs_own_resolved_look(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'classpreviewone');
        ['section' => $section, 'class' => $class, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        ClassNarrativeTerm::create(['school_class_id' => $class->id, 'term_id' => $term->id]);
        SchoolSectionBranding::create(['section_id' => $section->id, 'narrative_style' => 'skills', 'watermark_enabled' => true]);
        $school->logo_path = 'schools/1/logo.png';
        $school->save();
        ReportCardSkillArea::create(['category' => 'Literacy & Phonics', 'name' => 'Letter Recognition & Sound', 'sequence' => 1]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview/class/{$class->id}")
            ->assertOk()
            ->assertSee('Key Assessment Areas')
            ->assertSee('Literacy &amp; Phonics', false)
            // A filled-out illustrative sample remark (user directive
            // 2026-09-22: "for the preview sample, add sample CLASS
            // TEACHER'S REMARK too"), not the empty "Not yet entered."
            // state a genuinely unscored real report would show.
            ->assertSee('Shows strong progress in this area.')
            ->assertSee('class="rc-watermark"', false)
            ->assertSee($class->name);
    }

    public function test_an_admin_can_preview_any_class(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'classpreviewtwo');
        ['class' => $class] = $this->seedOneClass($school);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview/class/{$class->id}")
            ->assertOk();
    }

    public function test_a_class_teacher_can_only_preview_their_own_class(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'classpreviewthree');
        ['class' => $ownClass, 'classArm' => $ownArm] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        $otherSection = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        $otherClass = SchoolClass::create(['section_id' => $otherSection->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $otherArm = ClassArm::create(['school_class_id' => $otherClass->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);
        $staff = \App\Models\Tenant\Staff::create(['staff_id' => 'T1', 'first_name' => 'Tade', 'last_name' => 'Teacher']);
        $ownArm->update(['class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        $teacherUser = User::factory()->create();
        $teacherRole = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($teacherUser->id, ['role_id' => $teacherRole->id, 'status' => 'active']);
        app(TenantManager::class)->use($school);
        \App\Models\Tenant\Staff::where('id', $staff->id)->update(['user_id' => $teacherUser->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/report-cards/preview/class/{$ownClass->id}")
            ->assertOk();

        $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/report-cards/preview/class/{$otherClass->id}")
            ->assertNotFound();
    }

    public function test_an_admin_can_save_a_class_level_override_and_it_appears_pre_selected(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'classoverridethree');
        ['class' => $class] = $this->seedOneClass($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/overrides", [
            'report_card_template' => 'bordered',
            'narrative_style' => 'skills',
            'watermark_enabled' => '1',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = SchoolClass::find($class->id);
        app(TenantManager::class)->forget();

        $this->assertSame('bordered', $fresh->report_card_template);
        $this->assertSame('skills', $fresh->narrative_style);
        $this->assertTrue($fresh->watermark_enabled);
    }

    /**
     * Beginning/End of Term tracking resolved class, then section, then
     * the school wide Setting (user directive 2026-09-22, confirmed via
     * AskUserQuestion: "Make it per-section" -- so PPMS's Nursery can
     * turn this on without Primary/Secondary's own report cards also
     * switching over).
     */
    public function test_beginning_end_tracking_resolves_class_then_section_then_school(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'physicalscope');
        ['section' => $section, 'class' => $class] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        $prefs = app(ReportCardPreferences::class);
        // School wide default off, section turns it on, this class then
        // opts back out -- proves every level of the chain actually
        // takes effect, not just the top or bottom of it.
        $this->assertFalse($prefs->tracksPhysicalStatsStartFor($class));
        SchoolSectionBranding::create(['section_id' => $section->id, 'tracks_physical_stats_start' => true]);
        $this->assertTrue($prefs->tracksPhysicalStatsStartFor(SchoolClass::find($class->id)));
        $class->update(['tracks_physical_stats_start' => false]);
        $this->assertFalse($prefs->tracksPhysicalStatsStartFor(SchoolClass::find($class->id)));
        app(TenantManager::class)->forget();
    }

    public function test_a_section_can_turn_on_beginning_end_tracking_via_the_settings_form(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'physicalsectionform');
        ['section' => $section, 'class' => $class] = $this->seedOneClass($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/sections/{$section->id}/overrides", [
            'tracks_physical_stats_start' => '1',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $resolved = app(ReportCardPreferences::class)->tracksPhysicalStatsStartFor(SchoolClass::find($class->id));
        app(TenantManager::class)->forget();

        $this->assertTrue($resolved);
    }

    /**
     * Physical & Attendance and Stamp/Signature made per-section (user
     * directive 2026-09-22, confirmed via AskUserQuestion: "Make these
     * per-section too" -- turning Physical & Attendance on for one
     * section must not also turn it on for a school that never touches
     * this section's own override).
     */
    public function test_a_section_can_override_physical_attendance_and_stamp_signature_fields(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'fieldoverrideone');
        ['section' => $section, 'class' => $class] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        $prefs = app(ReportCardPreferences::class);
        // School wide default is off for both -- confirms the override
        // genuinely ADDS visibility, not just reflects an already-on default.
        $this->assertFalse($prefs->showsFor('physical_stats', SchoolClass::find($class->id)));
        $this->assertFalse($prefs->showsFor('stamp_signature', SchoolClass::find($class->id)));
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/sections/{$section->id}/field-overrides", [
            'physical_stats' => '1',
            'stamp_signature' => '1',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $classFresh = SchoolClass::find($class->id);
        $this->assertTrue($prefs->showsFor('physical_stats', $classFresh));
        $this->assertTrue($prefs->showsFor('stamp_signature', $classFresh));
        app(TenantManager::class)->forget();
    }

    public function test_a_second_school_is_unaffected_by_another_schools_field_overrides(): void
    {
        [$schoolA, $ownerA] = $this->schoolWithMember('school-owner', 'fieldoverridea');
        ['section' => $sectionA] = $this->seedOneClass($schoolA);
        [$schoolB] = $this->schoolWithMember('school-owner', 'fieldoverrideb');
        ['class' => $classB] = $this->seedOneClass($schoolB);

        $this->actingAs($ownerA)->post("http://{$schoolA->slug}.localhost/report-cards/sections/{$sectionA->id}/field-overrides", [
            'physical_stats' => '1',
        ])->assertRedirect();

        app(TenantManager::class)->use($schoolB);
        $stillOff = app(ReportCardPreferences::class)->showsFor('physical_stats', SchoolClass::find($classB->id));
        app(TenantManager::class)->forget();

        $this->assertFalse($stillOff);
    }

    public function test_a_sections_physical_stats_override_actually_shows_on_the_real_report_card(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'fieldoverridecard');
        ['section' => $section, 'class' => $class, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}")
            ->assertOk()
            ->assertDontSee('Attendance Record');

        app(TenantManager::class)->use($school);
        SchoolSectionBranding::updateOrCreate(['section_id' => $section->id], ['field_overrides' => ['physical_stats' => true]]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}")
            ->assertOk()
            ->assertSee('Attendance Record');
    }

    /**
     * The bordered-table redesign and "Comment" wording apply to every
     * template (user directive 2026-09-22, confirmed via AskUserQuestion:
     * "Every template" over narrative only) -- a plain Classic template
     * class, no narrative involved at all, gets the same treatment.
     */
    public function test_every_template_shows_the_bordered_remarks_table_not_just_narrative(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'borderedeverytemplate');
        ['class' => $class, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        app(ReportCardPreferences::class)->setTemplate('classic');
        app(ReportCardPreferences::class)->setFields(['class_teacher_remark' => true]);
        \App\Models\Tenant\StudentTermRemark::create([
            'student_id' => $student->id, 'term_id' => $term->id, 'class_teacher_remark' => 'Great progress this term.',
        ]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}");
        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('class="rc-bordered-table rc-remarks-table"', $html);
        $this->assertStringContainsString("<b>Class Teacher's Comment:</b> Great progress this term.", $html);
    }

    /**
     * Live user report 2026-09-21: an admin went to the "Report Card
     * Designer" (the drag-and-drop Layout Designer, /report-cards/
     * settings/layout) expecting to find the class-specific design
     * built above, and instead saw the old generic "Ada Grace Obi /
     * JSS 2 A" sample with none of PPMS's own Trimester wording or Key
     * Assessment Areas. This class picks that same class through the
     * Layout Designer and confirms the whole live preview re-renders
     * with its real resolved narrative style/skill areas.
     */
    public function test_the_layout_designer_reflects_a_picked_classs_own_resolved_look(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'designerpreviewone');
        ['section' => $section, 'class' => $class, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        ClassNarrativeTerm::create(['school_class_id' => $class->id, 'term_id' => $term->id]);
        SchoolSectionBranding::create(['section_id' => $section->id, 'narrative_style' => 'skills']);
        ReportCardSkillArea::create(['category' => 'Literacy & Phonics', 'name' => 'Letter Recognition & Sound', 'sequence' => 1]);
        app(TenantManager::class)->forget();

        // Without a class picked, the page still renders the old generic default.
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/settings/layout")
            ->assertOk()
            ->assertSee('JSS 2 A');

        // With this class picked, it reflects the class's own real look.
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/settings/layout?classId={$class->id}")
            ->assertOk()
            ->assertSee('Key Assessment Areas')
            ->assertSee('Literacy &amp; Phonics', false)
            ->assertSee($class->name);
    }

    public function test_a_class_teacher_only_sees_their_own_class_in_the_layout_designer_picker(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'designerpreviewtwo');
        ['class' => $ownClass, 'classArm' => $ownArm] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        $otherSection = Section::create(['name' => 'Secondary', 'sequence' => 3]);
        $otherClass = SchoolClass::create(['section_id' => $otherSection->id, 'name' => 'JSS 1', 'sequence' => 1]);
        ClassArm::create(['school_class_id' => $otherClass->id, 'arm_id' => Arm::create(['name' => 'B'])->id]);
        $staff = \App\Models\Tenant\Staff::create(['staff_id' => 'T1', 'first_name' => 'Tade', 'last_name' => 'Teacher']);
        $ownArm->update(['class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        $teacherUser = User::factory()->create();
        $teacherRole = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($teacherUser->id, ['role_id' => $teacherRole->id, 'status' => 'active']);
        app(TenantManager::class)->use($school);
        \App\Models\Tenant\Staff::where('id', $staff->id)->update(['user_id' => $teacherUser->id]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($teacherUser)->get("http://{$school->slug}.localhost/report-cards/settings/layout");
        $response->assertOk()
            ->assertSee($ownClass->name)
            ->assertDontSee($otherClass->name);
    }

    public function test_a_narrative_report_cards_identity_strip_includes_gender_and_session(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'narrativeidone');
        ['class' => $class, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        ClassNarrativeTerm::create(['school_class_id' => $class->id, 'term_id' => $term->id]);
        $student->update(['gender' => 'Male']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}")
            ->assertOk()
            ->assertSee('<b>Gender:</b>', false)
            ->assertSee('<b>Session:</b>', false);
    }

    /**
     * Live user report 2026-09-21, screenshot of a preview: the header
     * read "THIRD TERM REPORT 2025/2026" while the school's real
     * current session/term was something else entirely, "the preview
     * should match the current term."
     */
    public function test_the_preview_shows_the_schools_own_real_current_term_not_a_hardcoded_one(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'previewtermone');
        ['class' => $class] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2030/2031']);
        $realTerm = Term::create(['academic_session_id' => $session->id, 'name' => 'Second Term 2030/2031', 'sequence' => 2]);
        $realTerm->makeCurrent();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview/class/{$class->id}")
            ->assertOk()
            ->assertSee('Second Term 2030/2031')
            ->assertDontSee('Third Term');
    }

    /**
     * Live user report 2026-09-21: "remmber that the creche, reception
     * and KG classes of nursery does not use student image on the
     * report card" -- matches the reference report card's own header,
     * which has no photo box at all.
     */
    public function test_a_narrative_report_card_never_shows_a_student_photo_even_when_the_field_is_on(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'narrativephotoone');
        ['class' => $class, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        ClassNarrativeTerm::create(['school_class_id' => $class->id, 'term_id' => $term->id]);
        app(ReportCardPreferences::class)->setFields(['student_photo_header' => true, 'student_photo_avatar_fallback' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}")
            ->assertOk()
            ->assertDontSee('class="rc-photo"', false);
    }

    /**
     * The real broken-logo bug, live user report 2026-09-21 with the
     * actual broken URL: https://skulosapp.com/storage/schools/10/...
     * (the platform's own bare root domain, not the school's real
     * subdomain -- APP_URL is one static, non-tenant-aware value).
     * TenantAsset::url() must build the URL against THIS school's own
     * subdomain instead, the same way School::portalUrl() already does
     * for emailed links.
     */
    public function test_the_preview_logo_url_uses_the_schools_own_subdomain_not_the_bare_app_url(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'logourlone');
        ['class' => $class] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        $school->logo_path = 'schools/'.$school->id.'/logo.png';
        $school->save();
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview/class/{$class->id}");
        $response->assertOk();
        $response->assertSee("http://{$school->slug}.localhost/storage/schools/{$school->id}/logo.png", false);
        $response->assertDontSee('http://localhost/storage/', false);
    }

    /**
     * Live user report 2026-09-21, side-by-side screenshot against the
     * reference report card: the narrative identity area showed Gender
     * twice (once in the new top strip, once still in the untouched
     * .rc-id grid below it) plus admission number/date of birth/house/
     * class teacher/attendance, none of which the reference's own
     * identity row has. "I want it to be exact match."
     */
    public function test_a_narrative_report_cards_identity_area_shows_exactly_name_gender_class_session_once_each(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'narrativeidtwo');
        ['class' => $class, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        ClassNarrativeTerm::create(['school_class_id' => $class->id, 'term_id' => $term->id]);
        $student->update(['gender' => 'Male']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}");
        $response->assertOk();
        $html = $response->getContent();
        // The identity row is now a real bordered table (user directive
        // 2026-09-22, "NAME: ... GENDER: ..." exactly as the reference
        // shows it), label + colon inline, not the old stacked
        // label-above-value card.
        $this->assertSame(1, substr_count($html, '<b>Gender:</b>'));
        $this->assertStringNotContainsString('<b>Admission No.</b>', $html);
        $this->assertStringNotContainsString('<b>Date of Birth</b>', $html);
        $this->assertStringNotContainsString('<b>House</b>', $html);
        $this->assertStringNotContainsString('<b>Class Teacher</b>', $html);
    }

    public function test_the_class_report_cards_screen_shows_a_preview_link(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'classindexpreview');
        ['class' => $class, 'term' => $term] = $this->seedOneClass($school);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/classes/{$class->id}?term_id={$term->id}")
            ->assertOk()
            ->assertSee(route('school.report-cards.preview.class', ['school' => $school->slug, 'classId' => $class->id]));
    }

    /**
     * "Overall Remarks", confirmed via AskUserQuestion 2026-09-21: "Add a
     * real third field (recommended for an exact match)" -- its own
     * distinct line, not the same value as Class Teacher's Remark, shown
     * on the entry screen, saved on submit, and rendered on the card.
     */
    public function test_overall_remark_is_a_real_field_distinct_from_class_teacher_remark(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'overallremarkone');
        ['class' => $class, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/entry?term_id={$term->id}")
            ->assertOk()
            ->assertSee('Overall remark');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/entry", [
            'term_id' => $term->id,
            'students' => [
                $student->id => [
                    'overall_remark' => 'A well rounded term overall.',
                    'class_teacher_remark' => 'Keep up the good work in class.',
                ],
            ],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $remark = \App\Models\Tenant\StudentTermRemark::where('student_id', $student->id)->where('term_id', $term->id)->first();
        app(TenantManager::class)->forget();

        $this->assertSame('A well rounded term overall.', $remark->overall_remark);
        $this->assertSame('Keep up the good work in class.', $remark->class_teacher_remark);

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}");
        $response->assertOk();
        $html = $response->getContent();
        // The new bordered-table remarks markup (user directive
        // 2026-09-22, every template): label + colon inline with the
        // value in a table cell, "Comment" not "Remark".
        $this->assertStringContainsString('<b>Overall Remarks:</b> A well rounded term overall.', $html);
        $this->assertStringContainsString("<b>Class Teacher's Comment:</b> Keep up the good work in class.", $html);
    }

    /**
     * Physical stats beginning/end of term tracking, confirmed via
     * AskUserQuestion 2026-09-21: "option 2, also add it to what admin
     * can enable if they want the stat to have begining of term and end
     * of term or just one to cover it" -- off by default (a plain single
     * Height/Weight row, unaffected), a real admin toggle turns on
     * separate Beginning/End columns everywhere the stat is entered or
     * shown.
     */
    public function test_physical_stats_beginning_and_end_of_term_tracking_is_off_by_default(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'physicalstartoff');
        ['class' => $class, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        $this->assertFalse(app(ReportCardPreferences::class)->tracksPhysicalStatsStart());
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/entry?term_id={$term->id}")
            ->assertOk()
            ->assertSee('data-label="Height"', false)
            ->assertDontSee('Height (start)');

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview/class/{$class->id}");
        $response->assertOk();
        $response->assertDontSee('Beginning of Term');
    }

    /**
     * The admin facing toggle itself (Report Settings > Physical &
     * Attendance): a real gap found and fixed after first shipping only
     * the setTracksPhysicalStatsStart() plumbing with no way for an
     * admin to actually reach it from the UI.
     */
    public function test_an_admin_can_toggle_physical_stats_tracking_from_report_settings(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'physicalstarttoggle');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/settings")
            ->assertOk()
            ->assertSee('Track Height and Weight as Beginning of Term and End of Term');

        app(TenantManager::class)->use($school);
        $this->assertFalse(app(ReportCardPreferences::class)->tracksPhysicalStatsStart());
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/physical-stats-tracking", ['enabled' => '1'])
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertTrue(app(ReportCardPreferences::class)->tracksPhysicalStatsStart());
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/physical-stats-tracking", ['enabled' => '0'])
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertFalse(app(ReportCardPreferences::class)->tracksPhysicalStatsStart());
        app(TenantManager::class)->forget();
    }

    public function test_an_admin_can_turn_on_beginning_and_end_of_term_physical_tracking(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'physicalstarton');
        ['class' => $class, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        app(ReportCardPreferences::class)->setTracksPhysicalStatsStart(true);
        // physical_stats itself is off by default (config('skulos.
        // report_card_fields')) -- turned on here purely so the card
        // render assertions below have something to actually check.
        app(ReportCardPreferences::class)->setFields(['physical_stats' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/entry?term_id={$term->id}")
            ->assertOk()
            ->assertSee('data-label="Height (start)"', false)
            ->assertSee('data-label="Height (end)"', false)
            ->assertSee('data-label="Weight (start)"', false)
            ->assertSee('data-label="Weight (end)"', false);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/entry", [
            'term_id' => $term->id,
            'students' => [
                $student->id => [
                    'height_start' => '110cm',
                    'height' => '113cm',
                    'weight_start' => '17kg',
                    'weight' => '19kg',
                ],
            ],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $remark = \App\Models\Tenant\StudentTermRemark::where('student_id', $student->id)->where('term_id', $term->id)->first();
        app(TenantManager::class)->forget();

        $this->assertSame('110cm', $remark->height_start);
        $this->assertSame('113cm', $remark->height);
        $this->assertSame('17kg', $remark->weight_start);
        $this->assertSame('19kg', $remark->weight);

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}");
        $response->assertOk();
        // The new bordered-table layout (user directive 2026-09-22),
        // every template: column headers, not one combined label.
        $response->assertSee('Beginning of Term');
        $response->assertSee('End of Term');
        $response->assertSee('110cm');
        $response->assertSee('113cm');
    }

    /**
     * The bulk CSV round trip mirrors the on-screen table exactly, per
     * its own existing docblock -- confirms the new Overall Remark and
     * (when the toggle is on) beginning/end physical columns round trip
     * through a real download-then-upload cycle, not just the on-screen
     * form.
     */
    public function test_the_class_entry_csv_template_round_trips_overall_remark_and_physical_start(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'csvphysicalstart');
        ['class' => $class, 'student' => $student, 'term' => $term] = $this->seedOneClass($school);

        app(TenantManager::class)->use($school);
        app(ReportCardPreferences::class)->setTracksPhysicalStatsStart(true);
        app(TenantManager::class)->forget();

        $csv = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/entry/template?term_id={$term->id}")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Height (Start)', $csv);
        $this->assertStringContainsString('Overall Remark', $csv);

        $row = "{$student->admission_number},{$student->fullName()},108cm,112cm,16kg,18kg,,A great term overall.,Doing well in class.\n";
        $body = "Admission Number,Student Name,Height (Start),Height (End),Weight (Start),Weight (End),Club,Overall Remark,Class Teacher Remark\n".$row;
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('entry.csv', $body);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/entry/upload", [
            'term_id' => $term->id,
            'file' => $file,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $remark = \App\Models\Tenant\StudentTermRemark::where('student_id', $student->id)->where('term_id', $term->id)->first();
        app(TenantManager::class)->forget();

        $this->assertSame('108cm', $remark->height_start);
        $this->assertSame('112cm', $remark->height);
        $this->assertSame('16kg', $remark->weight_start);
        $this->assertSame('18kg', $remark->weight);
        $this->assertSame('A great term overall.', $remark->overall_remark);
        $this->assertSame('Doing well in class.', $remark->class_teacher_remark);
    }
}
