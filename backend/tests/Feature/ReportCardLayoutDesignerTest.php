<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Layout Designer rebuilt 2026-07-18 (user feedback: "the report card
 * designer is poorly done, rebuild using the report card templates as
 * idea. also there should be a live preview of how it will look like
 * which is where all the items should also be dragged to or remove").
 * The designer now renders the exact same block partial the real report
 * card uses (school.report-cards._layout-block), with sample data, so
 * what shows here is the real markup, not a mockup.
 */
class ReportCardLayoutDesignerTest extends TestCase
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

    public function test_the_designer_shows_a_live_preview_using_real_sample_data(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layoutone');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/settings/layout")
            ->assertOk()
            ->assertSee('rc-sheet', false)
            ->assertSee('Ada Grace Obi')
            ->assertSee('ADM/2025/014');
    }

    public function test_the_designer_loads_pre_populated_with_the_default_layout(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layouttwo');

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/settings/layout");

        $response->assertOk()->assertSee('data-block="school_name"', false)->assertSee('data-block="student_name"', false);
    }

    public function test_saving_a_layout_still_works_after_the_rebuild(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layoutthree');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'enabled' => '1',
            'header' => ['school_name'],
            'info' => ['student_name', 'class'],
        ])->assertRedirect();

        $again = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/settings/layout");
        $again->assertOk()->assertSee('checked', false);
    }

    public function test_the_designer_shows_body_sections_and_the_available_sections_panel(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layoutfive');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/settings/layout")
            ->assertOk()
            ->assertSee('data-section="subjects_table"', false)
            ->assertSee('data-section="signoff"', false)
            ->assertSee('Available sections');
    }

    public function test_saving_a_layout_persists_header_alignment_and_body_section_order(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layoutsix');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'enabled' => '1',
            'header' => ['logo', 'school_name'],
            'header_align' => ['logo' => 'left', 'school_name' => 'right'],
            'info' => ['student_name'],
            'body' => ['signoff', 'summary'],
        ])->assertRedirect();

        $layout = app(\App\Services\ReportCards\ReportCardLayout::class);
        $this->assertEquals(['logo' => 'left', 'school_name' => 'right'], $layout->headerAlign());
        $this->assertEquals(['signoff', 'summary'], $layout->bodyOrder());
    }

    public function test_a_narrow_body_order_actually_reorders_the_real_report_card(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layoutseven');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'enabled' => '1',
            'header' => ['school_name'],
            'info' => ['student_name'],
            'body' => ['signoff'],
        ])->assertRedirect();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview");
        $response->assertOk()->assertDontSee('Total Score');
    }

    /**
     * User feedback 2026-07-19: "all these items should be able to move
     * to left, right, or central on the report card page but current
     * one does not allow this" — extends the same per-block align-self
     * mechanism from the header to whole body sections.
     */
    public function test_saving_a_layout_persists_body_section_alignment_and_it_shows_on_the_real_card(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layouteight');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'enabled' => '1',
            'header' => ['school_name'],
            'info' => ['student_name'],
            'body' => ['signoff', 'remarks'],
            'body_align' => ['signoff' => 'center', 'remarks' => 'left'],
        ])->assertRedirect();

        $layout = app(\App\Services\ReportCards\ReportCardLayout::class);
        $this->assertEquals(['signoff' => 'center', 'remarks' => 'left'], $layout->bodyAlign());

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview")
            ->assertOk()
            ->assertSee('margin-left:auto;margin-right:auto', false);
    }

    /** User report 2026-07-19: "the behavioural section is not showing details or samples". */
    public function test_the_designer_shows_sample_content_for_a_section_even_when_its_field_is_switched_off(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layoutnine');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/settings/layout")
            ->assertOk()
            ->assertSee('Punctuality')
            ->assertSee('Excellent');
    }

    /** User request 2026-07-19: "add option to add text, charts etc to the report card... allow me allow something like address etc". */
    public function test_an_admin_can_create_a_custom_text_block_place_it_and_it_shows_on_the_real_card(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layoutten');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout/custom-blocks", [
            'label' => 'School Address', 'content' => '12 Freedom Way, Lekki, Lagos',
        ])->assertRedirect();

        $block = \App\Models\Tenant\ReportCardCustomBlock::first();
        $this->assertNotNull($block);
        $this->assertSame('School Address', $block->label);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/settings/layout")
            ->assertOk()
            ->assertSee('data-section="'.$block->bodyKey().'"', false)
            ->assertSee('12 Freedom Way, Lekki, Lagos');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'enabled' => '1',
            'header' => ['school_name'],
            'info' => ['student_name'],
            'body' => [$block->bodyKey()],
        ])->assertRedirect();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview")
            ->assertOk()
            ->assertSee('School Address')
            ->assertSee('12 Freedom Way, Lekki, Lagos');
    }

    public function test_deleting_a_custom_block_removes_it_from_a_saved_layout_too(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layouteleven');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout/custom-blocks", [
            'label' => 'Note', 'content' => 'Some text',
        ]);
        $block = \App\Models\Tenant\ReportCardCustomBlock::first();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'enabled' => '1', 'header' => [], 'info' => [], 'body' => [$block->bodyKey(), 'signoff'],
        ]);

        $this->actingAs($owner)->delete("http://{$school->slug}.localhost/report-cards/settings/layout/custom-blocks/{$block->id}")
            ->assertRedirect();

        $layout = app(\App\Services\ReportCards\ReportCardLayout::class);
        $this->assertEquals(['signoff'], $layout->bodyOrder());
    }

    /**
     * User request 2026-07-19, answered by AskUserQuestion: both a score
     * trend and a subject breakdown chart on the card. Originally Subject
     * Breakdown was a fixed bar chart regardless of chart_style; user
     * directive 2026-07-28 ("all - user can choose any they want")
     * extended the same chart_style toggle to it too, so under the
     * default 'line' style both charts now render as a line, not a mix
     * of line+bar — see the sibling test below for the 'bar' style.
     */
    public function test_score_trend_and_subject_breakdown_charts_render_on_the_real_card(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layouttwelve');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'enabled' => '1',
            'header' => ['school_name'],
            'info' => ['student_name'],
            'body' => ['score_trend', 'subject_chart'],
        ])->assertRedirect();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview");

        $response->assertOk()
            ->assertSee('Score Trend')
            ->assertSee('Subject Breakdown')
            ->assertSee('<svg', false)
            ->assertSee('<polyline', false)
            ->assertDontSee('<rect', false);
    }

    /** Sibling to the test above: chart_style='bar' switches both charts to bars together, since they now share one setting. */
    public function test_score_trend_and_subject_breakdown_charts_both_switch_to_bars_together(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layoutthirteen');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/branding", ['chart_style' => 'bar']);
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'enabled' => '1',
            'header' => ['school_name'],
            'info' => ['student_name'],
            'body' => ['score_trend', 'subject_chart'],
        ])->assertRedirect();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview");

        $response->assertOk()
            ->assertSee('Score Trend')
            ->assertSee('Subject Breakdown')
            ->assertSee('<rect', false)
            ->assertDontSee('<polyline', false);
    }

    public function test_a_non_report_card_viewer_cannot_reach_the_designer(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'layoutfour');

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/report-cards/settings/layout")
            ->assertForbidden();
    }

    protected function seedOneClass(School $school): array
    {
        app(TenantManager::class)->use($school);

        $section = \App\Models\Tenant\Section::create(['name' => 'Nursery', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'KG One', 'sequence' => 1]);
        $arm = \App\Models\Tenant\Arm::create(['name' => 'A']);
        $classArm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);

        app(TenantManager::class)->forget();

        return compact('section', 'class', 'classArm');
    }

    /**
     * A genuinely different report card block arrangement per class,
     * not just a different template/narrative style/watermark (user
     * directive 2026-09-21, confirmed via AskUserQuestion: "A genuinely
     * different arrangement per class/section (recommended if KG 1
     * really needs its own unique block order)").
     */
    public function test_saving_a_layout_for_a_class_does_not_touch_the_school_default(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'classlayoutone');
        ['class' => $class] = $this->seedOneClass($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'enabled' => '1',
            'header' => ['school_name'],
            'info' => ['student_name'],
            'body' => ['signoff', 'summary'],
        ])->assertRedirect();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'target_scope' => 'class',
            'target_id' => $class->id,
            'enabled' => '1',
            'header' => ['logo'],
            'info' => ['class'],
            'body' => ['remarks'],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $layout = app(\App\Services\ReportCards\ReportCardLayout::class);
        $schoolDefault = $layout->schoolDefaultSnapshot();
        $classFresh = \App\Models\Tenant\SchoolClass::find($class->id);
        $classLook = $layout->resolvedFor($classFresh);
        app(TenantManager::class)->forget();

        $this->assertSame(['signoff', 'summary'], $schoolDefault['body_order']);
        $this->assertSame(['remarks'], $classLook['body_order']);
        $this->assertTrue($layout->classHasOwnOverride($classFresh));
    }

    public function test_a_class_with_no_override_inherits_its_sections_own_layout(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'classlayouttwo');
        ['section' => $section, 'class' => $class] = $this->seedOneClass($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'target_scope' => 'section',
            'target_id' => $section->id,
            'enabled' => '1',
            'header' => ['school_name'],
            'info' => ['student_name'],
            'body' => ['grade_key'],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $layout = app(\App\Services\ReportCards\ReportCardLayout::class);
        $classFresh = \App\Models\Tenant\SchoolClass::find($class->id);
        $resolved = $layout->resolvedFor($classFresh);
        app(TenantManager::class)->forget();

        $this->assertSame(['grade_key'], $resolved['body_order']);
        $this->assertFalse($layout->classHasOwnOverride($classFresh));
        $this->assertTrue($layout->sectionHasOwnOverride($section->fresh()));
    }

    public function test_resetting_a_classs_layout_override_falls_back_to_the_inherited_default(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'classlayoutthree');
        ['class' => $class] = $this->seedOneClass($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'target_scope' => 'class',
            'target_id' => $class->id,
            'enabled' => '1',
            'header' => [],
            'info' => [],
            'body' => ['remarks'],
        ])->assertRedirect();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout/reset-override", [
            'target_scope' => 'class',
            'target_id' => $class->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $layout = app(\App\Services\ReportCards\ReportCardLayout::class);
        $classFresh = \App\Models\Tenant\SchoolClass::find($class->id);
        $stillOverridden = $layout->classHasOwnOverride($classFresh);
        app(TenantManager::class)->forget();

        $this->assertFalse($stillOverridden);
    }

    public function test_the_designer_editing_for_picker_shows_which_layout_is_being_edited(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'classlayoutfour');
        ['class' => $class] = $this->seedOneClass($school);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/settings/layout?target=class:{$class->id}")
            ->assertOk()
            ->assertSee('Showing the layout KG One currently inherits');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'target_scope' => 'class',
            'target_id' => $class->id,
            'enabled' => '1',
            'header' => ['school_name'],
            'info' => ['student_name'],
            'body' => ['remarks'],
        ])->assertRedirect();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/settings/layout?target=class:{$class->id}")
            ->assertOk()
            // Not escaped (false): the apostrophe here is literal Blade
            // template text, not inside {{ }}, so it renders as a raw
            // ' rather than assertSee's own default &#039; escaping.
            ->assertSee("KG One's own custom layout", false)
            ->assertSee('Follow the inherited default instead');
    }

    /**
     * The end to end proof: a class's own custom layout arrangement
     * actually changes what its real students' report cards render,
     * not just what the designer/service report. 'remarks' (Class
     * Teacher's Remark, always shown by default) proves the class's own
     * body order actually took effect; 'grade_key' (the school default's
     * own choice, never given to this class) proves it stayed only on
     * the school default, never applied here.
     */
    public function test_a_classs_own_custom_layout_renders_on_its_real_report_card(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'classlayoutfive');
        ['class' => $class] = $this->seedOneClass($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'enabled' => '1',
            'header' => ['school_name'],
            'info' => ['student_name'],
            'body' => ['grade_key'],
        ])->assertRedirect();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'target_scope' => 'class',
            'target_id' => $class->id,
            'enabled' => '1',
            'header' => ['school_name'],
            'info' => ['student_name'],
            'body' => ['remarks'],
        ])->assertRedirect();

        $classResponse = $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview/class/{$class->id}");
        $classResponse->assertOk();
        $classHtml = $classResponse->getContent();
        // "Comment", not "Remark" (user directive 2026-09-22, every
        // template): the new bordered-table remarks wording.
        $this->assertStringContainsString("Class Teacher's Comment", $classHtml);
        $this->assertStringNotContainsString('Grade Key', $classHtml);
    }
}
