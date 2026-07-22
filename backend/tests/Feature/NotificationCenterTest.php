<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Notification;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Notification Center (user directed backlog item, 2026-07-12, "To Do
 * List 2" doc item 8): categorized (Academic/Finance/Urgent/System),
 * with Inbox/Pinned/Archived views and bulk actions, replacing the
 * previous flat "everything is category announcement" dashboard widget.
 */
class NotificationCenterTest extends TestCase
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

    public function test_a_new_notification_created_via_a_known_category_lands_in_the_right_type(): void
    {
        [$school, $user] = $this->schoolWithMember('school-owner', 'notifone');

        app(TenantManager::class)->use($school);
        $n = Notification::create([
            'user_id' => $user->id, 'title' => 'Test', 'category' => 'report_card_review',
            'type' => Notification::typeFor('report_card_review'),
        ]);
        app(TenantManager::class)->forget();

        $this->assertSame('academic', $n->type);
    }

    public function test_an_unknown_category_falls_back_to_the_system_type(): void
    {
        $this->assertSame('system', Notification::typeFor('something_nobody_registered'));
    }

    public function test_the_notification_center_lists_a_users_own_notifications_and_filters_by_type(): void
    {
        [$school, $user] = $this->schoolWithMember('school-owner', 'notiftwo');

        app(TenantManager::class)->use($school);
        Notification::create(['user_id' => $user->id, 'title' => 'Announcement one', 'category' => 'announcement', 'type' => 'system']);
        Notification::create(['user_id' => $user->id, 'title' => 'Review needed', 'category' => 'report_card_review', 'type' => 'academic']);
        Notification::create(['user_id' => 999, 'title' => 'Someone elses', 'category' => 'announcement', 'type' => 'system']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($user)->get("http://{$school->slug}.localhost/notifications");
        $response->assertOk()->assertSee('Announcement one')->assertSee('Review needed')->assertDontSee('Someone elses');

        // assertDontSee() on the raw page is no longer precise enough:
        // the header notification bell (2026-07-21) shows this user's
        // recent notifications everywhere, unfiltered by this page's own
        // ?type= filter, so "Announcement one" can correctly appear there
        // even while correctly absent from the filtered list itself.
        $filtered = $this->actingAs($user)->get("http://{$school->slug}.localhost/notifications?type=academic");
        $filtered->assertOk()->assertSee('Review needed');
        $this->assertFalse(
            $filtered->viewData('notifications')->contains(fn ($n) => $n->title === 'Announcement one'),
            'The type filter did not exclude a non-matching notification from the list.'
        );
    }

    public function test_a_notification_can_be_pinned_then_appears_in_the_pinned_view(): void
    {
        [$school, $user] = $this->schoolWithMember('school-owner', 'notifthree');

        app(TenantManager::class)->use($school);
        $n = Notification::create(['user_id' => $user->id, 'title' => 'Pin me', 'category' => 'announcement', 'type' => 'system']);
        $nId = $n->id;
        app(TenantManager::class)->forget();

        $this->actingAs($user)->post("http://{$school->slug}.localhost/notifications/bulk-action", [
            'notification_ids' => [$nId], 'action' => 'pin',
        ])->assertRedirect();

        $this->actingAs($user)->get("http://{$school->slug}.localhost/notifications?view=pinned")
            ->assertOk()->assertSee('Pin me')->assertSee('Pinned');
    }

    public function test_an_archived_notification_leaves_the_inbox_and_unread_count(): void
    {
        [$school, $user] = $this->schoolWithMember('school-owner', 'notiffour');

        app(TenantManager::class)->use($school);
        $n = Notification::create(['user_id' => $user->id, 'title' => 'Archive me', 'category' => 'announcement', 'type' => 'system']);
        $nId = $n->id;
        app(TenantManager::class)->forget();

        $this->actingAs($user)->get("http://{$school->slug}.localhost/notifications")->assertOk()->assertSee('1 unread');

        $this->actingAs($user)->post("http://{$school->slug}.localhost/notifications/bulk-action", [
            'notification_ids' => [$nId], 'action' => 'archive',
        ])->assertRedirect();

        $inbox = $this->actingAs($user)->get("http://{$school->slug}.localhost/notifications");
        $inbox->assertOk()->assertDontSee('Archive me')->assertSee('0 unread');

        $archived = $this->actingAs($user)->get("http://{$school->slug}.localhost/notifications?view=archived");
        $archived->assertOk()->assertSee('Archive me');
    }

    /** A user can only ever act on their own notifications, never another user's, even if they guess the id. */
    public function test_a_user_cannot_bulk_act_on_another_users_notification(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'notiffive');
        $other = User::factory()->create();
        $school->users()->attach($other->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'parent')->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $n = Notification::create(['user_id' => $other->id, 'title' => 'Not yours', 'category' => 'announcement', 'type' => 'system']);
        $nId = $n->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/notifications/bulk-action", [
            'notification_ids' => [$nId], 'action' => 'archive',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $stillLive = Notification::find($nId);
        app(TenantManager::class)->forget();
        $this->assertNull($stillLive->archived_at);
    }
}
