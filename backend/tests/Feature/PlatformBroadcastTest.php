<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Send Broadcast (Dashboard Design Cross-Check, 2026-07-13: "Blocked, no
 * platform-wide broadcast tool exists"). A real message to a school's
 * leadership tier by email, not every user at the school.
 */
class PlatformBroadcastTest extends TestCase
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

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    protected function schoolWithOwnerAndTeacher(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $owner = User::factory()->create(['email' => "owner-{$slug}@example.com"]);
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        $teacher = User::factory()->create(['email' => "teacher-{$slug}@example.com"]);
        $teacherRole = $this->rbac->cloneTemplateRole($school, 'teacher');
        $school->users()->attach($teacher->id, ['role_id' => $teacherRole->id, 'status' => 'active']);

        return [$school, $owner, $teacher];
    }

    public function test_broadcast_to_all_schools_reaches_only_leadership_not_every_user(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();
        [$school, $owner, $teacher] = $this->schoolWithOwnerAndTeacher('bcastone');

        $this->actingAs($admin)->post(route('admin.broadcast.send'), [
            'audience' => 'all', 'subject' => 'Platform maintenance', 'body' => 'We will be down for maintenance Friday.',
        ])->assertRedirect(route('admin.dashboard'));

        Mail::assertSent(\App\Mail\PlatformBroadcastMail::class, function ($mail) use ($owner) {
            return $mail->hasTo($owner->email);
        });
        Mail::assertNotSent(\App\Mail\PlatformBroadcastMail::class, function ($mail) use ($teacher) {
            return $mail->hasTo($teacher->email);
        });
    }

    public function test_broadcast_to_a_specific_plan_only_reaches_schools_on_that_plan(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();
        $plan = Plan::create(['name' => 'Advance', 'slug' => 'advance-bcast', 'price_per_student_per_term' => 800, 'modules' => [], 'features' => []]);
        [$onPlan, $onPlanOwner] = $this->schoolWithOwnerAndTeacher('bcasttwo');
        $onPlan->update(['plan_id' => $plan->id]);
        [$offPlan, $offPlanOwner] = $this->schoolWithOwnerAndTeacher('bcastthree');

        $this->actingAs($admin)->post(route('admin.broadcast.send'), [
            'audience' => 'plan', 'plan_id' => $plan->id, 'subject' => 'Plan news', 'body' => 'Something new for Advance schools.',
        ])->assertRedirect();

        Mail::assertSent(\App\Mail\PlatformBroadcastMail::class, fn ($mail) => $mail->hasTo($onPlanOwner->email));
        Mail::assertNotSent(\App\Mail\PlatformBroadcastMail::class, fn ($mail) => $mail->hasTo($offPlanOwner->email));
    }

    public function test_broadcast_to_selected_schools_reaches_only_those_schools(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();
        [$selected, $selectedOwner] = $this->schoolWithOwnerAndTeacher('bcastfour');
        [$notSelected, $notSelectedOwner] = $this->schoolWithOwnerAndTeacher('bcastfive');

        $this->actingAs($admin)->post(route('admin.broadcast.send'), [
            'audience' => 'selected', 'school_ids' => [$selected->id], 'subject' => 'Just you', 'body' => 'Hello.',
        ])->assertRedirect();

        Mail::assertSent(\App\Mail\PlatformBroadcastMail::class, fn ($mail) => $mail->hasTo($selectedOwner->email));
        Mail::assertNotSent(\App\Mail\PlatformBroadcastMail::class, fn ($mail) => $mail->hasTo($notSelectedOwner->email));
    }

    public function test_broadcast_records_an_audit_entry(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();
        $this->schoolWithOwnerAndTeacher('bcastsix');

        $this->actingAs($admin)->post(route('admin.broadcast.send'), [
            'audience' => 'all', 'subject' => 'Audited message', 'body' => 'Body text.',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.broadcast_sent']);
    }

    public function test_a_non_super_admin_cannot_send_a_broadcast(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.broadcast.create'))->assertNotFound();
    }
}
