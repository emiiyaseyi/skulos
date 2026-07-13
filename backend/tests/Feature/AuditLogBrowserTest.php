<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * View Audit Logs (Dashboard Design Cross-Check, 2026-07-13: "Partial,
 * recent activity is already inline on the dashboard, no separate full
 * log browser page yet"). A real search/filter/paginate screen, not just
 * the dashboard's capped 8-row list.
 */
class AuditLogBrowserTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_admin_sees_real_audit_entries(): void
    {
        $admin = $this->superAdmin();
        $school = School::factory()->create();
        AuditLog::create(['user_id' => $admin->id, 'action' => 'school.suspended', 'auditable_type' => School::class, 'auditable_id' => $school->id]);

        $this->actingAs($admin)->get(route('admin.audit-log.index'))
            ->assertOk()->assertSee('school suspended');
    }

    public function test_filtering_by_action_narrows_results(): void
    {
        $admin = $this->superAdmin();
        AuditLog::create(['user_id' => $admin->id, 'action' => 'school.suspended']);
        AuditLog::create(['user_id' => $admin->id, 'action' => 'plan.created']);

        $this->actingAs($admin)->get(route('admin.audit-log.index', ['action' => 'school']))
            ->assertOk()
            ->assertSee('school suspended')
            ->assertDontSee('plan created');
    }

    public function test_filtering_by_date_range_narrows_results(): void
    {
        $admin = $this->superAdmin();
        // AuditLog is immutable (throws on Eloquent update), so backdating
        // an old entry has to be a raw insert, not create-then-save.
        \Illuminate\Support\Facades\DB::table('audit_logs')->insert([
            'user_id' => $admin->id, 'action' => 'old.event', 'created_at' => now()->subDays(30),
        ]);
        AuditLog::create(['user_id' => $admin->id, 'action' => 'recent.event']);

        $this->actingAs($admin)->get(route('admin.audit-log.index', ['from' => now()->subDays(2)->toDateString()]))
            ->assertOk()
            ->assertSee('recent event')
            ->assertDontSee('old event');
    }

    public function test_a_non_super_admin_cannot_view_the_audit_log(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.audit-log.index'))->assertNotFound();
    }
}
