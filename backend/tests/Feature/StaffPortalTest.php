<?php

namespace Tests\Feature;

use App\Mail\AccountCreatedMail;
use App\Models\School;
use App\Models\Tenant\Staff;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Staff Portal (user request 2026-07-12: "lets move to staff and parent
 * portal"). Before this, there was no admin screen anywhere to create a
 * staff record or a login for one, only App\Console\Commands\
 * SeedDemoSchoolData could.
 */
class StaffPortalTest extends TestCase
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

    public function test_owner_can_add_a_staff_member(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stfone');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/create", [
            'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Bursar', 'status' => 'active', 'email' => 'ada.obi@example.com',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $staff = Staff::first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($staff);
        $this->assertSame('Ada Obi', $staff->fullName());
        $this->assertSame('STF/0001', $staff->staff_id, 'staff id is auto generated');
    }

    public function test_email_is_required_by_default_but_phone_is_required_when_the_school_switches_staff_identifier(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stfeight');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/create", [
            'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Bursar', 'status' => 'active',
        ])->assertSessionHasErrors('email');

        app(TenantManager::class)->use($school);
        \App\Models\Tenant\Setting::put('staff_identifier', 'phone');
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/create", [
            'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Bursar', 'status' => 'active',
        ])->assertSessionHasErrors('phone');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/create", [
            'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Bursar', 'status' => 'active', 'phone' => '08031234567',
        ])->assertRedirect()->assertSessionDoesntHaveErrors();
    }

    public function test_owner_can_edit_a_staff_member(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stftwo');

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Bursar', 'status' => 'active']);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/edit", [
            'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Head Bursar', 'status' => 'active', 'email' => 'ada.obi@example.com',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = Staff::find($staffId);
        app(TenantManager::class)->forget();
        $this->assertSame('Head Bursar', $updated->position);
    }

    /**
     * The staff show page renders Staff::user() (a cross connection
     * belongsTo from a tenant model to the platform User model). A plain
     * belongsTo() here silently inherits the tenant connection onto User
     * and throws "no such table: users" — a real bug found live
     * 2026-07-12 by opening the actual page, not caught by any test that
     * only exercises the controller/model in isolation. This test opens
     * the real page, with a login already created, so it cannot regress
     * silently again.
     */
    public function test_the_staff_profile_page_renders_with_a_login_created(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stfseven');

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Bursar', 'status' => 'active']);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/login", [
            'email' => 'ada.obi@example.com', 'role' => 'bursar',
        ]);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/staff/{$staffId}")
            ->assertOk()
            ->assertSee('ada.obi@example.com')
            ->assertSee('Reset password');
    }

    public function test_owner_can_create_a_login_for_a_staff_member_and_an_email_is_sent(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stfthree');

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Bursar', 'status' => 'active']);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/login", [
            'email' => 'ada.obi@example.com', 'role' => 'bursar',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $refreshed = Staff::find($staffId);
        app(TenantManager::class)->forget();

        $this->assertNotNull($refreshed->user_id);
        $loginUser = User::find($refreshed->user_id);
        $this->assertSame('ada.obi@example.com', $loginUser->email);
        $this->assertTrue($school->users()->whereKey($loginUser->id)->exists());
        Mail::assertSent(AccountCreatedMail::class, fn ($mail) => $mail->hasTo('ada.obi@example.com'));
    }

    public function test_owner_can_reset_and_revoke_a_staff_login(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stffour');

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Bursar', 'status' => 'active']);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/login", [
            'email' => 'ada.obi@example.com', 'role' => 'bursar',
        ]);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/login/reset-password")
            ->assertRedirect();
        Mail::assertSent(\App\Mail\PasswordResetByAdminMail::class);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/login/toggle-access")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $refreshed = Staff::find($staffId);
        app(TenantManager::class)->forget();
        $status = $school->users()->whereKey($refreshed->user_id)->first()->pivot->status;
        $this->assertSame('suspended', $status);
    }

    public function test_bulk_status_change_and_delete(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stffive');

        app(TenantManager::class)->use($school);
        $one = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'A', 'last_name' => 'B', 'position' => 'Bursar', 'status' => 'active']);
        $two = Staff::create(['staff_id' => 'STF/0002', 'first_name' => 'C', 'last_name' => 'D', 'position' => 'Bursar', 'status' => 'active']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/bulk-action", [
            'staff_ids' => [$one->id], 'action' => 'on_leave',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame('on_leave', Staff::find($one->id)->status);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/bulk-action", [
            'staff_ids' => [$two->id], 'action' => 'delete',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertNull(Staff::find($two->id));
        app(TenantManager::class)->forget();
    }

    public function test_a_teacher_without_staff_update_cannot_create_a_login(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'stfsix');

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'A', 'last_name' => 'B', 'position' => 'Bursar', 'status' => 'active']);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/staff/{$staffId}/login", [
            'email' => 'x@example.com', 'role' => 'bursar',
        ])->assertForbidden();
    }

    /**
     * A staff member's one base role should not be their ceiling (user
     * request 2026-07-12: "a staff can be given more roles/features to
     * handle from the list of features... the school has under their
     * plans from billing"). A Bursar has no report_cards access by
     * default; granting it as extra access must actually work.
     */
    public function test_owner_can_grant_extra_module_access_beyond_the_staffs_base_role(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stfnine');

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Bursar', 'status' => 'active']);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/login", [
            'email' => 'ada.obi@example.com', 'role' => 'bursar',
        ]);

        app(TenantManager::class)->use($school);
        $refreshed = Staff::find($staffId);
        $bursarUser = User::find($refreshed->user_id);
        app(TenantManager::class)->forget();

        $this->assertFalse($this->rbac->check($bursarUser, $school, 'report_cards.view'), 'a plain Bursar has no report card access by default');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/extra-access", [
            'modules' => ['report_cards'],
        ])->assertRedirect();

        $this->assertTrue($this->rbac->check($bursarUser, $school, 'report_cards.view'), 'the extra access grant must actually unlock the module');
        $this->assertTrue($this->rbac->check($bursarUser, $school, 'report_cards.update'), 'grant is full module access, matching what a role\'s "module.*" grant would give');

        // Unchecking it again must revoke, not just leave stale allows behind.
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/extra-access", [
            'modules' => [],
        ])->assertRedirect();

        $this->assertFalse($this->rbac->check($bursarUser, $school, 'report_cards.view'), 'unticking the box must revoke the extra access');
    }

    public function test_a_module_not_on_the_curated_list_cannot_be_granted(): void
    {
        Mail::fake();
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stften');

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Bursar', 'status' => 'active']);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/login", [
            'email' => 'ada.obi@example.com', 'role' => 'bursar',
        ]);

        // "finance" has no real screen behind it yet, so it is not on the curated list.
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/extra-access", [
            'modules' => ['finance'],
        ])->assertSessionHasErrors('modules.0');
    }

    public function test_the_extra_access_section_only_appears_once_the_staff_member_has_a_login(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stfeleven');

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Bursar', 'status' => 'active']);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/staff/{$staffId}")
            ->assertOk()->assertDontSee('Extra access');
    }

    /**
     * Undo/restore (user request 2026-07-12, "To Do List 2" doc item 3): a
     * bulk delete must not be a one-way door. Deleting via the existing
     * bulk-action endpoint already soft deletes (test_bulk_status_change_and_delete
     * above), this covers the other half, the Trashed screen actually
     * bringing a staff member back.
     */
    public function test_a_deleted_staff_member_can_be_restored_from_trashed(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stftwelve');

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Bursar', 'status' => 'active']);
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/bulk-action", [
            'staff_ids' => [$staffId], 'action' => 'delete',
        ])->assertRedirect();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/staff/trashed")
            ->assertOk()->assertSee('Ada Obi');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/trashed/bulk-action", [
            'staff_ids' => [$staffId], 'action' => 'restore',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $restored = Staff::find($staffId);
        app(TenantManager::class)->forget();
        $this->assertNotNull($restored);
        $this->assertSame('Ada Obi', $restored->fullName());
    }

    /**
     * Permanently deleting a staff member who is a class teacher,
     * assistant teacher, or housemaster must not leave those
     * assignments dangling, those columns are plain ids with no
     * database level foreign key (found while building this feature).
     */
    public function test_permanently_deleting_a_staff_member_clears_dangling_teaching_assignments(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stfthirteen');

        app(TenantManager::class)->use($school);
        $staff = Staff::create(['staff_id' => 'STF/0001', 'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Teacher', 'status' => 'active']);
        $staffId = $staff->id;
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null, 'class_teacher_staff_id' => $staffId]);
        $armId = $arm->id;
        $staff->delete();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/trashed/bulk-action", [
            'staff_ids' => [$staffId], 'action' => 'force-delete',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertNull(Staff::withTrashed()->find($staffId));
        $this->assertNull(\App\Models\Tenant\ClassArm::find($armId)->class_teacher_staff_id);
        app(TenantManager::class)->forget();
    }

    /** A staff member with an active login is not silently force deleted, the admin must revoke the login first. */
    public function test_force_delete_skips_a_staff_member_who_still_has_a_login(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stffourteen');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/create", [
            'first_name' => 'Ada', 'last_name' => 'Obi', 'position' => 'Bursar', 'status' => 'active', 'email' => 'ada.obi@example.com',
        ]);
        app(TenantManager::class)->use($school);
        $staff = Staff::first();
        $staffId = $staff->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/{$staffId}/login", [
            'email' => 'ada.obi@example.com', 'role' => 'bursar',
        ]);

        app(TenantManager::class)->use($school);
        $staff->delete();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/staff/trashed/bulk-action", [
            'staff_ids' => [$staffId], 'action' => 'force-delete',
        ])->assertRedirect()->assertSessionHas('status', function ($status) {
            return str_contains($status, 'skipped');
        });

        app(TenantManager::class)->use($school);
        $this->assertNotNull(Staff::withTrashed()->find($staffId));
        app(TenantManager::class)->forget();
    }
}
