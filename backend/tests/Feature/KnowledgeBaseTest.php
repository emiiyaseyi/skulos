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
}
