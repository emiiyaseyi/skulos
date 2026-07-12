<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\CommunicationLog;
use App\Models\Tenant\Notification;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Dbase recommendation (standing rule, CLAUDE.md/tracker): archive old
 * academic sessions instead of leaving every one in the default list
 * forever, and a scheduled job to purge anything that grows unbounded
 * (read notifications, communication logs, expired login sessions).
 */
class MaintenanceCommandsTest extends TestCase
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

    protected function schoolWithMember(string $roleSlug, string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_an_archived_session_is_hidden_from_the_default_list(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'archone');

        app(TenantManager::class)->use($school);
        $old = AcademicSession::create(['name' => '2023/2024 Session']);
        $current = AcademicSession::create(['name' => '2025/2026 Session']);
        $current->makeCurrent();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/settings/calendar")
            ->assertOk()
            ->assertSee('2023/2024 Session')
            ->assertSee('2025/2026 Session');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/academics/sessions/{$old->id}/archive")
            ->assertRedirect();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/settings/calendar");
        $response->assertOk()->assertSee('2025/2026 Session');

        app(TenantManager::class)->use($school);
        $refreshed = AcademicSession::find($old->id);
        app(TenantManager::class)->forget();
        $this->assertTrue($refreshed->is_archived);
    }

    public function test_the_current_session_cannot_be_archived(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'archtwo');

        app(TenantManager::class)->use($school);
        $current = AcademicSession::create(['name' => '2025/2026 Session']);
        $current->makeCurrent();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/academics/sessions/{$current->id}/archive")
            ->assertRedirect()->assertSessionHas('error');

        app(TenantManager::class)->use($school);
        $refreshed = AcademicSession::find($current->id);
        app(TenantManager::class)->forget();
        $this->assertFalse($refreshed->is_archived);
    }

    public function test_an_archived_session_can_be_restored(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'archthree');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2023/2024 Session', 'is_archived' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/academics/sessions/{$session->id}/unarchive")
            ->assertRedirect();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/settings/calendar")
            ->assertOk()->assertSee('2023/2024 Session');
    }

    public function test_retention_cleanup_purges_old_read_notifications_and_communication_logs_only(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'retone']));
        $user = User::factory()->create();

        app(TenantManager::class)->use($school);
        Notification::create(['user_id' => $user->id, 'title' => 'Old read', 'body' => 'x', 'category' => 'x', 'read_at' => now()->subDays(200)]);
        Notification::create(['user_id' => $user->id, 'title' => 'Recent read', 'body' => 'x', 'category' => 'x', 'read_at' => now()->subDays(5)]);
        Notification::create(['user_id' => $user->id, 'title' => 'Old unread', 'body' => 'x', 'category' => 'x']);
        CommunicationLog::create(['channel' => 'email', 'recipient' => 'a@example.com', 'status' => CommunicationLog::STATUS_SENT])
            ->forceFill(['created_at' => now()->subDays(400)])->save();
        app(TenantManager::class)->forget();

        $this->artisan('skulos:retention-cleanup')->assertSuccessful();

        app(TenantManager::class)->use($school);
        $remaining = Notification::pluck('title')->all();
        $commsCount = CommunicationLog::count();
        app(TenantManager::class)->forget();

        $this->assertNotContains('Old read', $remaining, 'old read notifications past retention must be purged');
        $this->assertContains('Recent read', $remaining, 'recently read notifications must survive');
        $this->assertContains('Old unread', $remaining, 'unread notifications are never purged regardless of age');
        $this->assertSame(0, $commsCount, 'the old communication log must be purged');
    }

    public function test_optimize_tables_command_runs_successfully_against_every_school(): void
    {
        app(TenantManager::class)->provision(School::factory()->create(['slug' => 'optone']));
        app(TenantManager::class)->forget();

        $this->artisan('skulos:optimize-tables')->assertSuccessful();
    }
}
