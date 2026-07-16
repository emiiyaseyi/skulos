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
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Dashboard Announcements ticker (rebuilt 2026-07-16, user directive: "IT
 * SHOULD NOT BE SHOWING ALL THE ANNOUNCEMENTS... THE USER CAN DECIDE TO
 * DISMISS IT BUT IT RELOADS WHEN USER LOGIN AGAIN AND THE USER ONLY SEE
 * NEW ONE"). Was previously the latest 3 published Announcement rows
 * school wide, shown identically to every signed-in user regardless of
 * whether they were ever an actual recipient, with no dismiss state at
 * all. Now reuses the exact same per-user Notification.read_at tracking
 * the broadcast pop-up already has.
 */
class DashboardAnnouncementTickerTest extends TestCase
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

    public function test_a_recipient_sees_the_announcement_they_were_actually_sent(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attickone');

        $this->actingAs($owner)->post(route('school.announcements.store', ['school' => $school->slug]), [
            'title' => 'School closed Friday', 'body' => 'No classes this Friday.', 'audience' => 'all',
        ]);

        $response = $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]));
        $response->assertOk()->assertSee('School closed Friday');
    }

    /**
     * The old behavior showed the latest 3 announcements to literally
     * everyone regardless of whether they were ever a recipient. A
     * teacher who was never sent this owner-only-audience announcement
     * must not see it.
     */
    public function test_a_non_recipient_does_not_see_an_announcement_they_were_never_sent(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'atticktwo');
        $teacher = User::factory()->create();
        $teacherRole = $this->rbac->cloneTemplateRole($school, 'teacher');
        $school->users()->attach($teacher->id, ['role_id' => $teacherRole->id, 'status' => 'active']);

        $this->actingAs($owner)->post(route('school.announcements.store', ['school' => $school->slug]), [
            'title' => 'Parents only notice', 'body' => 'For parents.', 'audience' => 'parents',
        ]);

        // "parents" audience genuinely excludes a teacher (unlike "staff",
        // which would actually reach them too), scoped to the ticker
        // itself since the notification bell's own recent-activity feed
        // is a separate, unrelated widget that intentionally still lists
        // read items.
        $response = $this->actingAs($teacher)->get(route('school.dashboard', ['school' => $school->slug]));
        $response->assertOk();
        $this->assertStringNotContainsString('Parents only notice', $this->extractTicker($response->getContent()));
    }

    public function test_dismissing_persists_and_the_announcement_does_not_reappear_on_reload(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attickthree');

        $this->actingAs($owner)->post(route('school.announcements.store', ['school' => $school->slug]), [
            'title' => 'Sports day moved', 'body' => 'Now on Saturday.', 'audience' => 'all',
        ]);

        app(TenantManager::class)->use($school);
        $notification = Notification::forUser($owner->id)->where('category', 'announcement')->firstOrFail();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.notifications.read', ['school' => $school->slug, 'id' => $notification->id]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $refreshed = Notification::find($notification->id);
        app(TenantManager::class)->forget();
        $this->assertNotNull($refreshed->read_at, 'read_at must actually be persisted by the POST');

        $response = $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]));
        $response->assertOk();
        // Scoped to the ticker itself: the notification bell's own
        // recent-activity feed (Notification::feedFor()) intentionally
        // still lists read items, that is a separate, correct concern,
        // not a sign the dismiss failed.
        $this->assertStringNotContainsString('Sports day moved', $this->extractTicker($response->getContent()));
    }

    public function test_a_new_announcement_after_dismissing_the_old_one_still_shows(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attickfour');

        $this->actingAs($owner)->post(route('school.announcements.store', ['school' => $school->slug]), [
            'title' => 'First notice', 'body' => 'Body one.', 'audience' => 'all',
        ]);
        app(TenantManager::class)->use($school);
        $first = Notification::forUser($owner->id)->where('category', 'announcement')->firstOrFail();
        app(TenantManager::class)->forget();
        $this->actingAs($owner)->post(route('school.notifications.read', ['school' => $school->slug, 'id' => $first->id]));

        $this->actingAs($owner)->post(route('school.announcements.store', ['school' => $school->slug]), [
            'title' => 'Second notice', 'body' => 'Body two.', 'audience' => 'all',
        ]);

        $response = $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]));
        $response->assertOk()->assertSee('Second notice');
        $this->assertStringNotContainsString('First notice', $this->extractTicker($response->getContent()));
    }

    /**
     * Scopes an assertion to just the ticker widget's own markup; the
     * notification bell's own recent-activity feed is a separate,
     * unrelated widget that intentionally still lists read items, so a
     * plain whole-page assertDontSee would false-fail on a dismissed
     * item that is still, correctly, in that other feed. Walks div-tag
     * balance from the card's own opening tag to find its true closing
     * tag, the card contains nested divs so a naive first-</div> match
     * would cut the slice short.
     */
    protected function extractTicker(string $html): string
    {
        $markerPos = strpos($html, 'data-ann-ticker');
        if ($markerPos === false) {
            return '';
        }
        $start = strrpos(substr($html, 0, $markerPos), '<div');
        $depth = 0;
        $pos = $start;
        $end = strlen($html);
        while (preg_match('/<div\b|<\/div>/', $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $depth += $m[0][0] === '</div>' ? -1 : 1;
            $pos = $m[0][1] + strlen($m[0][0]);
            if ($depth === 0) {
                $end = $pos;
                break;
            }
        }

        return substr($html, $start, $end - $start);
    }

    public function test_no_ticker_renders_when_there_is_nothing_unread(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'attickfive');

        $response = $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]));

        $response->assertOk()->assertDontSee('data-ann-ticker', false);
    }
}
