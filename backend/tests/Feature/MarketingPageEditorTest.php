<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Marketing page editor (user request 2026-07-18: "link the platform
 * owner end to the marketing pages so its easier to make changes to any
 * of the pages"). Edits the real site/*.html files on whatever disk this
 * app is running on; a strict allow-list keeps it from ever reading or
 * writing outside the site directory.
 */
class MarketingPageEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_the_index_lists_the_real_pages_on_disk(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('admin.marketing-pages.index'))
            ->assertOk()
            ->assertSee('Home')
            ->assertSee('Pricing')
            ->assertSee('index.html');
    }

    public function test_editing_a_page_shows_its_real_html_source(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('admin.marketing-pages.edit', ['page' => 'faq.html']))
            ->assertOk()
            ->assertSee('<textarea', false);
    }

    public function test_saving_a_page_writes_the_file_and_restores_it_afterward(): void
    {
        $admin = $this->superAdmin();
        $path = base_path('../site/faq.html');
        $original = File::get($path);

        try {
            $marker = '<!-- test-marker-'.uniqid().' -->';
            $this->actingAs($admin)->post(route('admin.marketing-pages.update', ['page' => 'faq.html']), [
                'content' => $original.$marker,
            ])->assertRedirect();

            $this->assertStringContainsString($marker, File::get($path));
        } finally {
            File::put($path, $original);
        }
    }

    public function test_an_unknown_page_name_404s_not_arbitrary_file_access(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('admin.marketing-pages.edit', ['page' => '../../.env']))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.marketing-pages.edit', ['page' => 'not-a-real-page.html']))->assertNotFound();
    }

    public function test_a_non_super_admin_cannot_reach_the_editor(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.marketing-pages.index'))->assertNotFound();
    }
}
