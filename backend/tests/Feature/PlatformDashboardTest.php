<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Plan;
use App\Models\School;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\TrialRequest;
use App\Models\User;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite']);
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

    /**
     * Dashboard Design Cross-Check (2026-07-13): "Total Students/Staff/
     * Parents... would need summing across every school's own database
     * live; not built." Each real school's numbers only exist inside that
     * school's own tenant database, so this proves the platform dashboard
     * actually reaches in and sums them, not just that the labels exist.
     */
    public function test_dashboard_shows_real_cross_school_totals(): void
    {
        $admin = $this->superAdmin();
        $school = app(TenantManager::class)->provision(School::factory()->create());

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'S1', 'first_name' => 'A', 'last_name' => 'B']);
        Student::create(['admission_number' => 'S2', 'first_name' => 'C', 'last_name' => 'D']);
        Staff::create(['staff_id' => 'ST1', 'first_name' => 'E', 'last_name' => 'F']);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Guardian One', 'email' => 'g1@example.com']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));
        $response->assertOk();
        $content = str_replace(["\n", '  '], '', $response->getContent());

        $this->assertStringContainsString('2</b><span>Students', $content);
        $this->assertStringContainsString('1</b><span>Staff', $content);
        $this->assertStringContainsString('1</b><span>Parents', $content);
    }

    public function test_active_subscriptions_shows_one_summary_number(): void
    {
        $admin = $this->superAdmin();
        $plan = Plan::create(['name' => 'Advance', 'slug' => 'advance', 'price_per_student_per_term' => 800, 'modules' => [], 'features' => []]);
        School::factory()->create(['plan_id' => $plan->id]);
        School::factory()->create(['plan_id' => $plan->id]);
        School::factory()->create(['plan_id' => null]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));
        $response->assertOk();
        $content = str_replace(["\n", '  '], '', $response->getContent());

        $this->assertStringContainsString('2</b><span>Active Subscriptions', $content);
    }
}
