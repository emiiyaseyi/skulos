<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Dashboard\DashboardPreferences;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DashboardPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected School $school;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);

        $this->school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'dash', 'name' => 'Dash School']));
        app(TenantManager::class)->use($this->school);

        $this->member = User::factory()->create();
        $this->school->users()->attach($this->member->id, ['status' => 'active']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    public function test_default_layout_and_widgets(): void
    {
        $prefs = app(DashboardPreferences::class);
        $this->assertSame('classic', $prefs->layout());
        $this->assertTrue($prefs->shows('notifications'));
        $this->assertTrue($prefs->shows('calendar'));
    }

    public function test_setting_layout_and_widgets_persists(): void
    {
        $prefs = app(DashboardPreferences::class);
        $prefs->setLayout('erp');
        $prefs->setWidgets(['stats' => true, 'notifications' => false, 'calendar' => true, 'greeting' => true]);

        $this->assertSame('erp', app(DashboardPreferences::class)->layout());
        $this->assertFalse(app(DashboardPreferences::class)->shows('notifications'));
    }

    public function test_invalid_layout_falls_back_to_default(): void
    {
        app(DashboardPreferences::class)->setLayout('not-a-layout');
        $this->assertSame('classic', app(DashboardPreferences::class)->layout());
    }

    public function test_settings_page_lists_the_four_named_layouts(): void
    {
        $res = $this->actingAs($this->member)->get('http://dash.localhost/settings/dashboard');
        $res->assertOk()
            ->assertSee('Classic School')->assertSee('Vibrant')
            ->assertSee('ERP')->assertSee('Modern');
    }

    public function test_admin_can_change_layout_through_the_settings_form(): void
    {
        $this->actingAs($this->member)->post('http://dash.localhost/settings/dashboard', [
            'layout' => 'modern',
            'widgets' => ['stats' => '1', 'notifications' => '1'],
        ])->assertRedirect();

        $this->assertSame('modern', app(DashboardPreferences::class)->layout());
        $this->assertFalse(app(DashboardPreferences::class)->shows('calendar')); // unchecked
    }

    public function test_dashboard_renders_for_each_layout(): void
    {
        foreach (array_keys(config('skulos.dashboard_layouts')) as $layout) {
            app(DashboardPreferences::class)->setLayout($layout);
            $this->actingAs($this->member)->get('http://dash.localhost/dashboard')
                ->assertOk()->assertSee('Dash School');
        }
    }

    public function test_hidden_widget_does_not_render(): void
    {
        app(DashboardPreferences::class)->setWidgets(['stats' => true, 'notifications' => false, 'calendar' => true, 'greeting' => true]);

        // A bare ">Notifications<" text check is no longer specific enough
        // (found 2026-07-18): the sidebar nav's own, unrelated "Notifications"
        // link (the in-app notification center, App\Http\Controllers\School\
        // NotificationController) renders the same text on every dashboard
        // page it's enabled for, which the default plan's feature list now
        // includes. The notifications *widget* (this dashboard's own recent-
        // activity card, a completely different thing) carries its own
        // data-widget marker instead, so this only checks the widget itself.
        $this->actingAs($this->member)->get('http://dash.localhost/dashboard')
            ->assertOk()
            ->assertDontSee('data-widget="dashboard-notifications-feed"', false);
    }
}
