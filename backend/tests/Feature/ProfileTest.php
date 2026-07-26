<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\ProfileChangeRequest;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A school member's own profile (user request: "a user profile page
 * (click name to view/edit, set preferences) gated behind a
 * school-admin approval workflow before any change takes effect").
 * A member trusted with staff.update applies their own edits
 * immediately; everyone else's are held for approval.
 */
class ProfileTest extends TestCase
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

    protected function schoolWithMember(string $roleSlug, string $slug, array $userAttrs = []): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $user = User::factory()->create($userAttrs);
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_an_owner_can_view_their_own_profile(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'profileone');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/profile")
            ->assertOk()->assertSee($owner->name);
    }

    /** An owner holds staff.update, so their own edit applies immediately, no approval needed. */
    public function test_an_owner_editing_their_own_profile_applies_immediately(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'profiletwo', ['name' => 'Old Name']);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/profile", [
            'name' => 'New Name',
            'email' => $owner->email,
            'phone' => $owner->phone,
        ])->assertRedirect();

        $this->assertSame('New Name', $owner->fresh()->name);

        app(TenantManager::class)->use($school);
        $pendingCount = ProfileChangeRequest::count();
        app(TenantManager::class)->forget();
        $this->assertSame(0, $pendingCount, 'an owner\'s own edit should never create an approval request');
    }

    /** A subject teacher lacks staff.update, so their edit is held for approval instead of applying. */
    public function test_a_teacher_editing_their_own_profile_is_held_for_approval(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'profilethree', ['name' => 'Old Name']);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/profile", [
            'name' => 'New Name',
            'email' => $teacher->email,
            'phone' => $teacher->phone,
        ])->assertRedirect()->assertSessionHas('status', fn ($m) => str_contains($m, 'sent to'));

        $this->assertSame('Old Name', $teacher->fresh()->name, 'the name must not change until approved');

        app(TenantManager::class)->use($school);
        $pending = ProfileChangeRequest::where('user_id', $teacher->id)->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($pending);
        $this->assertSame(ProfileChangeRequest::STATUS_PENDING, $pending->status);
        $this->assertSame('New Name', $pending->requested['name']);
        $this->assertSame('Old Name', $pending->original['name']);
    }

    /**
     * Real bug found live, 2026-07-24 (user report: "a user made a
     * profile change that requires admin review and approval, admin
     * did not see it as a notification which from where admin can
     * access the request, review, approve or reject with comment").
     * Every active member whose role grants staff.update (the same
     * permission the approvals page itself requires) must get an in
     * app notification, someone without it must not.
     */
    public function test_a_profile_change_request_notifies_every_staff_update_holder(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'profilenotify', ['name' => 'Old Name']);
        $owner = User::factory()->create();
        $ownerRole = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $ownerRole->id, 'status' => 'active']);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/profile", [
            'name' => 'New Name',
            'email' => $teacher->email,
            'phone' => $teacher->phone,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $ownerNotified = \App\Models\Tenant\Notification::where('user_id', $owner->id)->where('category', 'profile_approval')->exists();
        $teacherNotified = \App\Models\Tenant\Notification::where('user_id', $teacher->id)->where('category', 'profile_approval')->exists();
        app(TenantManager::class)->forget();

        $this->assertTrue($ownerNotified, 'the owner holds staff.update and must be notified');
        $this->assertFalse($teacherNotified, 'the requester themselves is not an approver and must not be notified');
    }

    public function test_submitting_with_no_actual_changes_does_not_create_a_request(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'profilefour');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/profile", [
            'name' => $teacher->name,
            'email' => $teacher->email,
            'phone' => $teacher->phone,
        ])->assertRedirect()->assertSessionHas('status', 'Nothing changed.');

        app(TenantManager::class)->use($school);
        $count = ProfileChangeRequest::count();
        app(TenantManager::class)->forget();
        $this->assertSame(0, $count);
    }

    /** A second edit while one is still pending supersedes the first, not stacks alongside it. */
    public function test_a_second_pending_request_supersedes_the_first(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'profilefive');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/profile", [
            'name' => 'First Try', 'email' => $teacher->email, 'phone' => $teacher->phone,
        ]);
        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/profile", [
            'name' => 'Second Try', 'email' => $teacher->email, 'phone' => $teacher->phone,
        ]);

        app(TenantManager::class)->use($school);
        $pendingCount = ProfileChangeRequest::where('user_id', $teacher->id)->where('status', ProfileChangeRequest::STATUS_PENDING)->count();
        $latest = ProfileChangeRequest::where('user_id', $teacher->id)->where('status', ProfileChangeRequest::STATUS_PENDING)->first();
        app(TenantManager::class)->forget();

        $this->assertSame(1, $pendingCount, 'only the newest request should remain pending');
        $this->assertSame('Second Try', $latest->requested['name']);
    }

    public function test_an_admin_can_approve_a_pending_request_and_it_applies(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'profilesix');
        $teacher = User::factory()->create(['name' => 'Old Name']);
        $school->users()->attach($teacher->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'subject-teacher')->id, 'status' => 'active']);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/profile", [
            'name' => 'New Name', 'email' => $teacher->email, 'phone' => $teacher->phone,
        ]);

        app(TenantManager::class)->use($school);
        $requestId = ProfileChangeRequest::where('user_id', $teacher->id)->first()->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/profile/approvals/{$requestId}/approve")
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame('New Name', $teacher->fresh()->name);

        app(TenantManager::class)->use($school);
        $status = ProfileChangeRequest::find($requestId)->status;
        $applicantNotified = \App\Models\Tenant\Notification::where('user_id', $teacher->id)->where('category', 'profile_change_approved')->exists();
        app(TenantManager::class)->forget();
        $this->assertSame(ProfileChangeRequest::STATUS_APPROVED, $status);
        $this->assertTrue($applicantNotified, 'the applicant must be told their own request was approved');
    }

    public function test_an_admin_can_reject_a_pending_request_with_a_reason(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'profileseven');
        $teacher = User::factory()->create(['name' => 'Old Name']);
        $school->users()->attach($teacher->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'subject-teacher')->id, 'status' => 'active']);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/profile", [
            'name' => 'New Name', 'email' => $teacher->email, 'phone' => $teacher->phone,
        ]);

        app(TenantManager::class)->use($school);
        $requestId = ProfileChangeRequest::where('user_id', $teacher->id)->first()->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/profile/approvals/{$requestId}/reject", [
            'notes' => 'Please use your legal name.',
        ])->assertRedirect();

        $this->assertSame('Old Name', $teacher->fresh()->name, 'a rejected change must never apply');

        app(TenantManager::class)->use($school);
        $rejected = ProfileChangeRequest::find($requestId);
        $applicantNotified = \App\Models\Tenant\Notification::where('user_id', $teacher->id)
            ->where('category', 'profile_change_rejected')
            ->where('body', 'like', '%Please use your legal name.%')
            ->exists();
        app(TenantManager::class)->forget();
        $this->assertSame(ProfileChangeRequest::STATUS_REJECTED, $rejected->status);
        $this->assertSame('Please use your legal name.', $rejected->notes);
        $this->assertTrue($applicantNotified, 'the applicant must be told their own request was rejected, with the reason');
    }

    public function test_rejecting_without_a_reason_fails_validation(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'profileeight');
        $teacher = User::factory()->create();
        $school->users()->attach($teacher->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'subject-teacher')->id, 'status' => 'active']);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/profile", [
            'name' => 'New Name', 'email' => $teacher->email, 'phone' => $teacher->phone,
        ]);

        app(TenantManager::class)->use($school);
        $requestId = ProfileChangeRequest::where('user_id', $teacher->id)->first()->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/profile/approvals/{$requestId}/reject", [])
            ->assertSessionHasErrors('notes');
    }

    public function test_bulk_approve_and_bulk_reject_both_notify_the_applicants(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'profilebulk');
        $teacherA = User::factory()->create(['name' => 'Teacher A']);
        $teacherB = User::factory()->create(['name' => 'Teacher B']);
        $school->users()->attach($teacherA->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'subject-teacher')->id, 'status' => 'active']);
        $school->users()->attach($teacherB->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'subject-teacher')->id, 'status' => 'active']);

        $this->actingAs($teacherA)->post("http://{$school->slug}.localhost/profile", [
            'name' => 'A New Name', 'email' => $teacherA->email, 'phone' => $teacherA->phone,
        ]);
        $this->actingAs($teacherB)->post("http://{$school->slug}.localhost/profile", [
            'name' => 'B New Name', 'email' => $teacherB->email, 'phone' => $teacherB->phone,
        ]);

        app(TenantManager::class)->use($school);
        $requestIdA = ProfileChangeRequest::where('user_id', $teacherA->id)->first()->id;
        $requestIdB = ProfileChangeRequest::where('user_id', $teacherB->id)->first()->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/profile/approvals/bulk-approve", [
            'request_ids' => [$requestIdA],
        ])->assertRedirect();
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/profile/approvals/bulk-reject", [
            'request_ids' => [$requestIdB], 'notes' => 'Not this time.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $aApproved = \App\Models\Tenant\Notification::where('user_id', $teacherA->id)->where('category', 'profile_change_approved')->exists();
        $bRejected = \App\Models\Tenant\Notification::where('user_id', $teacherB->id)->where('category', 'profile_change_rejected')->exists();
        app(TenantManager::class)->forget();

        $this->assertTrue($aApproved, 'bulk approve must notify the applicant');
        $this->assertTrue($bRejected, 'bulk reject must notify the applicant');
    }

    public function test_a_teacher_without_staff_update_cannot_view_the_approvals_inbox(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'profilenine');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/profile/approvals")
            ->assertForbidden();
    }

    public function test_email_must_be_unique_across_the_platform(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'profileten');
        $other = User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/profile", [
            'name' => $teacher->name, 'email' => 'taken@example.com', 'phone' => $teacher->phone,
        ])->assertSessionHasErrors('email');
    }
}
