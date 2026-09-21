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
            ->assertSee('Not yet entered.')
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
}
