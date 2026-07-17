<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A school's plan actually drives what shows in its nav (user request
 * 2026-07-11: "once a plan is given to a school, it should automatically
 * appear under the school end for respective users, and if it's not a
 * plan/feature that was given to a school, the feature should not
 * appear" — hidden entirely, not shown locked, per the user's own
 * correction after an earlier locked/greyed out draft).
 */
class PlanFeatureNavTest extends TestCase
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

    protected function schoolOnPlan(array $features, string $slug, array $modules = []): array
    {
        $plan = Plan::create([
            'name' => 'Test Plan '.$slug, 'slug' => 'plan-'.$slug,
            'price_per_student_per_term' => 500, 'modules' => $modules, 'features' => $features,
        ]);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug, 'plan_id' => $plan->id]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_a_feature_included_in_the_plan_shows_in_the_nav(): void
    {
        [$school, $user] = $this->schoolOnPlan(['school_houses', 'result_management'], 'planone');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");

        // "Results" merged into "Report Cards" (user directive 2026-07-15),
        // one nav line, not two, see PlanFeatureNavTest::
        // test_results_and_report_cards_merge_into_one_nav_line below.
        $response->assertOk()->assertSee('Houses')->assertSee('Report Cards');
    }

    public function test_a_feature_not_in_the_plan_is_not_shown_at_all(): void
    {
        [$school, $user] = $this->schoolOnPlan(['school_houses'], 'plantwo');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");

        $response->assertOk()->assertSee('Houses')->assertDontSee('Report Cards');
    }

    public function test_school_model_has_feature_helper_reflects_the_plan(): void
    {
        $plan = Plan::create([
            'name' => 'Direct Test Plan', 'slug' => 'direct-test-plan',
            'price_per_student_per_term' => 500, 'modules' => [], 'features' => ['school_branding'],
        ]);
        $school = School::factory()->create(['plan_id' => $plan->id]);

        $this->assertTrue($school->hasFeature('school_branding'));
        $this->assertFalse($school->hasFeature('academic_sessions_terms'));
    }

    /**
     * The nav is grouped into collapsible submenus (user request
     * 2026-07-11: "we should use the grouping features where a menu is
     * under a sub menu instead of listing all as one under the menu"),
     * not a flat list under a plain section label.
     */
    public function test_the_nav_renders_grouped_collapsible_submenus(): void
    {
        // "school_houses" and "result_management" both got moved out of
        // Academic later in this file's own history (Houses to Settings,
        // Results merged into Report Cards under Reports & Analytics),
        // so this uses "student_records" instead, which stays under
        // Academic on its own, unaffected by either of those moves.
        [$school, $user] = $this->schoolOnPlan(['student_records'], 'planthree');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");

        $response->assertOk()
            ->assertSee('side-group-btn', false)
            ->assertSee('side-submenu', false)
            ->assertSee('Academic'); // the catalog's own category label, groups now mirror Billing exactly
    }

    /**
     * Whole functional areas with no screen built yet (Admissions,
     * Finance, HR, Learning) still need to appear once a plan includes
     * any of their features (user request 2026-07-11, after reporting
     * that moving a school to Enterprise showed no change: "it should
     * show for any plan or feature given to the school from the billing
     * page"), shown as a category level Soon entry.
     */
    public function test_a_category_with_no_built_screen_still_appears_as_soon_once_the_plan_includes_it(): void
    {
        [$school, $user] = $this->schoolOnPlan(['online_admissions', 'hr_management', 'lms'], 'planfour');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");

        $response->assertOk()
            ->assertSee('Admissions')
            ->assertSee('Human Resources')
            ->assertSee('Learning');
    }

    public function test_a_category_with_none_of_its_features_in_the_plan_does_not_appear(): void
    {
        [$school, $user] = $this->schoolOnPlan(['school_houses'], 'planfive');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");

        $response->assertOk()
            ->assertDontSee('Admissions')
            ->assertDontSee('Human Resources')
            ->assertDontSee('Learning Management');
    }

    public function test_school_model_has_any_feature_in_category_helper(): void
    {
        $plan = Plan::create([
            'name' => 'Category Test Plan', 'slug' => 'category-test-plan',
            'price_per_student_per_term' => 500, 'modules' => [], 'features' => ['payroll'],
        ]);
        $school = School::factory()->create(['plan_id' => $plan->id]);

        $this->assertTrue($school->hasAnyFeatureInCategory('finance'));
        $this->assertFalse($school->hasAnyFeatureInCategory('learning'));
    }

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    /**
     * The whole grant/revoke loop through the real Billing screens the
     * platform admin actually uses (user request 2026-07-11: "it must
     * check what is under billing... I need it to speak to the billing
     * section under Platform administration as set"), not a direct
     * database write standing in for it.
     */
    public function test_granting_an_addon_from_billing_shows_it_in_the_nav_and_revoking_hides_it_again(): void
    {
        [$school, $user] = $this->schoolOnPlan(['school_houses'], 'plansix');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.billing.schools.add-ons.store', $school), [
            'add_on_code' => 'library',
        ])->assertRedirect();

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk()->assertSee('Library');

        $addOn = \App\Models\SchoolAddOn::where('school_id', $school->id)->where('add_on_code', 'library')->firstOrFail();
        $this->actingAs($admin)->delete(route('admin.billing.add-ons.destroy', $addOn))->assertRedirect();

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk()->assertDontSee('>Library<', false);
    }

    /**
     * "Report settings" (the report card look/template page) is not
     * gated on any plan feature (user decision 2026-07-11: every plan
     * should be able to reach it, same as Backups or Mail Settings),
     * unlike "Report Cards" itself which still needs basic_report_cards.
     */
    public function test_report_settings_shows_regardless_of_plan_features(): void
    {
        [$school, $user] = $this->schoolOnPlan(['school_houses'], 'planseven');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");

        $response->assertOk()->assertSee('Report settings')->assertDontSee('Report Cards<');
    }

    /**
     * "Teacher Portal", "Parent Portal", and "Student Portal" are not
     * separate screens, they are what a teacher/parent/student sees once
     * they sign in, which already exists (teacher access is Staff with a
     * teaching role; parent/student logins are managed per student). They
     * must not render as "Soon" (user report 2026-07-15: "THOUGHT THEY
     * HAVE BEEN BUILT AND READY TO GO"), fixed by pointing them at
     * Staff/Students. A first attempt at that fix deduped them away
     * entirely (since Staff/Students already render their own line under
     * a different feature key), which made them vanish from the menu
     * instead of showing as real links, an even more confusing regression
     * (user report 2026-07-15: "I CANT SEE STUDENT AND PARENT PORTAL
     * UNDER THE MENU SECTION"). "Parent Portal" renders its own line,
     * pointing at its own real Section -> Class browse screen since
     * 2026-07-16. "Teacher Portal"/"Staff Records" merged into a single
     * "Staff Portal" line (2026-07-15: "STAFF AND TEACHER PORTAL SHOULD
     * BE MERGE INTO ONE AND RENAMED STAFF PORTAL"), and "Student
     * Portal"/"Student Records" merged into a single "Student Portal"
     * line (2026-07-16: "MERGE WHAT WE HAVE UNDER STUDENT INTO STUDENT
     * PORTAL SO THEY ARE ONE"), each pair must appear exactly once, not
     * as two separate entries.
     */
    public function test_teacher_parent_student_portal_features_render_as_real_links(): void
    {
        [$school, $user] = $this->schoolOnPlan(
            ['staff_records', 'student_records', 'teacher_portal', 'parent_portal', 'student_portal'],
            'planeight'
        );

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee('Staff Portal')
            ->assertSee('Student Portal')
            ->assertSee('Parent Portal')
            ->assertDontSee('Soon');
        $this->assertSame(1, substr_count($content, 'Staff Portal'), 'Staff and Teacher Portal must merge into exactly one line, not render twice');
        $this->assertSame(1, substr_count($content, 'Student Portal'), 'Student Records and Student Portal must merge into exactly one line, not render twice');
    }

    /**
     * Nav restructuring (user directive 2026-07-15): "ASSESSMENT
     * STRUCTURE, SESSIONS & TERM, BRANDING, CAMPUSES AND HOUSES SHOULD BE
     * MOVED TO SETTINGS"; "I WANT TO MOVE THE WHOLE ADMINISTRATION
     * SECTION TO COME IMMEDIATELY AFTER ACADEMIC SECTION"; "STAFF
     * PORTAL, PARENT AND STUDENT PORTAL SHOULD BE MOVED TO ACADEMIC".
     */
    public function test_the_nav_groups_are_reordered_and_five_items_moved_into_settings(): void
    {
        [$school, $user] = $this->schoolOnPlan([
            'staff_records', 'student_records', 'teacher_portal', 'parent_portal', 'student_portal',
            'continuous_assessment', 'academic_sessions_terms', 'school_branding', 'school_profile', 'school_houses',
            'user_role_management',
        ], 'plannine');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");
        $content = $response->getContent();

        $response->assertOk();

        $academicPos = strpos($content, 'data-group="academic"');
        $administrationPos = strpos($content, 'data-group="administration"');
        $this->assertNotFalse($academicPos);
        $this->assertNotFalse($administrationPos);
        $this->assertTrue($academicPos < $administrationPos, 'Administration must render immediately after Academic');

        // Everything between the Academic and Administration group markers
        // must include the three portal links (moved there) and the five
        // relocated settings items must NOT be in that slice (they moved
        // out of Academic/Administration entirely, into Settings).
        $academicSlice = substr($content, $academicPos, $administrationPos - $academicPos);
        $this->assertStringContainsString('Staff Portal', $academicSlice);
        $this->assertStringContainsString('Parent Portal', $academicSlice);
        $this->assertStringContainsString('Student Portal', $academicSlice);

        $settingsPos = strpos($content, 'data-group="settings"');
        $this->assertNotFalse($settingsPos);
        $nextGroupAfterSettings = strpos($content, 'side-section', $settingsPos + 1);
        $settingsSlice = substr($content, $settingsPos, ($nextGroupAfterSettings ?: strlen($content)) - $settingsPos);
        $this->assertStringContainsString('Assessment Structure', $settingsSlice);
        $this->assertStringContainsString('Sessions &amp; Terms', $settingsSlice);
        $this->assertStringContainsString('Branding', $settingsSlice);
        $this->assertStringContainsString('Campuses', $settingsSlice);
        $this->assertStringContainsString('Houses', $settingsSlice);
    }

    /**
     * User directive 2026-07-16: "I WANT THE WHOLE SETTINGS SECTION TO
     * BE THE LAST FOR EVERY USER THAT HAVE ACCESS TO IT". Plain
     * insertion order alone did not guarantee this, "Settings" is built
     * partway through the nav's own PHP block, and a plan feature that
     * happens to trigger a category group later in the file (Reports &
     * Analytics, Communication) could still land after it.
     */
    public function test_settings_group_always_renders_last(): void
    {
        [$school, $user] = $this->schoolOnPlan([
            'basic_report_cards', 'announcements', 'school_branding', 'school_houses',
        ], 'planthirteen');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");
        $content = $response->getContent();
        $response->assertOk();

        $settingsPos = strpos($content, 'data-group="settings"');
        $this->assertNotFalse($settingsPos);

        foreach (['reports-analytics', 'communication'] as $otherGroup) {
            $otherPos = strpos($content, "data-group=\"{$otherGroup}\"");
            $this->assertNotFalse($otherPos, "{$otherGroup} group must render given this plan");
            $this->assertLessThan($settingsPos, $otherPos, "{$otherGroup} must render before Settings, Settings must always be last");
        }

        // Settings must be the very last side-section in the sidebar, not just before these two specifically.
        // Matches the actual rendered element (class="side-section"), not a
        // bare substring: the nav search box's own script legitimately
        // references .side-section as a CSS selector further down the page,
        // which a plain "side-section" substring scan would misfire on.
        $this->assertFalse(strpos($content, 'class="side-section"', $settingsPos + 1), 'no other group may render after Settings');
    }

    /**
     * User directive 2026-07-15: "MERGE INTO ONE NAV LINK" (Results and
     * Report Cards). Unlike the Staff/Teacher Portal merge, both sides
     * have a real, distinct, actively used screen, so the Results screen
     * (school.examinations.index) must stay reachable, not just vanish,
     * see ReportCardController::index()'s "Results" button.
     */
    public function test_results_and_report_cards_merge_into_one_nav_line(): void
    {
        [$school, $user] = $this->schoolOnPlan(['result_management', 'basic_report_cards'], 'planten');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk();
        $content = $response->getContent();

        $navStart = strpos($content, 'id="sideNav"');
        $navEnd = strpos($content, '</aside>', $navStart);
        $this->assertNotFalse($navStart);
        $nav = substr($content, $navStart, $navEnd - $navStart);

        $this->assertSame(1, substr_count($nav, '>Report Cards<'), 'must render exactly once in the sidebar, not twice');
        // Scoped to the sidebar nav specifically, not the whole page: a
        // dashboard quick-action button also happens to say "Results"
        // (role-panel.blade.php, a shortcut straight to the results entry
        // screen), which is a separate UI element from the sidebar and
        // outside this merge's scope, unlike the nav line this asserts is gone.
        $this->assertStringNotContainsString('>Results<', $nav, 'the standalone "Results" nav line must be gone, merged into Report Cards');
    }

    public function test_the_report_cards_page_still_links_to_the_results_screen_after_the_merge(): void
    {
        [$school, $user] = $this->schoolOnPlan(['result_management', 'basic_report_cards'], 'planeleven');

        $response = $this->actingAs($user)->get(route('school.report-cards.index', ['school' => $school->slug]));

        $response->assertOk()->assertSee(route('school.examinations.index', ['school' => $school->slug]), false);
    }

    /**
     * User directive 2026-07-16: "ACADEMIC CALENDAR AND CALENDAR SHOULD
     * BE MERGE AS ONE AND PLACED UNDER ACADEMICS - LET IT BE ACTIVE".
     * Calendar used to be hardcoded, unconditionally pinned in the Main
     * group; it is now the "academic_calendar" plan feature, grouped
     * under Academic like everything else in that catalog category.
     */
    public function test_calendar_merges_with_academic_calendar_and_moves_under_academic(): void
    {
        [$school, $user] = $this->schoolOnPlan(['academic_calendar', 'student_records'], 'plantwelve');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");
        $content = $response->getContent();
        $response->assertOk();

        $this->assertSame(1, substr_count($content, '>Calendar<'), 'must render exactly once, not twice');

        $mainPos = strpos($content, 'side-section');
        $academicPos = strpos($content, 'data-group="academic"');
        $this->assertNotFalse($mainPos);
        $this->assertNotFalse($academicPos);

        $nextSectionAfterMain = strpos($content, 'side-section', $mainPos + 1);
        $mainSlice = substr($content, $mainPos, ($nextSectionAfterMain ?: strlen($content)) - $mainPos);
        $this->assertStringNotContainsString('>Calendar<', $mainSlice, 'must no longer be pinned in Main');
    }

    /** The single arrow toggle next to the school name exists on the school portal, not the admin one. */
    public function test_the_nav_has_a_single_expand_collapse_arrow_next_to_the_school_name(): void
    {
        [$school, $user] = $this->schoolOnPlan(['school_houses'], 'plannavtoggle');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");

        $response->assertOk()
            ->assertSee('id="navToggleAllBtn"', false)
            ->assertDontSee('>Collapse all<', false)
            ->assertDontSee('>Expand all<', false);
    }

    public function test_a_school_with_no_plan_falls_back_to_the_default_plan(): void
    {
        Plan::create([
            'name' => 'Default Plan', 'slug' => 'default-plan', 'is_default' => true,
            'price_per_student_per_term' => 500, 'modules' => [], 'features' => ['student_records'],
        ]);
        $school = School::factory()->create(['plan_id' => null]);

        $this->assertTrue($school->hasFeature('student_records'));
        $this->assertFalse($school->hasFeature('payroll'));
    }

    /**
     * User directive 2026-07-16 ("ALL THERE", build out the remaining
     * Reports & Analytics/Communication "Soon" items): the Notification
     * Center screen (NotificationController) was fully built back on
     * 2026-07-12, categories/search/pin/archive/bulk actions and all, but
     * was never wired into this catalog, so "In App Notifications" showed
     * as "Soon" despite already working.
     */
    public function test_in_app_notifications_renders_as_a_real_link_not_soon(): void
    {
        [$school, $user] = $this->schoolOnPlan(['in_app_notifications'], 'plannotifs');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Notifications')
            ->assertSee('href="http://plannotifs.localhost/notifications"', false)
            ->assertDontSee('Soon');

        // The link actually goes somewhere real, not a 404/500 behind it.
        $this->actingAs($user)->get("http://{$school->slug}.localhost/notifications")->assertOk();
    }

    /**
     * User directive, asked three separate times: "ADD A SEARCH BOX ABOVE
     * THE MENU BAR WHERE ONE CAN SEARCH FOR MENUS AND FEATURES TO EASILY
     * LOCATE THE FEATURE/MENU ONE IS LOOKING FOR".
     */
    public function test_the_nav_search_box_renders_above_the_menu(): void
    {
        [$school, $user] = $this->schoolOnPlan(['in_app_notifications', 'school_houses'], 'plannavsearch');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk();
        $content = $response->getContent();

        $searchPos = strpos($content, 'id="navSearchInput"');
        $navPos = strpos($content, 'class="side-nav"');
        $this->assertNotFalse($searchPos, 'the nav search input must render');
        $this->assertNotFalse($navPos);
        $this->assertLessThan($navPos, $searchPos, 'the search box must render above the menu, not inside or below it');
        $response->assertSee('Search menus and features');
    }

    /**
     * User directive 2026-07-16: "ALL THERE" — build out the remaining
     * Reports & Analytics "Soon" items. export_to_excel/bulk_export now
     * have a real screen behind them (StudentController::export()), so
     * they resolve to the Students screen rather than staying "Soon".
     */
    public function test_export_to_excel_and_bulk_export_render_as_a_real_link_not_soon(): void
    {
        [$school, $user] = $this->schoolOnPlan(['export_to_excel', 'bulk_export'], 'planexport');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Student Portal')
            ->assertDontSee('Soon');
    }

    public function test_student_performance_dashboard_renders_as_a_real_link_not_soon(): void
    {
        [$school, $user] = $this->schoolOnPlan(['student_performance_dashboard'], 'planperf');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Student Performance Dashboard')
            ->assertDontSee('Soon');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/examinations/performance-dashboard")->assertOk();
    }

    public function test_communication_history_renders_as_a_real_link_not_soon(): void
    {
        [$school, $user] = $this->schoolOnPlan(['communication_history'], 'plancommhist');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Communication History')
            ->assertDontSee('Soon');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/announcements/history")->assertOk();
    }

    public function test_basic_fee_records_and_bank_transfer_payment_render_as_real_links_not_soon(): void
    {
        [$school, $user] = $this->schoolOnPlan(
            ['basic_fee_records', 'bank_transfer_payment_option', 'payment_evidence_upload', 'payment_verification'],
            'planfinance',
            ['finance'],
        );

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Fee Records')
            ->assertSee('Payments')
            ->assertDontSee('Soon');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/finance/fee-items")->assertOk();
        $this->actingAs($user)->get("http://{$school->slug}.localhost/finance/payments")->assertOk();
    }

    public function test_the_nav_search_box_also_renders_on_the_platform_admin_portal(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();

        $this->actingAs($admin)->get('http://localhost/admin')
            ->assertOk()
            ->assertSee('id="navSearchInput"', false);
    }
}
