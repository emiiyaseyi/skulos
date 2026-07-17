<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\School;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPayment;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A school's own SKULOS subscription (user request 2026-07-17): real
 * amount due (active learners × plan price), a bank transfer payment
 * with evidence, platform admin verification/reminder/extension, and a
 * "demand payment" full lockout distinct from the pre-existing manual
 * suspend action.
 */
class SubscriptionTest extends TestCase
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
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function schoolWithOwner(string $slug, array $planOverrides = []): array
    {
        $plan = Plan::create(array_merge([
            'name' => 'Test Plan '.$slug, 'slug' => 'plan-'.$slug,
            'price_per_student_per_term' => 500, 'modules' => [], 'features' => [],
        ], $planOverrides));
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug, 'plan_id' => $plan->id]));
        $owner = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $owner];
    }

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_a_school_owner_sees_the_real_amount_due(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subone', ['price_per_student_per_term' => 1000]);

        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'X1', 'first_name' => 'A', 'last_name' => 'B']);
        Student::create(['admission_number' => 'X2', 'first_name' => 'C', 'last_name' => 'D']);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/subscription");
        $response->assertOk()
            ->assertSee('2') // 2 active learners
            ->assertSee('₦2,000.00'); // 2 * 1000
    }

    public function test_a_teacher_cannot_reach_the_subscription_page(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'subtwo']));
        $teacher = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($teacher->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/subscription")->assertForbidden();
    }

    public function test_a_school_owner_can_submit_a_payment_with_evidence(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subthree');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/subscription", [
            'amount' => 25000,
            'bank_name' => 'GTBank',
            'reference' => 'TXN999',
            'evidence' => UploadedFile::fake()->image('proof.jpg', 800, 600),
        ])->assertRedirect();

        $payment = SubscriptionPayment::first();
        $this->assertNotNull($payment);
        $this->assertSame($school->id, $payment->school_id);
        $this->assertSame(SubscriptionPayment::STATUS_PENDING, $payment->status);
        $this->assertSame($owner->id, $payment->submitted_by_user_id);
        $this->assertStringEndsWith('.webp', $payment->evidence_path);
    }

    public function test_a_platform_admin_sees_every_schools_real_amount_due(): void
    {
        [$school, ] = $this->schoolWithOwner('subfour', ['price_per_student_per_term' => 500]);
        app(TenantManager::class)->use($school);
        Student::create(['admission_number' => 'Y1', 'first_name' => 'A', 'last_name' => 'B']);
        app(TenantManager::class)->forget();

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('http://localhost/admin/subscriptions')
            ->assertOk()
            ->assertSee($school->name)
            ->assertSee('₦500.00'); // 1 learner * 500
    }

    /**
     * Audit finding H-2 (2026-07-17): the admin subscriptions index looped
     * every provisioned school into its tenant DB with no per-school
     * try/catch, so one missing/corrupt tenant database 500'd the whole
     * page, hiding every other school's billing data too.
     */
    public function test_a_missing_tenant_database_does_not_break_the_whole_admin_list(): void
    {
        [$healthySchool, ] = $this->schoolWithOwner('subbroken', ['price_per_student_per_term' => 500]);
        app(TenantManager::class)->use($healthySchool);
        Student::create(['admission_number' => 'Z1', 'first_name' => 'A', 'last_name' => 'B']);
        app(TenantManager::class)->forget();

        [$brokenSchool, ] = $this->schoolWithOwner('subbroken2');
        File::delete($this->tenantDir.DIRECTORY_SEPARATOR.$brokenSchool->database.'.sqlite');

        $admin = $this->superAdmin();
        $response = $this->actingAs($admin)->get('http://localhost/admin/subscriptions');

        $response->assertOk()
            ->assertSee($healthySchool->name)
            ->assertSee('₦500.00')
            ->assertSee($brokenSchool->name)
            ->assertSee('Temporarily unavailable');
    }

    /** Audit finding H-7 (2026-07-17): a school suspended for a reason other than billing must not render a misleading green "ok" badge. */
    public function test_a_manually_suspended_school_shows_a_bad_badge_not_ok(): void
    {
        [$school, ] = $this->schoolWithOwner('subsuspended');
        $school->update(['status' => School::STATUS_SUSPENDED, 'suspension_reason' => null]);

        $admin = $this->superAdmin();
        $response = $this->actingAs($admin)->get('http://localhost/admin/subscriptions');

        $response->assertOk();
        $body = $response->getContent();
        $tableStart = strpos($body, '<tbody>');
        $rowStart = strpos($body, $school->name, $tableStart);
        $rowSlice = substr($body, $rowStart, 600);
        $this->assertStringContainsString('badge-bad', $rowSlice);
        $this->assertStringNotContainsString('badge-ok', $rowSlice);
    }

    public function test_admin_can_verify_a_payment_and_the_school_is_notified(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subfive');
        $payment = SubscriptionPayment::create([
            'school_id' => $school->id, 'amount' => 10000, 'status' => SubscriptionPayment::STATUS_PENDING,
            'submitted_by_user_id' => $owner->id,
        ]);

        $admin = $this->superAdmin();
        $this->actingAs($admin)->post("http://localhost/admin/subscriptions/payments/{$payment->id}/verify")
            ->assertRedirect();

        $payment->refresh();
        $this->assertSame(SubscriptionPayment::STATUS_VERIFIED, $payment->status);
        $this->assertSame($admin->id, $payment->verified_by_user_id);

        app(TenantManager::class)->use($school);
        $notified = Notification::where('user_id', $owner->id)->where('category', 'subscription_payment_verified')->exists();
        app(TenantManager::class)->forget();
        $this->assertTrue($notified);
    }

    public function test_admin_can_reject_a_payment(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subsix');
        $payment = SubscriptionPayment::create([
            'school_id' => $school->id, 'amount' => 10000, 'status' => SubscriptionPayment::STATUS_PENDING,
            'submitted_by_user_id' => $owner->id,
        ]);

        $admin = $this->superAdmin();
        $this->actingAs($admin)->post("http://localhost/admin/subscriptions/payments/{$payment->id}/reject")
            ->assertRedirect();

        $this->assertSame(SubscriptionPayment::STATUS_REJECTED, $payment->fresh()->status);
    }

    public function test_admin_can_send_a_reminder(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subseven');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post("http://localhost/admin/subscriptions/{$school->id}/remind")
            ->assertRedirect();

        $this->assertSame(1, SubscriptionEvent::where('school_id', $school->id)->where('type', SubscriptionEvent::TYPE_REMINDER)->count());

        app(TenantManager::class)->use($school);
        $notified = Notification::where('user_id', $owner->id)->where('category', 'subscription_reminder')->exists();
        app(TenantManager::class)->forget();
        $this->assertTrue($notified);
    }

    public function test_admin_can_grant_an_extension_and_the_due_date_moves(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subeight');
        $school->update(['next_billing_date' => now()->addDays(5)]);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post("http://localhost/admin/subscriptions/{$school->id}/extend", [
            'days' => 14, 'note' => 'Goodwill extension',
        ])->assertRedirect();

        $school->refresh();
        $this->assertTrue($school->next_billing_date->isSameDay(now()->addDays(5)->addDays(14)));

        $event = SubscriptionEvent::where('school_id', $school->id)->where('type', SubscriptionEvent::TYPE_EXTENSION)->first();
        $this->assertNotNull($event);
        $this->assertSame('Goodwill extension', $event->note);
    }

    public function test_admin_can_demand_payment_and_the_school_is_locked_out(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subnine');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post("http://localhost/admin/subscriptions/{$school->id}/demand", [
            'note' => 'Three reminders ignored.',
        ])->assertRedirect();

        $school->refresh();
        $this->assertSame(School::STATUS_SUSPENDED, $school->status);
        $this->assertSame(School::SUSPENSION_REASON_BILLING, $school->suspension_reason);

        // The school is now genuinely locked out, a billing specific message, not the generic suspend message.
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard")
            ->assertForbidden()
            ->assertSee('subscription is overdue');
    }

    public function test_admin_can_reinstate_a_billing_locked_school(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subten');
        $school->update(['status' => School::STATUS_SUSPENDED, 'suspension_reason' => School::SUSPENSION_REASON_BILLING]);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post("http://localhost/admin/subscriptions/{$school->id}/reinstate")
            ->assertRedirect();

        $school->refresh();
        $this->assertSame(School::STATUS_ACTIVE, $school->status);
        $this->assertNull($school->suspension_reason);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard")->assertOk();
    }

    public function test_a_manually_suspended_school_still_shows_the_generic_message_not_the_billing_one(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subeleven');
        $school->update(['status' => School::STATUS_SUSPENDED]); // suspension_reason left null, the pre-existing manual path

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard")
            ->assertForbidden()
            ->assertSee('currently suspended')
            ->assertDontSee('subscription is overdue');
    }

    public function test_reinstate_refuses_a_school_suspended_for_a_different_reason(): void
    {
        [$school, ] = $this->schoolWithOwner('subtwelve');
        $school->update(['status' => School::STATUS_SUSPENDED]); // manual suspend, not billing

        $admin = $this->superAdmin();
        $this->actingAs($admin)->post("http://localhost/admin/subscriptions/{$school->id}/reinstate")->assertNotFound();
    }

    public function test_admin_can_save_the_subscription_bank_details(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post('http://localhost/admin/settings/subscription-bank', [
            'bank_name' => 'Zenith Bank', 'account_name' => 'SKULOS Ltd', 'account_number' => '1234567890',
        ])->assertRedirect();

        [$school, $owner] = $this->schoolWithOwner('subthirteen');
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/subscription")
            ->assertOk()
            ->assertSee('Zenith Bank')
            ->assertSee('1234567890');
    }

    public function test_the_daily_popup_shows_within_21_days_of_the_due_date(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subfourteen');
        $school->update(['next_billing_date' => now()->addDays(10)]);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertSee('subPopupScrim', false);
    }

    public function test_the_daily_popup_does_not_show_outside_the_window(): void
    {
        [$school, $owner] = $this->schoolWithOwner('subfifteen');
        $school->update(['next_billing_date' => now()->addDays(45)]);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()
            ->assertDontSee('subPopupScrim', false);
    }
}
