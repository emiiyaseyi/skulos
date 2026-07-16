<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Announcement;
use App\Models\Tenant\Notification;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Communication History (user directive 2026-07-16, "ALL THERE": build out
 * the remaining Reports & Analytics/Communication "Soon" items). A unified,
 * chronological ledger across Announcements and Messages/Broadcasts, which
 * already share one underlying `Announcement` table but only ever had their
 * own single-type browse screen each.
 */
class CommunicationHistoryTest extends TestCase
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

    public function test_history_shows_both_announcements_and_broadcasts_together(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'commhistone');

        app(TenantManager::class)->use($school);
        Announcement::create(['title' => 'Term Resumption', 'body' => 'School resumes Monday.', 'type' => Announcement::TYPE_ANNOUNCEMENT, 'audience' => 'everyone', 'status' => 'published', 'published_at' => now()]);
        Announcement::create(['title' => 'Fee Reminder', 'body' => 'Fees due Friday.', 'type' => Announcement::TYPE_BROADCAST, 'audience' => 'parent', 'status' => 'published', 'published_at' => now()]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/announcements/history")
            ->assertOk()
            ->assertSee('Term Resumption')
            ->assertSee('Fee Reminder')
            ->assertSee('Announcement')
            ->assertSee('Message');
    }

    public function test_the_type_filter_narrows_the_list(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'commhisttwo');

        app(TenantManager::class)->use($school);
        Announcement::create(['title' => 'Term Resumption', 'body' => 'School resumes Monday.', 'type' => Announcement::TYPE_ANNOUNCEMENT, 'audience' => 'everyone', 'status' => 'published', 'published_at' => now()]);
        Announcement::create(['title' => 'Fee Reminder', 'body' => 'Fees due Friday.', 'type' => Announcement::TYPE_BROADCAST, 'audience' => 'parent', 'status' => 'published', 'published_at' => now()]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/announcements/history?type=broadcast")
            ->assertOk()
            ->assertSee('Fee Reminder')
            ->assertDontSee('Term Resumption');
    }

    public function test_a_platform_broadcast_delivered_to_this_school_does_not_appear_on_its_own_sent_ledger(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'commhistthree');

        app(TenantManager::class)->use($school);
        Announcement::create(['title' => 'Platform Update', 'body' => 'System maintenance tonight.', 'type' => Announcement::TYPE_BROADCAST, 'audience' => 'everyone', 'status' => 'published', 'published_at' => now(), 'platform_broadcast_id' => 1]);
        Announcement::create(['title' => 'Our Own Notice', 'body' => 'Real school notice.', 'type' => Announcement::TYPE_ANNOUNCEMENT, 'audience' => 'everyone', 'status' => 'published', 'published_at' => now()]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/announcements/history")
            ->assertOk()
            ->assertSee('Our Own Notice')
            ->assertDontSee('Platform Update');
    }

    public function test_delivered_and_read_counts_reflect_real_notification_rows(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'commhistfour');
        $recipientOne = User::factory()->create();
        $recipientTwo = User::factory()->create();

        app(TenantManager::class)->use($school);
        $announcement = Announcement::create(['title' => 'Sports Day', 'body' => 'This Saturday.', 'type' => Announcement::TYPE_ANNOUNCEMENT, 'audience' => 'everyone', 'status' => 'published', 'published_at' => now()]);
        Notification::create(['user_id' => $recipientOne->id, 'announcement_id' => $announcement->id, 'category' => 'academic', 'type' => 'info', 'title' => 'Sports Day', 'body' => 'This Saturday.', 'read_at' => now()]);
        Notification::create(['user_id' => $recipientTwo->id, 'announcement_id' => $announcement->id, 'category' => 'academic', 'type' => 'info', 'title' => 'Sports Day', 'body' => 'This Saturday.']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/announcements/history")
            ->assertOk()
            ->assertSee('1 of 2');
    }

    public function test_history_requires_communication_view_permission(): void
    {
        [$school, $user] = $this->schoolWithMember('security', 'commhistfive');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/announcements/history")->assertForbidden();
    }
}
