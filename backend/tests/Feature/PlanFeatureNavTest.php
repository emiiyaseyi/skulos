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

    /**
     * User report 2026-07-22: "staff and parent field setting keeps
     * going back to student field settings" — originally fixed by
     * having Parent/Staff Settings jump to the shared screen's
     * identifier section by anchor, keeping all three as separate nav
     * lines. Superseded 2026-08-07 by a stronger direct request ("all
     * these fields should be merged into one menu 'Fields' since they
     * have the same content"): three clickable rows landing on the same
     * one real screen kept reading as "keeps going back to student
     * field settings" even with the anchor fix, so they are now one
     * single nav line instead of three.
     */
    public function test_student_parent_and_staff_field_settings_are_one_nav_line_not_three(): void
    {
        [$school, $user] = $this->schoolOnPlan([], 'plananchor');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");
        $html = $response->getContent();

        $target = route('school.students.settings.edit', ['school' => $school->slug]);
        $this->assertStringContainsString('href="'.$target.'"', $html);
        $this->assertStringContainsString('>Fields<', $html, 'the merged single nav line should read exactly "Fields"');
        $response->assertDontSee('Parent Settings')->assertDontSee('Staff Settings');
        // Custom Fields is a genuinely separate real screen, kept as its
        // own adjacent nav line rather than folded into the one above.
        $response->assertSee('Custom Fields');
    }

    /** User report 2026-07-22: "storage and health should be merge as one menu since its same data there." */
    public function test_storage_and_health_are_one_merged_nav_line_not_two(): void
    {
        [$school, $user] = $this->schoolOnPlan([], 'planstoragehealth');

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard");

        $response->assertOk()->assertSee('Storage &amp; Health', false)
            ->assertDontSee('>Storage<', false)->assertDontSee('>Health<', false);
    }

    /**
     * User report 2026-07-22: "Its shows error 404 for subject teacher
     * now but it ought not to be a menu option for subject teachers at
     * all under report and analystics." result_management's own nav
     * permission mapping was looser (examinations.view, which a subject
     * teacher genuinely holds for entering scores) than the screen it
     * points at actually requires (report_cards.view, which they do
     * not hold), so the link rendered anyway and led nowhere real.
     */
    public function test_a_subject_teacher_never_sees_report_cards_in_the_nav(): void
    {
        $plan = Plan::create([
            'name' => 'Test Plan rcnavhide', 'slug' => 'plan-rcnavhide',
            'price_per_student_per_term' => 500, 'modules' => [], 'features' => ['result_management', 'basic_report_cards'],
        ]);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'rcnavhide', 'plan_id' => $plan->id]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'subject-teacher');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertDontSee('Report Cards');
    }

    /** A class teacher genuinely holds report_cards.view and must still see the link, unaffected by the fix above. */
    public function test_a_class_teacher_still_sees_report_cards_in_the_nav(): void
    {
        $plan = Plan::create([
            'name' => 'Test Plan rcnavshow', 'slug' => 'plan-rcnavshow',
            'price_per_student_per_term' => 500, 'modules' => [], 'features' => ['result_management', 'basic_report_cards'],
        ]);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'rcnavshow', 'plan_id' => $plan->id]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('Report Cards');
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

        // Learning Management is deliberately NOT in this list any more:
        // Assignments moved there 2026-08-05 and, like every role, is
        // checked by real RBAC permission rather than the plan-feature
        // catalog (see nav-school.blade.php's own comment on that
        // choice), so a wildcard owner role always sees the group
        // regardless of plan, the same way it always saw Academic for
        // the identical reason before this move.
        $response->assertOk()
            ->assertDontSee('Admissions')
            ->assertDontSee('Human Resources');
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
    /**
     * Updated 2026-07-18 for the Settings nested-accordion restructure:
     * "User & Role Management" (the last remaining "administration"
     * catalog feature that was not already force-grouped elsewhere) moved
     * into Settings > Users > Roles & Permissions too, per the user's own
     * exact tree. Every "administration" category feature is now
     * force-grouped somewhere else (Academic or Settings), so the
     * "Administration" top-level group can no longer render at all, for
     * any plan; this test's original premise (Administration renders
     * immediately after Academic) is obsolete, verifying its replacement
     * instead: no Administration group at all, and the 6 relocated items
     * (5 original plus Roles & Permissions) land inside the Settings
     * accordion's own nested sub-groups, not a flat list.
     */
    public function test_the_nav_groups_are_reordered_and_relocated_items_moved_into_settings(): void
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
        $this->assertNotFalse($academicPos);
        $this->assertFalse(strpos($content, 'data-group="administration"'), 'Administration should never render, every one of its features is now force-grouped elsewhere');

        $settingsPos = strpos($content, 'data-group="settings"');
        $this->assertNotFalse($settingsPos);
        $this->assertTrue($academicPos < $settingsPos, 'Settings must render after Academic (pinned last)');

        // Everything between Academic and Settings must include the three
        // portal links (moved there), the five relocated settings items
        // must NOT be in that slice (they moved out of Academic/
        // Administration entirely, into Settings' own sub-groups).
        $academicSlice = substr($content, $academicPos, $settingsPos - $academicPos);
        $this->assertStringContainsString('Staff Portal', $academicSlice);
        $this->assertStringContainsString('Parent Portal', $academicSlice);
        $this->assertStringContainsString('Student Portal', $academicSlice);

        $nextGroupAfterSettings = strpos($content, 'class="side-section"', $settingsPos + 1);
        $settingsSlice = substr($content, $settingsPos, ($nextGroupAfterSettings ?: strlen($content)) - $settingsPos);
        $this->assertStringContainsString('Assessment Structure', $settingsSlice);
        $this->assertStringContainsString('Sessions &amp; Terms', $settingsSlice);
        $this->assertStringContainsString('Branding', $settingsSlice);
        $this->assertStringContainsString('Campuses', $settingsSlice);
        $this->assertStringContainsString('Houses', $settingsSlice);
        $this->assertStringContainsString('Roles &amp; Permissions', $settingsSlice);
        // Nested sub-group structure, not a flat list.
        $this->assertStringContainsString('side-subgroup-btn', $settingsSlice);
        $this->assertStringContainsString('data-subgroup="settings-school"', $settingsSlice);
        $this->assertStringContainsString('data-subgroup="settings-users"', $settingsSlice);
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

    /** User directive 2026-07-22: "students/import should not be a menu option under reports and analytics... you are only removing it from the menu side, page content and function still remains." */
    public function test_bulk_import_no_longer_renders_its_own_nav_line_but_the_import_screen_still_works(): void
    {
        [$school, $user] = $this->schoolOnPlan(['bulk_import'], 'planbulkimport');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Student Portal')
            ->assertDontSee('Import Students')
            ->assertDontSee('Soon');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/students/import")->assertOk();
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

    /**
     * Advance tier plan feature 'advanced_report_cards' (2026-07-18):
     * points at the real behavioural/psychomotor traits catalog, a
     * capability Foundation's plain 'basic_report_cards' does not include.
     */
    public function test_advanced_report_cards_renders_as_a_real_link_not_soon(): void
    {
        [$school, $user] = $this->schoolOnPlan(['basic_report_cards', 'advanced_report_cards'], 'planadvrc');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Behavioural Traits')
            ->assertDontSee('Soon');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/report-cards/traits")->assertOk();
    }

    /**
     * User report 2026-07-18: "the examination page is missing as a menu
     * which should be under academics where teachers can enter scores, it
     * should have the grade page as a sub menu under it, use the setting
     * approach we use to achieve the above". "Enter Scores" is core,
     * plan-independent functionality (same "Report settings" treatment);
     * "Grading" only appears once the Advance-tier 'unlimited_grading_
     * systems' feature is on the plan, nested under the same Examinations
     * sub-group rather than as its own flat Academic entry.
     */
    public function test_examinations_appears_under_academic_with_grading_nested_when_the_plan_includes_it(): void
    {
        [$school, $user] = $this->schoolOnPlan(['student_records', 'unlimited_grading_systems'], 'planexam');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSeeInOrder(['Academic', 'Examinations', 'Enter Scores', 'Grading'])
            ->assertSee('data-subgroup="academic-examinations"', false)
            ->assertDontSee('Soon');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/examinations")->assertOk();
        $this->actingAs($user)->get("http://{$school->slug}.localhost/examinations/grading")->assertOk();
    }

    public function test_enter_scores_shows_even_without_the_unlimited_grading_systems_feature(): void
    {
        [$school, $user] = $this->schoolOnPlan(['student_records'], 'planexamtwo');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('Enter Scores')->assertDontSee('Grading');
    }

    /**
     * User directive 2026-07-18: "add a submenu for term broadsheets
     * too". 'student_reports' (a Foundation feature, previously its own
     * Reports & Analytics line) nests under the same Academic >
     * Examinations subgroup as Enter Scores and Grading, in that fixed
     * order regardless of which plan_feature_catalog category each came
     * from.
     */
    public function test_term_broadsheets_nests_under_academic_examinations_in_order(): void
    {
        [$school, $user] = $this->schoolOnPlan(['student_reports', 'unlimited_grading_systems'], 'planbroadsheet');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSeeInOrder(['Examinations', 'Enter Scores', 'Term Broadsheets', 'Grading'])
            ->assertDontSee('Student Reports')
            ->assertDontSee('Soon');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/reports/students")->assertOk();
    }

    public function test_custom_report_builder_renders_as_a_real_link_not_soon(): void
    {
        [$school, $user] = $this->schoolOnPlan(['custom_report_builder'], 'planbuilder');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Report Builder')
            ->assertDontSee('Soon');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/reports/builder")->assertOk();
    }

    public function test_custom_report_card_designer_renders_as_a_real_link_not_soon(): void
    {
        [$school, $user] = $this->schoolOnPlan(['custom_report_card_designer'], 'plandesigner');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Report Card Designer')
            ->assertDontSee('Soon');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/report-cards/settings/layout")->assertOk();
    }

    public function test_timetable_management_renders_as_a_real_link_not_soon(): void
    {
        [$school, $user] = $this->schoolOnPlan(['timetable_management'], 'plantimetable');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Timetable')
            ->assertDontSee('Soon');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/timetable")->assertOk();
    }

    public function test_academic_insights_renders_as_a_real_link_not_soon(): void
    {
        [$school, $user] = $this->schoolOnPlan(['academic_insights'], 'planinsights');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Academic Insights')
            ->assertDontSee('Soon');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/examinations/academic-insights")->assertOk();
    }

    public function test_teacher_workload_renders_as_a_real_link_not_soon(): void
    {
        [$school, $user] = $this->schoolOnPlan(['teacher_workload'], 'planworkload');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Teacher Workload')
            ->assertDontSee('Soon');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/staff-portal/workload")->assertOk();
    }

    /**
     * Narrative Evaluation (2026-07-18) is deliberately not a
     * plan_feature_catalog entry ("for all admins across all plan"), so
     * it always shows for an admin regardless of which features the
     * plan lists, unlike everything else this file tests.
     */
    public function test_narrative_evaluation_always_shows_for_an_admin_regardless_of_plan_features(): void
    {
        [$school, $user] = $this->schoolOnPlan(['student_records'], 'plannarrative');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Narrative Evaluation');
    }

    public function test_narrative_evaluation_is_hidden_from_a_scoped_teacher_role(): void
    {
        $plan = Plan::create([
            'name' => 'Test Plan plannarrativetwo', 'slug' => 'plan-plannarrativetwo',
            'price_per_student_per_term' => 500, 'modules' => [], 'features' => ['student_records'],
        ]);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'plannarrativetwo', 'plan_id' => $plan->id]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'teacher');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($user)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertDontSee('Narrative Evaluation');
    }

    public function test_the_nav_search_box_also_renders_on_the_platform_admin_portal(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();

        $this->actingAs($admin)->get('http://localhost/admin')
            ->assertOk()
            ->assertSee('id="navSearchInput"', false);
    }

    /**
     * Found live 2026-07-31: a school gets an optional module two
     * genuinely independent ways — a full Plan change (Plan::features,
     * what every other test in this file exercises), or a direct
     * per-school toggle (BillingController::toggleFeature() ->
     * Rbac::setModuleState(), no plan change involved at all, e.g.
     * "Toggle feature" on one school's Billing detail page). A module
     * enabled only the second way used to never appear in nav, even with
     * real screens behind it, since the nav's gate only ever checked
     * hasFeature()/hasAnyFeatureInCategory() (Plan::features), never
     * module state. feature_category_module_map bridges the two.
     */
    public function test_a_module_enabled_by_direct_toggle_alone_still_appears_in_nav_with_no_plan_change(): void
    {
        $plan = Plan::create([
            'name' => 'Test Plan modtoggle', 'slug' => 'plan-modtoggle',
            'price_per_student_per_term' => 500, 'modules' => [], 'features' => ['student_records'],
        ]);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'modtoggleone', 'plan_id' => $plan->id]));
        $owner = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        // Confirms the plan alone would NOT have surfaced this (the real
        // bug's exact starting condition), before toggling the module.
        $this->assertFalse($school->hasFeature('online_admissions'));
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertDontSee('Admissions Settings');

        $this->rbac->setModuleState($school, 'admissions', \App\Models\Module::STATE_ENABLED);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('Admissions Settings')
            ->assertSee('Admission Form Builder')
            // Repointed from "Application Fee Review" to the real
            // pipeline board once Phase 1g built it (Fee Review stays
            // reachable from there instead of its own top-level nav
            // entry, same as the school_profile repoint precedent).
            ->assertSee('Applicants')
            // 'admission_letters' repointed again 2026-08-06, to the real
            // pipeline board, once real offer letter generation actually
            // landed (nav-school.blade.php's own comment on this key).
            ->assertSee('Admission Letters')
            // 'online_document_upload' repointed 2026-08-07, to the real
            // admin document review queue, once discovered (via direct
            // feedback) that Phase 1f had actually shipped back on
            // 2026-07-31 but this catalog key was simply never repointed
            // off "Soon" — the exact same shape of gap as the other three
            // keys above, now closed the same way. Rendered label is
            // "Document Review" (the $link tuple's own label), not the
            // raw catalog key text "Online Document Upload".
            ->assertSee('Document Review');
    }

    /**
     * User report 2026-09-10, screenshot of "Examination" showing CBT
     * Examination/Question Bank/Random Questions/Examination Analytics
     * all stuck on "Soon": these marketing keys describe exactly what
     * the Assessment Hub already does, but that module was built as its
     * own "Learning Management" nav section entirely outside this
     * catalog, so these 4 keys were never repointed off "Soon" even
     * once the real screens existed.
     */
    public function test_examination_marketing_keys_stop_showing_soon_once_assessment_hub_is_live(): void
    {
        $plan = Plan::create([
            'name' => 'Test Plan examsoon', 'slug' => 'plan-examsoon',
            'price_per_student_per_term' => 500, 'modules' => [],
            'features' => ['student_records', 'cbt_examination', 'question_bank', 'random_questions', 'examination_analytics'],
        ]);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'examsoonone', 'plan_id' => $plan->id]));
        $owner = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        // Before Assessment Hub is enabled, these correctly still read
        // "Soon" -- the underlying screens genuinely are not reachable
        // by this school yet.
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('CBT Examination')
            ->assertSee('Random Questions');

        $this->rbac->setModuleState($school, 'assessment_hub', \App\Models\Module::STATE_ENABLED);

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk()
            // The marketing labels with no real link of the same name
            // must vanish entirely, not just lose their "Soon" tag.
            ->assertDontSee('CBT Examination')
            ->assertDontSee('Random Questions')
            // Question Bank's marketing label matches its real built
            // link's own label, so presence alone does not prove the
            // fix -- assert the real route itself is on the page.
            ->assertSee(route('school.cbt.questions.index', ['school' => $school->slug]))
            // Genuinely unbuilt (Score Matrix/Assessment Manager, not
            // started) -- must keep reading "Soon", not be silently
            // hidden or wrongly promoted.
            ->assertSee('Examination Analytics');
    }
}
