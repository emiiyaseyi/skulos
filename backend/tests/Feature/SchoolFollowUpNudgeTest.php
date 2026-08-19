<?php

namespace Tests\Feature;

use App\Mail\SchoolFollowUpNudgeMail;
use App\Mail\TrialRequestInfoRequestMail;
use App\Models\School;
use App\Models\TrialRequest;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The manual "checking in on your setup" nudge and the "ask before
 * deciding" question (2026-08-19), both from the platform admin side:
 * "under the trial and new schools created, let's add a nudge button
 * there... admin should be able to send a mail to each school
 * requesting for more details about their trial request."
 */
class SchoolFollowUpNudgeTest extends TestCase
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

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    protected function schoolWithOwner(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug, 'name' => 'Nudge School']));
        $owner = User::factory()->create(['name' => 'Ada Owner', 'email' => 'owner@example.com']);
        $role = app(Rbac::class)->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $owner];
    }

    public function test_nudging_a_school_emails_every_active_owner(): void
    {
        [$school, $owner] = $this->schoolWithOwner('nudgeone');
        Mail::fake();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.schools.nudge', $school))
            ->assertRedirect();

        Mail::assertSent(SchoolFollowUpNudgeMail::class, fn ($mail) => $mail->hasTo($owner->email) && $mail->school->is($school));
    }

    public function test_a_revoked_admin_is_not_nudged(): void
    {
        [$school, $owner] = $this->schoolWithOwner('nudgetwo');
        $school->users()->updateExistingPivot($owner->id, ['status' => 'revoked']);
        Mail::fake();

        $this->actingAs($this->superAdmin())->post(route('admin.schools.nudge', $school));

        Mail::assertNotSent(SchoolFollowUpNudgeMail::class);
    }

    public function test_only_a_super_admin_can_nudge_a_school(): void
    {
        [$school] = $this->schoolWithOwner('nudgethree');

        $this->actingAs(User::factory()->create())
            ->post(route('admin.schools.nudge', $school))
            ->assertNotFound();
    }

    public function test_nudging_an_approved_trial_emails_its_real_school_admin(): void
    {
        [$school, $owner] = $this->schoolWithOwner('nudgefour');
        $trial = TrialRequest::create([
            'school_name' => 'Nudge School', 'contact_name' => 'Ada Owner', 'email' => $owner->email,
            'phone' => '0800', 'status' => TrialRequest::STATUS_APPROVED, 'school_id' => $school->id,
        ]);
        Mail::fake();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.trial-requests.nudge', $trial))
            ->assertRedirect();

        Mail::assertSent(SchoolFollowUpNudgeMail::class, fn ($mail) => $mail->hasTo($owner->email));
    }

    public function test_a_pending_trial_with_no_school_yet_cannot_be_nudged(): void
    {
        $trial = TrialRequest::create([
            'school_name' => 'Not Yet', 'contact_name' => 'Ada', 'email' => 'ada@example.com',
            'phone' => '0800', 'status' => TrialRequest::STATUS_PENDING,
        ]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.trial-requests.nudge', $trial))
            ->assertNotFound();
    }

    public function test_requesting_more_info_emails_the_requester_without_changing_its_status(): void
    {
        $trial = TrialRequest::create([
            'school_name' => 'Needs Info', 'contact_name' => 'Bola', 'email' => 'bola@example.com',
            'phone' => '0800', 'status' => TrialRequest::STATUS_PENDING,
        ]);
        Mail::fake();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.trial-requests.request-info', $trial), [
                'message' => 'Could you share your full school address?',
            ])->assertRedirect();

        Mail::assertSent(TrialRequestInfoRequestMail::class, fn ($mail) => $mail->hasTo('bola@example.com')
            && str_contains($mail->message, 'full school address'));

        // Asking a question is not a decision -- the request must stay
        // exactly where it was, still visible in the pending queue.
        $this->assertSame(TrialRequest::STATUS_PENDING, $trial->fresh()->status);
    }

    public function test_requesting_more_info_requires_a_real_message(): void
    {
        $trial = TrialRequest::create([
            'school_name' => 'Needs Info', 'contact_name' => 'Bola', 'email' => 'bola@example.com',
            'phone' => '0800', 'status' => TrialRequest::STATUS_PENDING,
        ]);
        Mail::fake();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.trial-requests.request-info', $trial), ['message' => ''])
            ->assertSessionHasErrors(['message']);

        Mail::assertNothingSent();
    }
}
