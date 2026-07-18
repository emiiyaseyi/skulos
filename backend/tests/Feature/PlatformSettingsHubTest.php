<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Platform Settings hub (user request 2026-07-18: "so many features that
 * we have in the school end are missing under the platform owner end").
 * Every platform-level settings screen already existed, independently
 * routed, only reachable if you already knew the URL; this just links
 * them all from one page.
 */
class PlatformSettingsHubTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_the_hub_links_every_platform_settings_screen(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Mail Settings')
            ->assertSee('Landing Pages')
            ->assertSee('Idle Timeout')
            ->assertSee('Retention')
            ->assertSee('Support Contact')
            ->assertSee('Subscription Bank')
            ->assertSee(route('admin.settings.mail.edit'), false)
            ->assertSee(route('admin.settings.subscription-bank.edit'), false);
    }

    public function test_a_non_super_admin_cannot_view_the_settings_hub(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.settings.index'))->assertNotFound();
    }
}
