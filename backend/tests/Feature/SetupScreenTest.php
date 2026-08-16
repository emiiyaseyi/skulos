<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The Guided Setup wizard had no test coverage at all (found
 * 2026-08-15 while fixing its step indicator), despite being the first
 * screen a brand new school's owner is sent to — the one place where a
 * failure means a paying customer's very first impression is a broken
 * page. These are deliberately smoke-level: that every step renders,
 * and that it is not reachable by someone who should not see it.
 */
class SetupScreenTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config([
            'tenancy.sqlite_path' => $this->tenantDir,
            'tenancy.driver' => 'sqlite',
            'app.domain' => 'localhost',
        ]);
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
        $role = app(Rbac::class)->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $owner];
    }

    public function test_every_setup_step_renders_for_a_school_owner(): void
    {
        [$school, $owner] = $this->schoolWithOwner('setupsmoke');

        // Walks every step rather than just the first: each one renders
        // its own distinct branch of the template, so a broken branch
        // would otherwise only surface for whichever real school
        // happened to reach that step.
        for ($step = 1; $step <= 9; $step++) {
            $this->actingAs($owner)
                ->get(route('school.setup', ['school' => $school->slug, 'step' => $step]))
                ->assertOk();
        }
    }

    public function test_the_step_indicator_labels_cannot_overflow_their_pill(): void
    {
        [$school, $owner] = $this->schoolWithOwner('setuppill');

        $response = $this->actingAs($owner)
            ->get(route('school.setup', ['school' => $school->slug, 'step' => 1]));

        // min-width:0 is what actually stops a nowrap label spilling out
        // over the step number (a flex item will not shrink below its
        // content width without it), so it is asserted explicitly rather
        // than left to be silently dropped by a future style tidy-up.
        $response->assertOk()
            ->assertSee('text-overflow:ellipsis', false)
            ->assertSee('min-width:0', false);
    }

    public function test_a_non_member_cannot_reach_another_schools_setup(): void
    {
        [$school] = $this->schoolWithOwner('setupowned');
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)
            ->get(route('school.setup', ['school' => $school->slug, 'step' => 1]));

        $this->assertContains($response->getStatusCode(), [403, 404, 302]);
    }
}
