<?php

namespace Tests\Feature;

use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeBaseCategory;
use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The in-app Knowledge Base (2026-07-21): resolved the long-standing
 * "real screen or a service commitment" ambiguity as "both" — this is the
 * real screen half. Platform-authored, shared by every school.
 */
class KnowledgeBaseTest extends TestCase
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
        $this->seed(PlanSeeder::class);
        $this->rbac = app(Rbac::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    protected function schoolWithMember(string $roleSlug, string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug, 'plan_id' => \App\Models\Plan::where('slug', 'foundation')->value('id')]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_the_admin_index_and_article_edit_pages_render_with_the_new_steps_and_start_route_fields(): void
    {
        $admin = $this->superAdmin();
        $category = KnowledgeBaseCategory::create(['name' => 'General', 'slug' => 'general', 'sequence' => 0]);
        $article = KnowledgeBaseArticle::create([
            'knowledge_base_category_id' => $category->id, 'title' => 'Sample', 'slug' => 'sample',
            'body' => 'x', 'steps' => ['One', 'Two'], 'start_route' => 'school.students.create', 'sequence' => 0, 'is_published' => true,
        ]);

        $this->actingAs($admin)->get(route('admin.knowledge-base.index'))->assertOk()->assertSee('Steps (one per line)');
        $this->actingAs($admin)->get(route('admin.knowledge-base.articles.edit', ['articleId' => $article->id]))
            ->assertOk()->assertSee('One')->assertSee('Two');
    }

    public function test_admin_can_add_a_category_and_an_article(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.knowledge-base.categories.store'), ['name' => 'Billing'])
            ->assertRedirect();
        $category = KnowledgeBaseCategory::where('name', 'Billing')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.knowledge-base.articles.store'), [
            'knowledge_base_category_id' => $category->id,
            'title' => 'How billing works',
            'body' => 'Explained here.',
            'is_published' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('knowledge_base_articles', ['title' => 'How billing works', 'is_published' => true]);
    }

    public function test_admin_can_edit_and_unpublish_an_article(): void
    {
        $admin = $this->superAdmin();
        $category = KnowledgeBaseCategory::create(['name' => 'General', 'slug' => 'general', 'sequence' => 0]);
        $article = KnowledgeBaseArticle::create([
            'knowledge_base_category_id' => $category->id, 'title' => 'Old title', 'slug' => 'old-title',
            'body' => 'Old body', 'sequence' => 0, 'is_published' => true,
        ]);

        $this->actingAs($admin)->post(route('admin.knowledge-base.articles.update', ['articleId' => $article->id]), [
            'knowledge_base_category_id' => $category->id,
            'title' => 'New title',
            'body' => 'New body',
            // The real form always sends a hidden 0 fallback immediately
            // before the checkbox, so an unchecked box still submits "0"
            // rather than being silently absent — simulated directly here.
            'is_published' => '0',
        ])->assertRedirect();

        $article->refresh();
        $this->assertSame('New title', $article->title);
        $this->assertSame('New body', $article->body);
        $this->assertFalse($article->is_published);
    }

    /** User request 2026-07-22: "this should be presented more as a procedure or step... add a button that takes them to where to start the process." */
    public function test_admin_can_add_an_article_with_steps_and_a_start_here_button(): void
    {
        $admin = $this->superAdmin();
        $category = KnowledgeBaseCategory::create(['name' => 'General', 'slug' => 'general', 'sequence' => 0]);

        $this->actingAs($admin)->post(route('admin.knowledge-base.articles.store'), [
            'knowledge_base_category_id' => $category->id,
            'title' => 'Adding a student',
            'body' => 'Short intro.',
            'steps' => "Go to Students.\nClick Add Student.\nSave.",
            'start_route' => 'school.students.create',
            'is_published' => '1',
        ])->assertRedirect();

        $article = KnowledgeBaseArticle::where('title', 'Adding a student')->firstOrFail();
        $this->assertSame(['Go to Students.', 'Click Add Student.', 'Save.'], $article->steps);
        $this->assertSame('school.students.create', $article->start_route);
    }

    public function test_an_unknown_start_route_is_rejected(): void
    {
        $admin = $this->superAdmin();
        $category = KnowledgeBaseCategory::create(['name' => 'General', 'slug' => 'general', 'sequence' => 0]);

        $this->actingAs($admin)->post(route('admin.knowledge-base.articles.store'), [
            'knowledge_base_category_id' => $category->id,
            'title' => 'Bad route article',
            'start_route' => 'not.a.real.route',
            'is_published' => '1',
        ])->assertSessionHasErrors('start_route');
    }

    public function test_the_article_page_renders_steps_and_a_start_here_button(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'kbsteps');
        $category = KnowledgeBaseCategory::create(['name' => 'General', 'slug' => 'general', 'sequence' => 0]);
        $article = KnowledgeBaseArticle::create([
            'knowledge_base_category_id' => $category->id, 'title' => 'Adding a student', 'slug' => 'adding-a-student',
            'body' => 'Short intro.', 'steps' => ['Go to Students.', 'Click Add Student.', 'Save.'],
            'start_route' => 'school.students.create', 'sequence' => 0, 'is_published' => true,
        ]);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/help/{$article->slug}")
            ->assertOk()
            ->assertSee('Go to Students.')
            ->assertSee('Click Add Student.')
            ->assertSee('Start here')
            ->assertSee(route('school.students.create', ['school' => $school->slug]), false);
    }

    /** User report 2026-07-22: "Knowdlge base showing under other staff should be related to the things the user can do only." */
    public function test_a_restricted_role_only_sees_articles_it_actually_has_access_to(): void
    {
        [$school, $subjectTeacher] = $this->schoolWithMember('subject-teacher', 'kbrestricted');

        $category = KnowledgeBaseCategory::create(['name' => 'General', 'slug' => 'general', 'sequence' => 0]);
        KnowledgeBaseArticle::create([
            'knowledge_base_category_id' => $category->id, 'title' => 'Finding your way around', 'slug' => 'orientation',
            'body' => 'General help visible to everyone.', 'sequence' => 0, 'is_published' => true,
        ]);
        KnowledgeBaseArticle::create([
            'knowledge_base_category_id' => $category->id, 'title' => 'Adding a student', 'slug' => 'adding-a-student-gated',
            'body' => 'Requires students.create.', 'start_route' => 'school.students.create', 'sequence' => 1, 'is_published' => true,
        ]);

        // Subject teacher has students.view but not students.create, so the gated article must be hidden.
        $this->actingAs($subjectTeacher)->get("http://{$school->slug}.localhost/help")
            ->assertOk()
            ->assertSee('Finding your way around')
            ->assertDontSee('Adding a student');

        // A subject teacher navigating straight to the gated article's URL must not reach it either.
        $this->actingAs($subjectTeacher)->get("http://{$school->slug}.localhost/help/adding-a-student-gated")
            ->assertNotFound();

        [$school2, $owner] = $this->schoolWithMember('school-owner', 'kbrestrictedowner');
        $category2 = KnowledgeBaseCategory::create(['name' => 'General', 'slug' => 'general2', 'sequence' => 0]);
        KnowledgeBaseArticle::create([
            'knowledge_base_category_id' => $category2->id, 'title' => 'Adding a student', 'slug' => 'adding-a-student-owner',
            'body' => 'Requires students.create.', 'start_route' => 'school.students.create', 'sequence' => 0, 'is_published' => true,
        ]);

        // A school owner (wildcard grants) must still see it.
        $this->actingAs($owner)->get("http://{$school2->slug}.localhost/help")
            ->assertOk()->assertSee('Adding a student');
    }

    /**
     * User report 2026-07-26: "Help & Knowledge Base for parent is
     * totally wrong, why is it showing how to set up house,
     * communicaiton, so may issues are wrog there." A parent holds
     * students.view and communication.view too, for their own child
     * only, not for browsing school administration articles like Houses
     * or Communication History, so the permission check alone was not
     * enough, hidden_for_family_scoped catches these regardless.
     */
    public function test_a_parent_does_not_see_admin_only_articles_despite_holding_the_same_permission(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'kbparentfilter');
        $this->seed(\Database\Seeders\KnowledgeBaseSeeder::class);

        $response = $this->actingAs($parent)->get("http://{$school->slug}.localhost/help");

        $response->assertOk()
            ->assertDontSee('Setting up school houses')
            ->assertDontSee('Seeing who has acknowledged an announcement');
    }

    public function test_a_parent_cannot_open_an_admin_only_article_directly_by_url(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'kbparentfilterurl');
        $this->seed(\Database\Seeders\KnowledgeBaseSeeder::class);

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/help/setting-up-school-houses")
            ->assertNotFound();
    }

    /** User request 2026-07-26: "update it to show items related to parents." */
    public function test_a_parent_sees_the_new_for_parents_category_with_real_content(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'kbparentcontent');
        $this->seed(\Database\Seeders\KnowledgeBaseSeeder::class);

        $response = $this->actingAs($parent)->get("http://{$school->slug}.localhost/help");

        $response->assertOk()
            ->assertSee('For Parents')
            ->assertSee(e("Paying your child's fees"), false)
            ->assertSee('Reading school announcements')
            ->assertSee('Checking your payment history');
    }

    /** A school owner, unaffected by the family-scoped exclusion, must still see every admin article. */
    public function test_an_admin_still_sees_the_admin_only_articles_the_family_scoped_exclusion_hides_from_a_parent(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'kbadminstillsees');
        $this->seed(\Database\Seeders\KnowledgeBaseSeeder::class);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/help")
            ->assertOk()
            ->assertSee('Setting up school houses')
            ->assertSee('Seeing who has acknowledged an announcement');
    }

    /**
     * User request 2026-07-28: "Help & Knowledge Base role-relevance for
     * OTHER roles" — the same shape of fix test_a_parent_does_not_see_
     * admin_only_articles above already covers, now for a class teacher.
     */
    public function test_a_class_teacher_does_not_see_admin_only_articles_despite_holding_the_same_permission(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'kbteacherfilter');
        $this->seed(\Database\Seeders\KnowledgeBaseSeeder::class);

        $response = $this->actingAs($teacher)->get("http://{$school->slug}.localhost/help");

        $response->assertOk()
            ->assertDontSee('Setting up school houses')
            ->assertDontSee('Seeing who has acknowledged an announcement');
    }

    public function test_a_class_teacher_sees_the_new_for_teachers_category_with_real_content(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'kbteachercontent');
        $this->seed(\Database\Seeders\KnowledgeBaseSeeder::class);

        $response = $this->actingAs($teacher)->get("http://{$school->slug}.localhost/help");

        $response->assertOk()
            ->assertSee('For Teachers')
            ->assertSee(e("Entering scores for your subject"), false)
            ->assertSee(e("Messaging a student's guardian"), false);
    }

    /**
     * User report 2026-07-28, found live after the "For Teachers"
     * category shipped: "why are parents seeing for teachers knowledge
     * under their own help base" — "Viewing your timetable" and
     * "Messaging a student's guardian" both point at routes
     * (school.timetable.mine, school.chat.index) a parent ALSO
     * genuinely holds the permission for, so the route-level
     * hidden_for_family_scoped flag alone (already used for the admin
     * only articles) was not enough; these specific, teacher-voiced
     * articles need their own per-article hide.
     */
    public function test_a_parent_does_not_see_the_for_teachers_category_at_all(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'kbparentnoteacher');
        $this->seed(\Database\Seeders\KnowledgeBaseSeeder::class);

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/help")
            ->assertOk()
            ->assertDontSee('For Teachers')
            ->assertDontSee('Entering scores for your subject')
            ->assertDontSee('Viewing your timetable')
            ->assertDontSee(e("Messaging a student's guardian"), false);
    }

    /** The same leak confirmed live in the OTHER direction too: a real class teacher could see parent-voiced "For Parents" articles via the same shared-route/shared-permission shape. */
    public function test_a_class_teacher_does_not_see_parent_specific_for_parents_articles(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'kbteachernoparent');
        $this->seed(\Database\Seeders\KnowledgeBaseSeeder::class);

        $response = $this->actingAs($teacher)->get("http://{$school->slug}.localhost/help");

        $response->assertOk()
            ->assertDontSee(e("Viewing your child's academic report"), false)
            ->assertDontSee(e("Paying your child's fees"), false)
            ->assertDontSee('Checking your payment history');
        // Generic, non parent-exclusive content under the same category
        // stays visible, hiding it would remove real content rather
        // than fix a leak (no other article covers this for a teacher).
        $response->assertSee('Reading school announcements')
            ->assertSee('Understanding your notifications');
    }

    /** Taking attendance is a genuine class teacher task, deliberately NOT hidden by the teacher-scoped exclusion, unlike the admin-only articles above. */
    public function test_a_class_teacher_still_sees_the_take_attendance_article(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'kbteacherattendance');
        $this->seed(\Database\Seeders\KnowledgeBaseSeeder::class);

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/help")
            ->assertOk()
            ->assertSee('Taking attendance');
    }

    public function test_deleting_a_category_removes_its_articles(): void
    {
        $admin = $this->superAdmin();
        $category = KnowledgeBaseCategory::create(['name' => 'Temp', 'slug' => 'temp', 'sequence' => 0]);
        KnowledgeBaseArticle::create([
            'knowledge_base_category_id' => $category->id, 'title' => 'Doomed', 'slug' => 'doomed',
            'body' => 'x', 'sequence' => 0, 'is_published' => true,
        ]);

        $this->actingAs($admin)->delete(route('admin.knowledge-base.categories.destroy', ['categoryId' => $category->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('knowledge_base_categories', ['id' => $category->id]);
        $this->assertDatabaseMissing('knowledge_base_articles', ['title' => 'Doomed']);
    }

    public function test_a_non_super_admin_cannot_manage_the_knowledge_base(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.knowledge-base.index'))->assertNotFound();
    }

    public function test_school_member_sees_only_published_articles_and_can_search(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'kbschool');

        $category = KnowledgeBaseCategory::create(['name' => 'Attendance', 'slug' => 'attendance', 'sequence' => 0]);
        KnowledgeBaseArticle::create([
            'knowledge_base_category_id' => $category->id, 'title' => 'Taking attendance', 'slug' => 'taking-attendance',
            'body' => 'How to mark students present.', 'sequence' => 0, 'is_published' => true,
        ]);
        KnowledgeBaseArticle::create([
            'knowledge_base_category_id' => $category->id, 'title' => 'Unfinished draft', 'slug' => 'unfinished-draft',
            'body' => 'Not ready.', 'sequence' => 1, 'is_published' => false,
        ]);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/help")
            ->assertOk()->assertSee('Taking attendance')->assertDontSee('Unfinished draft');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/help?q=attendance")
            ->assertOk()->assertSee('Taking attendance');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/help?q=zzz-nothing-matches")
            ->assertOk()->assertDontSee('Taking attendance');
    }

    public function test_a_draft_article_cannot_be_opened_directly_by_url(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'kbdraft');

        $category = KnowledgeBaseCategory::create(['name' => 'General', 'slug' => 'general', 'sequence' => 0]);
        $article = KnowledgeBaseArticle::create([
            'knowledge_base_category_id' => $category->id, 'title' => 'Draft', 'slug' => 'draft-article',
            'body' => 'x', 'sequence' => 0, 'is_published' => false,
        ]);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/help/{$article->slug}")
            ->assertNotFound();
    }

    /**
     * The real point of this test: Knowledge Base is the one "support"
     * category feature that renders as a real nav link even for the most
     * restricted scoped role (2026-07-21 nav fix), unlike its siblings
     * (Standard Support etc.) which stay excluded as service commitments.
     */
    public function test_a_parent_sees_the_knowledge_base_link_in_the_nav(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'kbparent');

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('Knowledge Base');
    }

    /** User request 2026-07-22: "let's make this section much more presentable and great. Also lets add more help or knowledge to it." */
    public function test_the_default_catalog_seeds_the_new_articles_with_real_steps_and_start_routes(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'kbseeded');
        $this->seed(\Database\Seeders\KnowledgeBaseSeeder::class);

        $newArticle = KnowledgeBaseArticle::where('slug', 'building-a-custom-report')->first();
        $this->assertNotNull($newArticle, 'a new article this batch added must exist after seeding');
        $this->assertNotEmpty($newArticle->steps);
        $this->assertSame('school.reports.builder.index', $newArticle->start_route);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/help")
            ->assertOk()
            ->assertSee('What do you need help with?')
            ->assertSee('Building a custom report')
            ->assertSee('Reports &amp; Analytics', false);
    }

    /** Re-running the seeder must backfill steps/start_route onto an already-seeded article without touching its existing body. */
    public function test_reseeding_backfills_steps_onto_an_already_existing_article_without_overwriting_its_body(): void
    {
        $category = KnowledgeBaseCategory::create(['name' => 'Getting Started', 'slug' => 'getting-started', 'sequence' => 0]);
        KnowledgeBaseArticle::create([
            'knowledge_base_category_id' => $category->id, 'title' => 'Finding your way around', 'slug' => 'finding-your-way-around',
            'body' => 'A custom intro an admin already wrote.', 'sequence' => 0, 'is_published' => true,
        ]);

        $this->seed(\Database\Seeders\KnowledgeBaseSeeder::class);

        $article = KnowledgeBaseArticle::where('slug', 'finding-your-way-around')->firstOrFail();
        $this->assertSame('A custom intro an admin already wrote.', $article->body, 'an existing body must never be overwritten by re-seeding');
        $this->assertNotEmpty($article->steps, 'steps must still be backfilled since they were genuinely missing before');
    }
}
