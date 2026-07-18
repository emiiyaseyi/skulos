<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Platform team management (user request 2026-07-18: "so many features
 * that we have in the school end are missing under the platform owner
 * end... i need full control of the whole thing"). Until now the only
 * way to grant platform-admin access at all was the platform:make-super-
 * admin console command, with no screen to see who has it or revoke it.
 */
class PlatformTeamTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_the_team_page_lists_every_platform_admin(): void
    {
        $admin = $this->superAdmin();
        $other = $this->superAdmin();
        $notAdmin = User::factory()->create();

        $this->actingAs($admin)->get(route('admin.team.index'))
            ->assertOk()
            ->assertSee($admin->email)
            ->assertSee($other->email)
            ->assertDontSee($notAdmin->email);
    }

    public function test_an_admin_can_grant_a_new_platform_admin(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.team.store'), [
            'name' => 'New Admin', 'email' => 'new-admin@example.com', 'password' => 'password123',
        ])->assertRedirect();

        $newAdmin = User::where('email', 'new-admin@example.com')->first();
        $this->assertNotNull($newAdmin);
        $this->assertTrue($newAdmin->is_super_admin);
        $this->assertTrue($newAdmin->must_change_password);
        $this->assertTrue(AuditLog::where('action', 'platform.team.granted')->exists());
    }

    public function test_an_admin_can_revoke_another_admins_access(): void
    {
        $admin = $this->superAdmin();
        $other = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.team.revoke', ['user' => $other->id]))
            ->assertRedirect();

        $this->assertFalse($other->fresh()->is_super_admin);
        $this->assertTrue(AuditLog::where('action', 'platform.team.revoked')->exists());
    }

    public function test_an_admin_cannot_revoke_their_own_access(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin(); // a second admin exists, so "last admin" isn't the blocker here

        $this->actingAs($admin)->post(route('admin.team.revoke', ['user' => $admin->id]))
            ->assertStatus(422);

        $this->assertTrue($admin->fresh()->is_super_admin);
    }

    public function test_a_non_super_admin_cannot_reach_the_team_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.team.index'))->assertNotFound();
    }
}
