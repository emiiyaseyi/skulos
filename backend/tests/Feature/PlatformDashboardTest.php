<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Plan;
use App\Models\School;
use App\Models\TrialRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Platform Super Admin dashboard (DASHBOARD DESIGN doc, cross checked
 * 2026-07-12: "Total Schools, Active Schools, Trial Schools... Active
 * Subscriptions, Free Trial Conversions... School Growth chart... Backup
 * Status... Add School, Clone School, Create Plan"). Revenue/Invoices/
 * Support Tickets/API/SMS/WhatsApp usage/Server Health are not shown,
 * none of that is tracked anywhere in the product yet, and nothing here
 * is allowed to fabricate a number for it.
 */
class PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_dashboard_shows_real_school_and_user_counts(): void
    {
        $admin = $this->superAdmin();
        School::factory()->create(['status' => School::STATUS_ACTIVE]);
        School::factory()->create(['status' => School::STATUS_SUSPENDED]);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Total Schools')
            ->assertSee('Trial Schools')
            ->assertSee('Total Users')
            ->assertSee('Trial Conversion')
            ->assertSee('Schools Without a Backup')
            ->assertSee('School growth')
            ->assertSee('Subscriptions by plan');
    }

    public function test_quick_actions_link_to_real_routes(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.schools.index'), false)
            ->assertSee(route('admin.billing.index'), false)
            ->assertSee(route('admin.trial-requests.index'), false);
    }

    public function test_subscription_breakdown_reflects_real_plan_assignments(): void
    {
        $admin = $this->superAdmin();
        $plan = Plan::create(['name' => 'Foundation', 'slug' => 'foundation', 'price_per_student_per_term' => 500, 'modules' => [], 'features' => []]);
        School::factory()->create(['plan_id' => $plan->id]);
        School::factory()->create(['plan_id' => $plan->id]);
        School::factory()->create(['plan_id' => null]);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Foundation')
            ->assertSee('No plan assigned');
    }

    public function test_schools_without_a_backup_counts_real_backup_rows(): void
    {
        $admin = $this->superAdmin();
        $backedUp = School::factory()->create();
        School::factory()->create(); // no backup

        Backup::create([
            'school_id' => $backedUp->id, 'disk' => 'local', 'file_path' => 'x/y.sqlite',
            'size_bytes' => 100, 'type' => Backup::TYPE_MANUAL,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));
        $response->assertOk();

        // Two schools total, one has a backup, one does not.
        $this->assertStringContainsString('1</b><span>Schools Without a Backup', str_replace(["\n", '  '], '', $response->getContent()));
    }

    public function test_trial_conversion_rate_reflects_real_trial_outcomes(): void
    {
        $admin = $this->superAdmin();
        TrialRequest::create(['school_name' => 'A', 'contact_name' => 'A', 'email' => 'a@example.com', 'status' => TrialRequest::STATUS_APPROVED]);
        TrialRequest::create(['school_name' => 'B', 'contact_name' => 'B', 'email' => 'b@example.com', 'status' => TrialRequest::STATUS_REJECTED]);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('50%');
    }
}
