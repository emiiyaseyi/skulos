<?php

namespace Tests\Feature;

use App\Models\MarketingPageView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * First-party marketing-site visitor tracking, reported inside SKULOS's
 * own admin backend (direct instruction, 2026-11-06: "admin can also see
 * who's coming to the platform, how long they spent... run targeted
 * campaign"), not only through the external GA4/Clarity dashboards.
 */
class MarketingAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_the_public_beacon_records_a_page_view_with_no_authentication(): void
    {
        $this->postJson('/api/marketing/track', [
            'path' => '/pricing.html',
            'referrer' => 'https://wa.me/',
            'utm_source' => 'whatsapp',
            'utm_campaign' => 'owners-group-nov',
            'session_id' => 'v_abc123',
        ])->assertNoContent();

        $this->assertDatabaseHas('marketing_page_views', [
            'path' => '/pricing.html',
            'utm_source' => 'whatsapp',
            'utm_campaign' => 'owners-group-nov',
            'session_id' => 'v_abc123',
        ]);
    }

    public function test_the_beacon_requires_a_path_and_session_id(): void
    {
        $this->postJson('/api/marketing/track', [])->assertStatus(422);
        $this->assertSame(0, MarketingPageView::count());
    }

    public function test_only_super_admin_can_see_visitor_analytics(): void
    {
        $regular = User::factory()->create();
        $this->actingAs($regular)->get(route('admin.visitors.index'))->assertNotFound();
    }

    public function test_visitor_analytics_aggregates_views_sessions_and_campaign_sources(): void
    {
        $admin = $this->superAdmin();

        // One two-page visitor who came from a named campaign.
        MarketingPageView::create(['path' => '/', 'session_id' => 'v_1', 'utm_campaign' => 'owners-group-nov', 'created_at' => now()->subMinutes(5)]);
        MarketingPageView::create(['path' => '/pricing.html', 'session_id' => 'v_1', 'utm_campaign' => 'owners-group-nov', 'created_at' => now()->subMinutes(3)]);
        // One single-page visitor with no referrer at all.
        MarketingPageView::create(['path' => '/', 'session_id' => 'v_2', 'created_at' => now()->subMinute()]);
        // One stale view outside the 30 day window, must not count.
        MarketingPageView::create(['path' => '/', 'session_id' => 'v_3', 'created_at' => now()->subDays(40)]);

        $response = $this->actingAs($admin)->get(route('admin.visitors.index'));
        $response->assertOk();

        $this->assertSame(3, $response->viewData('totalViews'));
        $this->assertSame(2, $response->viewData('uniqueSessions'));
        $this->assertEquals(50, $response->viewData('singlePageShare'), 'exactly 1 of 2 sessions viewed only one page');
        $this->assertNotNull($response->viewData('avgSpanSeconds'));

        $sources = $response->viewData('sources');
        $this->assertTrue($sources->contains(fn ($s) => $s->source === 'owners-group-nov' && $s->views === 2));
        $this->assertTrue($sources->contains(fn ($s) => $s->source === 'Direct / Unknown'));
    }

    public function test_admin_can_export_trial_requests_as_csv_for_outreach(): void
    {
        $admin = $this->superAdmin();
        \App\Models\TrialRequest::create(['school_name' => 'Export Me', 'contact_name' => 'A', 'email' => 'a@export.test', 'country' => 'Nigeria']);

        $response = $this->actingAs($admin)->get(route('admin.trial-requests.export', ['status' => 'all']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Export Me', $response->getContent());
        $this->assertStringContainsString('a@export.test', $response->getContent());
    }
}
