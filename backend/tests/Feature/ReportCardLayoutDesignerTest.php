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

    public function test_a_non_report_card_viewer_cannot_reach_the_designer(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'layoutfour');

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/report-cards/settings/layout")
            ->assertForbidden();
    }
}
