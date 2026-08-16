<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * SKULOS Assessment Hub, Phase 2h: the new top-level "Learning
 * Management" nav group, gated purely on Rbac::check() (which already
 * consults Rbac::moduleState() internally) — same pattern already
 * proven by the pre-existing Assignments nav entry, not tied to any
 * Plan's feature catalog.
 */
class CbtNavTest extends TestCase
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

    public function test_an_owner_sees_the_full_learning_management_group_once_the_module_is_enabled(): void
    {
        // The proctoring-only entries (Proctor View, Incidents, Security
        // Profiles, Face Match Approvals) are gated behind
        // App\Support\AssessmentMode, which defaults OFF per the
        // "no proctor/invigilation for now" light-mode scope cut —
        // forced on here since this test is specifically about the
        // full admin nav surface, not the light-mode default.
        config(['skulos.cbt_proctoring_enabled' => true]);

        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'navone']));
        $this->rbac->setModuleState($school, 'assessment_hub', Module::STATE_ENABLED);

        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        $response = $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]));
        $response->assertOk()
            ->assertSee('Learning Management')
            ->assertSee('Question Bank')
            ->assertSee('Proctor / Invigilator View')
            ->assertSee('Incidents');
    }

    public function test_an_owner_does_not_see_proctoring_only_entries_in_light_mode(): void
    {
        // Light mode is the real default now (no config override): a
        // school gets timer/attempts/question bank/randomisation, but
        // no proctoring surfaces at all, until proctoring is switched
        // back on for a real deployment that wants it.
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'navlight']));
        $this->rbac->setModuleState($school, 'assessment_hub', Module::STATE_ENABLED);

        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        $response = $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]));
        $response->assertOk()
            ->assertSee('Learning Management')
            ->assertSee('Question Bank')
            ->assertDontSee('Proctor / Invigilator View')
            ->assertDontSee('Incidents');
    }

    public function test_a_student_only_sees_take_assessments_not_the_admin_entries(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'navtwo']));
        $this->rbac->setModuleState($school, 'assessment_hub', Module::STATE_ENABLED);

        $student = User::factory()->create();
        $studentRole = $this->rbac->cloneTemplateRole($school, 'student');
        $school->users()->attach($student->id, ['role_id' => $studentRole->id, 'status' => 'active']);

        $response = $this->actingAs($student)->get(route('school.dashboard', ['school' => $school->slug]));
        $response->assertOk()
            ->assertSee('Take Assessments')
            ->assertDontSee('Question Bank')
            ->assertDontSee('Proctor / Invigilator View');
    }

    public function test_the_whole_group_is_hidden_when_the_module_is_disabled(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'navthree']));
        // No setModuleState() call at all — a fresh school has no plan
        // assigned and assessment_hub isn't in any real Plan's modules
        // array, so it stays disabled by default, same as Admissions'
        // own precedent.

        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        $response = $this->actingAs($owner)->get(route('school.dashboard', ['school' => $school->slug]));

        // Every Assessment Hub entry is gone, which is what disabling
        // the module has to mean. The "Learning Management" heading
        // itself is deliberately NOT asserted absent any more: since
        // the 2026-08-05 directive moved Assignments into this group,
        // and 'assignments' is a core module every school has, the
        // group legitimately renders for a school with the Assessment
        // Hub switched off. Asserting on the heading was really
        // asserting "this group contains only Assessment Hub", which
        // stopped being true — it is the expectation that went stale,
        // not the gating, and this test failing for that reason is the
        // long-standing failure referenced throughout CLAUDE.md.
        $response->assertOk()
            ->assertDontSee('Question Bank')
            ->assertDontSee('Take Assessments')
            ->assertDontSee('Proctor / Invigilator View')
            ->assertDontSee('Incidents');
    }
}
