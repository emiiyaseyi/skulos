<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Setting;
use App\Models\User;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A school's own at-risk thresholds (user directive 2026-07-16: "add it to
 * what admin can configure threshold for under settings"), the config
 * fallback used until a school saves its own.
 */
class AtRiskThresholdSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function schoolWithOwner(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $owner = User::factory()->create();
        $ownerRole = app(\App\Services\Rbac\Rbac::class)->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        return [$school, $owner];
    }

    public function test_with_nothing_saved_the_config_default_shows(): void
    {
        [$school, $owner] = $this->schoolWithOwner('atriskone');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/settings/at-risk-thresholds")
            ->assertOk()
            ->assertSee('value="75"', false)
            ->assertSee('value="40"', false);
    }

    public function test_owner_can_save_their_own_thresholds(): void
    {
        [$school, $owner] = $this->schoolWithOwner('atrisktwo');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/at-risk-thresholds", [
            'attendance_pct' => 80, 'score_pct' => 50,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $saved = Setting::grab('at_risk_thresholds');
        app(TenantManager::class)->forget();

        $this->assertSame(['attendance_pct' => 80, 'score_pct' => 50], $saved);
    }

    public function test_a_non_admin_member_cannot_change_thresholds(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'atriskthree']));
        $teacherRole = app(\App\Services\Rbac\Rbac::class)->cloneTemplateRole($school, 'subject-teacher');
        $teacher = User::factory()->create();
        $school->users()->attach($teacher->id, ['role_id' => $teacherRole->id, 'status' => 'active']);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/settings/at-risk-thresholds", [
            'attendance_pct' => 80, 'score_pct' => 50,
        ])->assertForbidden();
    }
}
