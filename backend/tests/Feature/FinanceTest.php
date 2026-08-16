<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\FeeItem;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Payment;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\Tenant\Term;
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
 * Foundation Finance slice (2026-07-17, "a deliberately minimal slice, not
 * the full deferred Finance module from section 5b"): Bank Transfer
 * Payment Option, Payment Evidence Upload, Payment Verification, Basic
 * Fee Records. No payment gateway, no invoicing automation.
 */
class FinanceTest extends TestCase
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
        Storage::fake('local'); // evidence now lives on the private disk, audit finding M-2, 2026-07-17
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
        // No PlanSeeder run in these tests (only RbacSeeder), so there is
        // no default plan for Rbac::moduleState()'s plan fallback to find;
        // enable the module explicitly instead, the same direct pattern
        // RbacTest already uses for its own module-gated (Library) tests.
        $this->rbac->setModuleState($school, 'finance', \App\Models\Module::STATE_ENABLED);

        return [$school, $user];
    }

    protected function currentTerm(): Term
    {
        $session = AcademicSession::create(['name' => '2025/2026']);

        return Term::create([
            'academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true,
            'starts_on' => now()->subDays(10), 'ends_on' => now()->addDays(50),
        ]);
    }

    public function test_a_bursar_can_manage_the_fee_catalog(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finone');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items", [
            'name' => 'Tuition', 'amount' => 50000, 'term_id' => $term->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $feeItem = FeeItem::first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($feeItem);
        $this->assertSame('Tuition', $feeItem->name);
        $this->assertSame('50000.00', (string) $feeItem->amount);

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items/{$feeItem->id}/toggle-active")
            ->assertRedirect();
        app(TenantManager::class)->use($school);
        $this->assertFalse($feeItem->fresh()->is_active);
        app(TenantManager::class)->forget();
    }

    /**
     * User report 2026-07-26: "parents should not be seeing the
     * individual breakdown of the fees like this, just fee schedule is
     * okay to be seen." The per-fee-item list (term/classes/
     * installments/status, real finance-office detail) is restricted to
     * $canManage; the schedule matrix above it still shows for everyone
     * with finance.view.
     */
    public function test_a_parent_sees_the_fee_schedule_but_not_the_individual_fee_item_breakdown(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finhidebreakdown');
        $parent = User::factory()->create();
        $parentRole = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parent->id, ['role_id' => $parentRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        FeeItem::create(['name' => 'Tuition', 'amount' => 50000, 'term_id' => $term->id]);
        app(TenantManager::class)->forget();

        $bursarResponse = $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/fee-items?term_id={$term->id}");
        // The per-row action button, matched as the exact rendered
        // element rather than the bare word "Manage": that word is a
        // substring of the "Learning Management" nav group, so a plain
        // assertDontSee('Manage') below silently failed for a reason
        // that had nothing to do with Finance at all (the long-standing
        // failure referenced throughout CLAUDE.md).
        $bursarResponse->assertOk()->assertSee('Installments')->assertSee('>Manage</a>', false);

        $parentResponse = $this->actingAs($parent)->get("http://{$school->slug}.localhost/finance/fee-items?term_id={$term->id}");
        $parentResponse->assertOk()
            ->assertSee($term->name.' fee schedule', false)
            ->assertDontSee('Installments')
            ->assertDontSee('>Manage</a>', false);
    }

    public function test_a_role_without_finance_permission_cannot_manage_fee_records(): void
    {
        [$school, $user] = $this->schoolWithMember('security', 'fintwo');

        $this->actingAs($user)->get("http://{$school->slug}.localhost/finance/fee-items")->assertForbidden();
    }

    /**
     * Audit finding 2026-07-18 (surfaced live by the user's own "hope
     * admin can set this and parent can see it too" check-in on
     * Installment Payment Plans): store() was gated on finance.create,
     * the same permission PaymentController::store() correctly uses for
     * "submit a payment" and that a parent legitimately holds, letting
     * any parent create arbitrary entries in the school's own fee
     * catalog. Fee catalog management is institution-level policy,
     * admin-only, the same finance.update tier every other write action
     * on FeeItemController already required.
     */
    public function test_a_parent_cannot_create_a_fee_item(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'finauth');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        app(TenantManager::class)->forget();

        $this->actingAs($parent)->post("http://{$school->slug}.localhost/finance/fee-items", [
            'name' => 'Not allowed', 'amount' => 1000, 'term_id' => $term->id,
        ])->assertForbidden();
    }

    /**
     * Rbac::moduleState() only ever wrote a real "enabled" row for a
     * module when a platform admin visited Billing and explicitly
     * assigned/changed a school's plan (BillingController::assignPlan());
     * absent that one specific action, a school fell back to
     * Module::defaultState(), which is DISABLED for every non-core module
     * regardless of what its plan actually promises. Found building this
     * slice: the nav already correctly showed "Payments" (School::
     * hasFeature() has its own, separate, already-correct plan fallback),
     * but clicking it 403'd, because moduleState() never consulted the
     * plan at all. Fixed by giving moduleState() the same plan fallback
     * hasFeature() already has. This test proves the real path: a school
     * on a real Plan that includes 'finance', with NO explicit
     * setModuleState() call ever made for it (unlike every other test in
     * this file, which call it directly in schoolWithMember() since
     * these tests don't seed PlanSeeder), can actually reach the screen.
     */
    public function test_a_school_on_a_plan_that_includes_finance_can_reach_it_with_no_explicit_module_toggle(): void
    {
        $plan = Plan::create([
            'name' => 'Test Plan Finance', 'slug' => 'plan-financereal',
            'price_per_student_per_term' => 500, 'modules' => ['finance'], 'features' => ['basic_fee_records'],
        ]);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'finreal', 'plan_id' => $plan->id]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'bursar');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($user)->get("http://{$school->slug}.localhost/finance/fee-items")->assertOk();
    }

    public function test_a_school_on_a_plan_without_finance_cannot_reach_it(): void
    {
        $plan = Plan::create([
            'name' => 'Test Plan No Finance', 'slug' => 'plan-nofinance',
            'price_per_student_per_term' => 500, 'modules' => [], 'features' => [],
        ]);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'finnone', 'plan_id' => $plan->id]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'bursar');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($user)->get("http://{$school->slug}.localhost/finance/fee-items")->assertForbidden();
    }

    public function test_a_parent_can_submit_a_bank_transfer_payment_with_evidence(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'finthree');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $feeItem = FeeItem::create(['name' => 'Tuition', 'amount' => 50000, 'term_id' => $term->id]);
        $student = Student::create(['admission_number' => 'F1', 'first_name' => 'Ada', 'last_name' => 'Obi']);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Mrs Obi', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $evidence = UploadedFile::fake()->image('receipt.jpg', 800, 600);

        $this->actingAs($parent)->post("http://{$school->slug}.localhost/finance/payments", [
            'student_ids' => [$student->id],
            'fee_item_id' => $feeItem->id,
            'amount' => 50000,
            'bank_name' => 'GTBank',
            'reference' => 'TXN123',
            'evidence' => $evidence,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $payment = Payment::first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($payment);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame($parent->id, $payment->submitted_by_user_id);
        $this->assertStringEndsWith('.webp', $payment->evidence_path); // converted, not stored raw
        Storage::disk('local')->assertExists($payment->evidence_path);

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/finance/payments/{$payment->id}/evidence")
            ->assertOk();
    }

    /**
     * Audit finding M-2 (2026-07-17): evidence used to be stored on the
     * public disk and served via a bare, unauthenticated URL. It now lives
     * on the private disk and is only reachable through this authenticated,
     * family-scoped route.
     */
    public function test_payment_evidence_is_not_reachable_by_a_different_family_or_an_unauthenticated_request(): void
    {
        [$school, $parentOne] = $this->schoolWithMember('parent', 'finfourteen');
        $parentTwo = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parentTwo->id, ['role_id' => $role->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'F13', 'first_name' => 'Evi', 'last_name' => 'Dence']);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'P1', 'relationship' => 'Mother', 'guardian_user_id' => $parentOne->id, 'is_primary' => true]);
        $payment = Payment::forceCreate(['student_id' => $student->id, 'amount' => 1000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_PENDING, 'submitted_by_user_id' => $parentOne->id, 'evidence_path' => 'payments/'.$school->slug.'/fake.webp']);
        Storage::disk('local')->put($payment->evidence_path, 'fake-image-bytes');
        app(TenantManager::class)->forget();

        $this->actingAs($parentTwo)->get("http://{$school->slug}.localhost/finance/payments/{$payment->id}/evidence")
            ->assertNotFound();

        // actingAs() keeps the test client "logged in" for every request
        // after it, so the guard has to be explicitly reset to actually
        // exercise the unauthenticated path.
        $this->app['auth']->forgetGuards();
        $this->get("http://{$school->slug}.localhost/finance/payments/{$payment->id}/evidence")
            ->assertRedirect(); // unauthenticated, bounced to login
    }

    public function test_pdf_evidence_is_stored_as_is_not_converted(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'finfour');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'F2', 'first_name' => 'Bode', 'last_name' => 'Ade']);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Mr Ade', 'relationship' => 'Father', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $evidence = UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf');

        $this->actingAs($parent)->post("http://{$school->slug}.localhost/finance/payments", [
            'student_ids' => [$student->id],
            'amount' => 15000,
            'evidence' => $evidence,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $payment = Payment::first();
        app(TenantManager::class)->forget();

        $this->assertStringEndsWith('.pdf', $payment->evidence_path);
        Storage::disk('local')->assertExists($payment->evidence_path);
    }

    public function test_a_parent_cannot_submit_a_payment_for_another_guardians_child(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'finfive');

        app(TenantManager::class)->use($school);
        $otherStudent = Student::create(['admission_number' => 'F3', 'first_name' => 'Not', 'last_name' => 'Mine']);
        app(TenantManager::class)->forget();

        $this->actingAs($parent)->post("http://{$school->slug}.localhost/finance/payments", [
            'student_ids' => [$otherStudent->id],
            'amount' => 10000,
            'evidence' => UploadedFile::fake()->image('r.jpg'),
        ])->assertNotFound();
    }

    public function test_a_bursar_can_verify_a_pending_payment_and_the_parent_is_notified(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finsix');
        $parent = User::factory()->create();
        $parentRole = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parent->id, ['role_id' => $parentRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'F4', 'first_name' => 'Chika', 'last_name' => 'Uzo']);
        $payment = Payment::forceCreate([
            'student_id' => $student->id, 'amount' => 20000, 'method' => 'bank_transfer',
            'status' => Payment::STATUS_PENDING, 'submitted_by_user_id' => $parent->id,
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/payments/{$payment->id}/verify")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $payment->refresh();
        $notified = Notification::where('user_id', $parent->id)->where('category', 'payment_verified')->exists();
        app(TenantManager::class)->forget();

        $this->assertSame(Payment::STATUS_VERIFIED, $payment->status);
        $this->assertSame($bursar->id, $payment->verified_by_user_id);
        $this->assertNotNull($payment->verified_at);
        $this->assertTrue($notified, 'the submitting parent must be notified once their payment is verified');
    }

    public function test_a_bursar_can_reject_a_pending_payment_with_a_note(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finseven');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'F5', 'first_name' => 'Tola', 'last_name' => 'Jide']);
        $payment = Payment::forceCreate([
            'student_id' => $student->id, 'amount' => 5000, 'method' => 'bank_transfer',
            'status' => Payment::STATUS_PENDING, 'submitted_by_user_id' => $bursar->id,
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/payments/{$payment->id}/reject", [
            'notes' => 'Amount does not match the fee due.',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $payment->refresh();
        app(TenantManager::class)->forget();

        $this->assertSame(Payment::STATUS_REJECTED, $payment->status);
        $this->assertSame('Amount does not match the fee due.', $payment->notes);
    }

    public function test_an_already_decided_payment_cannot_be_verified_again(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'fineight');

        app(TenantManager::class)->use($school);
        $student = Student::create(['admission_number' => 'F6', 'first_name' => 'Kemi', 'last_name' => 'Ola']);
        $payment = Payment::forceCreate([
            'student_id' => $student->id, 'amount' => 5000, 'method' => 'bank_transfer',
            'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $bursar->id,
            'verified_by_user_id' => $bursar->id, 'verified_at' => now(),
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/payments/{$payment->id}/reject")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $status = $payment->fresh()->status;
        app(TenantManager::class)->forget();

        $this->assertSame(Payment::STATUS_VERIFIED, $status, 'a decided payment must not flip status again');
    }

    public function test_a_parent_only_sees_their_own_familys_payments(): void
    {
        [$school, $parentOne] = $this->schoolWithMember('parent', 'finnine');
        $parentTwo = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parentTwo->id, ['role_id' => $role->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $studentOne = Student::create(['admission_number' => 'F7', 'first_name' => 'Mine', 'last_name' => 'Child']);
        StudentGuardian::create(['student_id' => $studentOne->id, 'name' => 'P1', 'relationship' => 'Mother', 'guardian_user_id' => $parentOne->id, 'is_primary' => true]);
        $studentTwo = Student::create(['admission_number' => 'F8', 'first_name' => 'Other', 'last_name' => 'Child']);
        StudentGuardian::create(['student_id' => $studentTwo->id, 'name' => 'P2', 'relationship' => 'Mother', 'guardian_user_id' => $parentTwo->id, 'is_primary' => true]);

        Payment::forceCreate(['student_id' => $studentOne->id, 'amount' => 1000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_PENDING, 'submitted_by_user_id' => $parentOne->id]);
        Payment::forceCreate(['student_id' => $studentTwo->id, 'amount' => 2000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_PENDING, 'submitted_by_user_id' => $parentTwo->id]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($parentOne)->get("http://{$school->slug}.localhost/finance/payments");
        $response->assertOk()->assertSee('Mine Child')->assertDontSee('Other Child');
    }

    public function test_the_bursar_dashboard_shows_real_pending_and_verified_figures(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finten');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $student = Student::create(['admission_number' => 'F9', 'first_name' => 'Dayo', 'last_name' => 'Ige']);
        Payment::forceCreate(['student_id' => $student->id, 'amount' => 1000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_PENDING, 'submitted_by_user_id' => $bursar->id]);
        Payment::forceCreate(['student_id' => $student->id, 'amount' => 30000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $bursar->id, 'verified_by_user_id' => $bursar->id, 'verified_at' => now()]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($bursar)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk()
            ->assertSee('Pending Payments')
            ->assertSee('1') // one pending
            ->assertSee('₦30,000.00'); // verified this term
    }

    /** Bulk retire/reactivate/delete for fee items (audit finding M-12, 2026-07-17), matching the standing bulk-actions convention. */
    public function test_a_bursar_can_bulk_retire_and_reactivate_fee_items(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'fineleven');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $itemOne = FeeItem::create(['name' => 'Tuition', 'amount' => 1000, 'term_id' => $term->id]);
        $itemTwo = FeeItem::create(['name' => 'PTA Levy', 'amount' => 500, 'term_id' => $term->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items/bulk-action", [
            'fee_item_ids' => [$itemOne->id, $itemTwo->id], 'action' => 'retire',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertFalse($itemOne->fresh()->is_active);
        $this->assertFalse($itemTwo->fresh()->is_active);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items/bulk-action", [
            'fee_item_ids' => [$itemOne->id], 'action' => 'activate',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertTrue($itemOne->fresh()->is_active);
        $this->assertFalse($itemTwo->fresh()->is_active);
        app(TenantManager::class)->forget();
    }

    /** A fee item with payments recorded against it is skipped by bulk delete, not force-removed, matching the single-record destroy() guard. */
    public function test_bulk_delete_skips_a_fee_item_with_payments_recorded(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'fintwelve');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $clean = FeeItem::create(['name' => 'Excursion', 'amount' => 2000, 'term_id' => $term->id]);
        $withPayments = FeeItem::create(['name' => 'Tuition', 'amount' => 50000, 'term_id' => $term->id]);
        $student = Student::create(['admission_number' => 'F10', 'first_name' => 'Bulk', 'last_name' => 'Test']);
        Payment::forceCreate(['student_id' => $student->id, 'fee_item_id' => $withPayments->id, 'amount' => 50000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $bursar->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items/bulk-action", [
            'fee_item_ids' => [$clean->id, $withPayments->id], 'action' => 'delete',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertNull(FeeItem::find($clean->id));
        $this->assertNotNull(FeeItem::find($withPayments->id));
        app(TenantManager::class)->forget();
    }

    /** Bulk verify/reject for pending payments (audit finding M-12, 2026-07-17), reusing the same atomic guard applyDecision() gives the single-record actions. */
    public function test_a_bursar_can_bulk_verify_pending_payments(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finthirteen');

        app(TenantManager::class)->use($school);
        $studentOne = Student::create(['admission_number' => 'F11', 'first_name' => 'Bulk', 'last_name' => 'One']);
        $studentTwo = Student::create(['admission_number' => 'F12', 'first_name' => 'Bulk', 'last_name' => 'Two']);
        $paymentOne = Payment::forceCreate(['student_id' => $studentOne->id, 'amount' => 1000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_PENDING, 'submitted_by_user_id' => $bursar->id]);
        $paymentTwo = Payment::forceCreate(['student_id' => $studentTwo->id, 'amount' => 2000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_PENDING, 'submitted_by_user_id' => $bursar->id]);
        $alreadyVerified = Payment::forceCreate(['student_id' => $studentOne->id, 'amount' => 3000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $bursar->id, 'verified_by_user_id' => $bursar->id, 'verified_at' => now()]);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/payments/bulk-action", [
            'payment_ids' => [$paymentOne->id, $paymentTwo->id, $alreadyVerified->id], 'action' => 'verify',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(Payment::STATUS_VERIFIED, $paymentOne->fresh()->status);
        $this->assertSame(Payment::STATUS_VERIFIED, $paymentTwo->fresh()->status);
        $this->assertSame(2, Notification::where('category', 'payment_verified')->count());
        app(TenantManager::class)->forget();
    }

    /**
     * Installment Payment Plans (Advance Finance, 2026-07-18): a fee item
     * can be split into a scheduled series of partial payments instead of
     * one lump submission, the natural extension of this Foundation slice
     * flagged as deferred when it first shipped.
     */
    public function test_a_bursar_can_manage_a_fee_items_installment_schedule(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'fininstallone');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $item = FeeItem::create(['name' => 'Tuition', 'amount' => 60000, 'term_id' => $term->id, 'allow_installments' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/fee-items/{$item->id}")
            ->assertOk()->assertSee('Installment schedule');

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items/{$item->id}/installments", [
            'label' => '1st Installment', 'amount' => 30000, 'due_date' => now()->addDays(30)->toDateString(),
        ])->assertRedirect();
        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items/{$item->id}/installments", [
            'label' => '2nd Installment', 'amount' => 30000,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $installments = \App\Models\Tenant\FeeItemInstallment::where('fee_item_id', $item->id)->orderBy('sequence')->get();
        app(TenantManager::class)->forget();

        $this->assertCount(2, $installments);
        $this->assertSame('1st Installment', $installments[0]->label);
        $this->assertSame('30000.00', (string) $installments[0]->amount);

        $this->actingAs($bursar)->delete("http://{$school->slug}.localhost/finance/fee-items/installments/{$installments[1]->id}")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertCount(1, \App\Models\Tenant\FeeItemInstallment::where('fee_item_id', $item->id)->get());
        app(TenantManager::class)->forget();
    }

    public function test_an_installment_with_a_payment_recorded_cannot_be_deleted(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'fininstalltwo');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $item = FeeItem::create(['name' => 'Tuition', 'amount' => 60000, 'term_id' => $term->id, 'allow_installments' => true]);
        $installment = \App\Models\Tenant\FeeItemInstallment::create(['fee_item_id' => $item->id, 'label' => '1st Installment', 'amount' => 30000, 'sequence' => 1]);
        $student = Student::create(['admission_number' => 'FI1', 'first_name' => 'Inst', 'last_name' => 'Allment']);
        Payment::forceCreate([
            'student_id' => $student->id, 'fee_item_id' => $item->id, 'fee_item_installment_id' => $installment->id,
            'amount' => 30000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $bursar->id,
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->delete("http://{$school->slug}.localhost/finance/fee-items/installments/{$installment->id}")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertNotNull(\App\Models\Tenant\FeeItemInstallment::find($installment->id));
        app(TenantManager::class)->forget();
    }

    public function test_a_parent_can_submit_a_payment_against_a_specific_installment(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'fininstallthree');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $item = FeeItem::create(['name' => 'Tuition', 'amount' => 60000, 'term_id' => $term->id, 'allow_installments' => true]);
        $installment = \App\Models\Tenant\FeeItemInstallment::create(['fee_item_id' => $item->id, 'label' => '1st Installment', 'amount' => 30000, 'sequence' => 1]);
        $child = Student::create(['admission_number' => 'FI2', 'first_name' => 'Child', 'last_name' => 'Payer']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $file = UploadedFile::fake()->image('evidence.jpg');
        $this->actingAs($parent)->post("http://{$school->slug}.localhost/finance/payments", [
            'student_ids' => [$child->id], 'fee_item_id' => $item->id, 'fee_item_installment_id' => $installment->id,
            'amount' => 30000, 'evidence' => $file,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $payment = Payment::where('student_id', $child->id)->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($payment);
        $this->assertSame($installment->id, $payment->fee_item_installment_id);
    }

    /**
     * User check-in 2026-07-18, after the installment feature shipped:
     * "hope admin can set this and parent can see it too". Admin (bursar)
     * already could; this locks in that a parent (finance.view/finance.
     * create, no finance.update) can browse to a fee item's own page and
     * see its installment schedule too, just without the manage controls.
     */
    public function test_a_parent_can_view_but_not_manage_an_installment_schedule(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'fininstallfive');
        $parent = User::factory()->create();
        $parentRole = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parent->id, ['role_id' => $parentRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $item = FeeItem::create(['name' => 'Tuition', 'amount' => 60000, 'term_id' => $term->id, 'allow_installments' => true]);
        \App\Models\Tenant\FeeItemInstallment::create(['fee_item_id' => $item->id, 'label' => '1st Installment', 'amount' => 30000, 'sequence' => 1]);
        app(TenantManager::class)->forget();

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/finance/fee-items")
            ->assertOk()->assertSee('Tuition')->assertSee('View')->assertDontSee('Add a fee item');

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/finance/fee-items/{$item->id}")
            ->assertOk()
            ->assertSee('1st Installment')
            ->assertSee('Collected')
            ->assertDontSee('Add an installment');
    }

    /** An installment id belonging to a different fee item than the one submitted is silently dropped, never trusted cross-field. */
    public function test_a_mismatched_installment_id_is_dropped_not_trusted(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'fininstallfour');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $itemA = FeeItem::create(['name' => 'Tuition', 'amount' => 60000, 'term_id' => $term->id, 'allow_installments' => true]);
        $itemB = FeeItem::create(['name' => 'PTA Levy', 'amount' => 5000, 'term_id' => $term->id, 'allow_installments' => true]);
        $installmentOfB = \App\Models\Tenant\FeeItemInstallment::create(['fee_item_id' => $itemB->id, 'label' => 'Only Installment', 'amount' => 5000, 'sequence' => 1]);
        $child = Student::create(['admission_number' => 'FI3', 'first_name' => 'Child', 'last_name' => 'Mismatch']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $file = UploadedFile::fake()->image('evidence.jpg');
        $this->actingAs($parent)->post("http://{$school->slug}.localhost/finance/payments", [
            'student_ids' => [$child->id], 'fee_item_id' => $itemA->id, 'fee_item_installment_id' => $installmentOfB->id,
            'amount' => 30000, 'evidence' => $file,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $payment = Payment::where('student_id', $child->id)->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($payment);
        $this->assertNull($payment->fee_item_installment_id);
    }

    /**
     * User request 2026-07-26: "for parents with more than one child, add
     * the option of check box to select in case the parent want the
     * payment to cover more than one child." One evidence upload split
     * across three children, each getting its own Payment row and an even
     * share of the total, tied together by a shared payment_group_id.
     */
    public function test_a_parent_with_multiple_children_can_split_one_payment_across_them(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'finmulti');

        app(TenantManager::class)->use($school);
        $childA = Student::create(['admission_number' => 'FM1', 'first_name' => 'Ada', 'last_name' => 'Multi']);
        $childB = Student::create(['admission_number' => 'FM2', 'first_name' => 'Bola', 'last_name' => 'Multi']);
        $childC = Student::create(['admission_number' => 'FM3', 'first_name' => 'Chidi', 'last_name' => 'Multi']);
        foreach ([$childA, $childB, $childC] as $child) {
            StudentGuardian::create(['student_id' => $child->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        }
        app(TenantManager::class)->forget();

        $evidence = UploadedFile::fake()->image('receipt.jpg');
        $this->actingAs($parent)->post("http://{$school->slug}.localhost/finance/payments", [
            'student_ids' => [$childA->id, $childB->id, $childC->id],
            'amount' => 10000,
            'evidence' => $evidence,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $payments = Payment::orderBy('student_id')->get();
        app(TenantManager::class)->forget();

        $this->assertCount(3, $payments);
        $this->assertEqualsWithDelta(10000.0, (float) $payments->sum('amount'), 0.01);
        $this->assertCount(1, $payments->pluck('payment_group_id')->unique());
        $this->assertNotNull($payments->first()->payment_group_id);
        $this->assertCount(1, $payments->pluck('evidence_path')->unique());
    }

    /**
     * A single-child submission (the ordinary case) must not gain a
     * payment_group_id or split anything, unchanged from before this
     * feature existed.
     */
    public function test_a_single_child_payment_has_no_payment_group(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'finmultitwo');

        app(TenantManager::class)->use($school);
        $child = Student::create(['admission_number' => 'FM4', 'first_name' => 'Solo', 'last_name' => 'Payer']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $evidence = UploadedFile::fake()->image('receipt.jpg');
        $this->actingAs($parent)->post("http://{$school->slug}.localhost/finance/payments", [
            'student_ids' => [$child->id],
            'amount' => 5000,
            'evidence' => $evidence,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $payment = Payment::first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($payment);
        $this->assertNull($payment->payment_group_id);
        $this->assertEqualsWithDelta(5000.0, (float) $payment->amount, 0.01);
    }

    /**
     * A crafted student id outside the parent's own family is silently
     * dropped from the batch rather than rejecting the whole submission,
     * same "never trust cross-field ids" convention as elsewhere; the
     * legitimate children in the batch still get their payment recorded.
     */
    public function test_a_foreign_student_id_is_dropped_from_a_multi_child_submission_not_trusted(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'finmultithree');

        app(TenantManager::class)->use($school);
        $ownChild = Student::create(['admission_number' => 'FM5', 'first_name' => 'Own', 'last_name' => 'Child']);
        StudentGuardian::create(['student_id' => $ownChild->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        $notMyChild = Student::create(['admission_number' => 'FM6', 'first_name' => 'Not', 'last_name' => 'Mine']);
        app(TenantManager::class)->forget();

        $evidence = UploadedFile::fake()->image('receipt.jpg');
        $this->actingAs($parent)->post("http://{$school->slug}.localhost/finance/payments", [
            'student_ids' => [$ownChild->id, $notMyChild->id],
            'amount' => 8000,
            'evidence' => $evidence,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $payments = Payment::all();
        app(TenantManager::class)->forget();

        $this->assertCount(1, $payments);
        $this->assertSame($ownChild->id, $payments->first()->student_id);
        $this->assertEqualsWithDelta(8000.0, (float) $payments->first()->amount, 0.01);
    }

    /**
     * "All Fees" payment plan (user request 2026-07-26): "add an item of
     * all fees... based on the child or children selected... calculate
     * the total amount to be paid, this is only for non optional items...
     * if parent has made payment before, show the balance left." The
     * Submit Payment page ships each child's own non-optional-owed and
     * already-paid totals as data attributes for the client side
     * calculator to read; an optional item must never count toward the
     * owed total, and a prior verified payment must reduce the balance.
     */
    public function test_the_submit_payment_page_ships_the_non_optional_owed_and_paid_totals_for_the_all_fees_plan(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'finallfeesone');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id]);
        $tuition = FeeItem::create(['name' => 'Tuition', 'amount' => 10000, 'term_id' => $term->id, 'is_optional' => false]);
        $uniform = FeeItem::create(['name' => 'Uniform', 'amount' => 3000, 'term_id' => $term->id, 'is_optional' => true]);
        $child = Student::create(['admission_number' => 'FAF1', 'first_name' => 'Ada', 'last_name' => 'Fees', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        Payment::forceCreate(['student_id' => $child->id, 'amount' => 4000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $parent->id]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($parent)->get("http://{$school->slug}.localhost/finance/payments/create");

        $response->assertOk()
            ->assertSee('All Fees (non-optional items)')
            ->assertSee('data-owed="10000"', false)
            ->assertSee('data-paid="4000"', false);
    }

    /**
     * Submitting with the synthetic "all_fees" picker value saves the
     * same way a General payment does (fee_item_id null), since it is a
     * client side amount calculator, not a real fee item.
     */
    public function test_a_parent_can_submit_an_all_fees_payment(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'finallfeestwo');

        app(TenantManager::class)->use($school);
        $child = Student::create(['admission_number' => 'FAF2', 'first_name' => 'Bode', 'last_name' => 'Fees']);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $evidence = UploadedFile::fake()->image('receipt.jpg');
        $this->actingAs($parent)->post("http://{$school->slug}.localhost/finance/payments", [
            'student_ids' => [$child->id],
            'fee_item_id' => 'all_fees',
            'amount' => 6000,
            'evidence' => $evidence,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $payment = Payment::first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($payment);
        $this->assertNull($payment->fee_item_id);
        $this->assertEqualsWithDelta(6000.0, (float) $payment->amount, 0.01);
    }

    /**
     * User report 2026-07-26: "looking through all the list is too
     * clumsy, all similar items in the list should be merged under one,
     * parent selected one child and it is still showing school uniform
     * for three childs, wrong approach." A fee item scoped to certain
     * classes exists as one row per class at different prices; the
     * Submit Payment picker now shows one option per distinct name
     * regardless of how many per-class rows share it.
     */
    public function test_same_named_fee_items_across_classes_show_as_one_option_not_several(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finmergeone');
        $parent = User::factory()->create();
        $parentRole = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parent->id, ['role_id' => $parentRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $term->update(['is_current' => true]);
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $classA = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $classB = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 2', 'sequence' => 2]);
        FeeItem::create(['name' => 'School Uniform (Optional)', 'amount' => 12000, 'term_id' => $term->id, 'is_optional' => true])->schoolClasses()->attach($classA->id);
        FeeItem::create(['name' => 'School Uniform (Optional)', 'amount' => 15000, 'term_id' => $term->id, 'is_optional' => true])->schoolClasses()->attach($classB->id);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $classA->id]);
        $student = Student::create(['admission_number' => 'MRG1', 'first_name' => 'Merge', 'last_name' => 'Test', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($parent)->get("http://{$school->slug}.localhost/finance/payments/create");

        $response->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'School Uniform (Optional)<'), 'the two per-class rows sharing this name must render as exactly one dropdown option');
    }

    /**
     * User report 2026-07-26: "you did not add all fees, optional items
     * included." The original non-optional-only All Fees plan stays as
     * its own choice; this is a second one that folds optional items in
     * too.
     */
    public function test_all_fees_including_optional_variant_totals_every_approved_item(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'finallfeesthree');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id]);
        FeeItem::create(['name' => 'Tuition', 'amount' => 10000, 'term_id' => $term->id, 'is_optional' => false]);
        FeeItem::create(['name' => 'Uniform', 'amount' => 3000, 'term_id' => $term->id, 'is_optional' => true]);
        $child = Student::create(['admission_number' => 'AFI1', 'first_name' => 'Ada', 'last_name' => 'Inc', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($parent)->get("http://{$school->slug}.localhost/finance/payments/create");

        $response->assertOk()
            ->assertSee('All Fees (including optional items)')
            ->assertSee('data-owed-all="13000"', false)
            ->assertSee('data-owed="10000"', false);
    }

    /** The fee item picker only ever shows the active term's own items, matching every other finance screen's own term scoping. */
    public function test_the_submit_payment_fee_item_picker_only_shows_the_active_term(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'finmergetwo');

        app(TenantManager::class)->use($school);
        $currentTerm = $this->currentTerm();
        $currentTerm->update(['is_current' => true]);
        $pastSession = AcademicSession::create(['name' => '2024/2025']);
        $pastTerm = Term::create(['academic_session_id' => $pastSession->id, 'name' => 'Third Term', 'sequence' => 3, 'is_current' => false]);
        FeeItem::create(['name' => 'Current Term Fee', 'amount' => 5000, 'term_id' => $currentTerm->id]);
        FeeItem::create(['name' => 'Old Term Fee', 'amount' => 5000, 'term_id' => $pastTerm->id]);
        $student = Student::create(['admission_number' => 'MRG2', 'first_name' => 'Term', 'last_name' => 'Scope']);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/finance/payments/create")
            ->assertOk()->assertSee('Current Term Fee')->assertDontSee('Old Term Fee');
    }

    /**
     * Found live 2026-07-25 (user report: "finance menu is missing from
     * parent view"): the parent role already holds finance.view/
     * finance.create, and PaymentController/FeeItemController already let
     * a parent all the way in, but the nav's own restricted-role branch
     * only ever shows a feature link when config('skulos.nav_permission_
     * map') has an entry for it, and none of the 5 finance feature keys
     * did, so the link was unconditionally hidden for every parent (and
     * student) regardless of their real permission. Fixed by mapping each
     * to finance.view, the exact permission each screen's own index()
     * already checks.
     */
    public function test_a_parent_sees_the_finance_nav_link(): void
    {
        // hasFeature()/hasAnyFeatureInCategory() (what the nav actually
        // gates the link's rendering on) read the school's Plan, not the
        // Module state schoolWithMember() sets, so a real Plan with a
        // finance feature is needed here specifically to prove the nav
        // link renders, matching the existing "a school on a plan that
        // includes finance..." test's own setup for the same reason.
        $plan = Plan::create([
            'name' => 'Test Plan Finance Nav', 'slug' => 'plan-financenav',
            'price_per_student_per_term' => 500, 'modules' => ['finance'], 'features' => ['bank_transfer_payment_option'],
        ]);
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'finnav', 'plan_id' => $plan->id]));
        $parent = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parent->id, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($parent)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk()->assertSee(route('school.finance.payments.index', ['school' => $school->slug]), false);
    }

    /**
     * Bank account(s) (user request 2026-07-25: "School admin sets the
     * school account options for payment which parents sees, a school can
     * add more than one account details and the parent sees all"). Purely
     * informational, shown on the Submit Payment screen.
     */
    public function test_a_bursar_can_add_more_than_one_bank_account_and_a_parent_sees_them_all(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finbankone');
        $parent = User::factory()->create();
        $parentRole = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parent->id, ['role_id' => $parentRole->id, 'status' => 'active']);

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/bank-accounts", [
            'bank_name' => 'First Bank', 'account_name' => 'Test Academy', 'account_number' => '1111111111',
        ])->assertRedirect();
        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/bank-accounts", [
            'bank_name' => 'GTBank', 'account_name' => 'Test Academy', 'account_number' => '2222222222',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertCount(2, \App\Models\Tenant\BankAccount::all());
        app(TenantManager::class)->forget();

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/finance/payments/create")
            ->assertOk()
            ->assertSee('First Bank')->assertSee('1111111111')
            ->assertSee('GTBank')->assertSee('2222222222');
    }

    public function test_a_hidden_bank_account_is_not_shown_to_a_parent(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finbanktwo');
        $parent = User::factory()->create();
        $parentRole = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parent->id, ['role_id' => $parentRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $account = \App\Models\Tenant\BankAccount::create(['bank_name' => 'Access Bank', 'account_name' => 'Test Academy', 'account_number' => '3333333333']);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/bank-accounts/{$account->id}/toggle-active")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertFalse($account->fresh()->is_active);
        app(TenantManager::class)->forget();

        // assertDontSee('Access Bank') alone would be a false positive here:
        // the bursar's own toggle-active flash message ("\"Access Bank\" is
        // hidden from parents.") shares this test's session/flash state and
        // would otherwise still be on screen for the very next request.
        // The account number is a reliable stand-in, it never appears
        // outside the bank account list itself.
        $this->actingAs($parent)->get("http://{$school->slug}.localhost/finance/payments/create")
            ->assertOk()->assertDontSee('3333333333');
    }

    public function test_a_parent_cannot_manage_bank_accounts(): void
    {
        [$school, $parent] = $this->schoolWithMember('parent', 'finbankthree');

        $this->actingAs($parent)->post("http://{$school->slug}.localhost/finance/bank-accounts", [
            'bank_name' => 'Not Allowed', 'account_name' => 'X', 'account_number' => '000',
        ])->assertForbidden();
    }

    /**
     * Class-scoped fee items (user request 2026-07-25: "fee items can be
     * different for each class... make it possible to select the classes
     * that item is applicable to"). No classes ticked still means "every
     * class", the field's original, still-default meaning.
     */
    public function test_a_fee_item_with_no_classes_ticked_applies_to_every_class(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finclassone');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items", [
            'name' => 'PTA Levy', 'amount' => 2000, 'term_id' => $term->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $feeItem = FeeItem::first();
        $applies = $feeItem->appliesToClass(999);
        app(TenantManager::class)->forget();

        $this->assertTrue($applies, 'a fee item with no classes ticked must apply to every class, including one that does not even exist yet');
    }

    public function test_a_fee_item_scoped_to_one_class_only_shows_for_a_student_in_that_class(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finclasstwo');
        $parent = User::factory()->create();
        $parentRole = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parent->id, ['role_id' => $parentRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $sss1 = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'SSS 1', 'sequence' => 1]);
        $sss2 = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'SSS 2', 'sequence' => 2]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $sss1->id]);
        $student = Student::create(['admission_number' => 'FC1', 'first_name' => 'Sss', 'last_name' => 'One', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items", [
            'name' => 'SSS 1 Excursion', 'amount' => 15000, 'term_id' => $term->id, 'class_ids' => [$sss1->id],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $feeItem = FeeItem::where('name', 'SSS 1 Excursion')->first();
        $this->assertTrue($feeItem->appliesToClass($sss1->id));
        $this->assertFalse($feeItem->appliesToClass($sss2->id));
        app(TenantManager::class)->forget();

        // The create form itself passes every active fee item to the
        // page regardless of class (JS filters it client side against
        // the chosen student's own class), so this proves the data the
        // filtering relies on is actually there rather than the filtering
        // logic itself, which is client side JS out of PHP's reach here.
        $this->actingAs($parent)->get("http://{$school->slug}.localhost/finance/payments/create")
            ->assertOk()->assertSee('data-class-id="'.$sss1->id.'"', false)
            ->assertSee('data-classes="'.$sss1->id.'"', false);
    }

    /** User request 2026-07-26: "the fee items should have a box to check if its optional or not." */
    public function test_a_fee_item_can_be_marked_optional(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finoptionalone');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items", [
            'name' => 'School Uniform', 'amount' => 12000, 'term_id' => $term->id, 'is_optional' => '1',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $isOptional = FeeItem::where('name', 'School Uniform')->value('is_optional');
        app(TenantManager::class)->forget();

        $this->assertTrue((bool) $isOptional);
    }

    /**
     * User report 2026-07-26: "the fee schedule per class section table
     * is not showing the total per class (note that this should be
     * automated and should have two rows - Total including Options
     * Items and Total excluding Options Items)" and "let all non
     * optional items come first in the list."
     */
    public function test_the_fee_schedule_shows_non_optional_items_first_and_two_automated_totals(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finoptionaltwo');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        FeeItem::create(['name' => 'School Uniform', 'amount' => 12000, 'term_id' => $term->id, 'is_optional' => true]);
        FeeItem::create(['name' => 'Tuition', 'amount' => 50000, 'term_id' => $term->id, 'is_optional' => false]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/fee-items?term_id={$term->id}");
        $response->assertOk();
        $content = $response->getContent();

        $this->assertLessThan(
            strpos($content, 'School Uniform'),
            strpos($content, 'Tuition'),
            'a non optional item (Tuition) must be listed before an optional one (School Uniform)'
        );
        $this->assertStringContainsString('Total (including optional items)', $content);
        $this->assertStringContainsString('Total (excluding optional items)', $content);
        $response->assertSee('₦62,000.00')  // including: 12,000 + 50,000
            ->assertSee('₦50,000.00');       // excluding: 50,000 only
    }

    /**
     * User report 2026-07-26: "the terms are all showing on the page
     * instead of have the switch a term function." Checked via the
     * page's own $feeSchedule/$scheduleTerm view data rather than raw
     * HTML text: the separate, unscoped "every fee item" list further
     * down the same page (and the add-item class picker) legitimately
     * still shows every term/class regardless of this filter, by design,
     * so a page-wide assertDontSee would be testing the wrong table.
     */
    public function test_the_fee_schedule_shows_only_the_selected_term_not_every_term_at_once(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finswitchschedule');

        app(TenantManager::class)->use($school);
        $currentTerm = $this->currentTerm();
        $otherSession = \App\Models\Tenant\AcademicSession::create(['name' => '2026/2027']);
        $otherTerm = \App\Models\Tenant\Term::create([
            'academic_session_id' => $otherSession->id, 'name' => 'Second Term', 'sequence' => 2, 'is_current' => false,
            'starts_on' => now()->addMonths(4), 'ends_on' => now()->addMonths(7),
        ]);
        FeeItem::create(['name' => 'Current Term Fee', 'amount' => 1000, 'term_id' => $currentTerm->id]);
        FeeItem::create(['name' => 'Other Term Fee', 'amount' => 2000, 'term_id' => $otherTerm->id]);
        app(TenantManager::class)->forget();

        $viewingCurrent = $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/fee-items?term_id={$currentTerm->id}");
        $viewingCurrent->assertOk();
        $this->assertSame($currentTerm->id, $viewingCurrent->viewData('scheduleTerm')->id);
        $this->assertEqualsCanonicalizing(['Current Term Fee'], collect($viewingCurrent->viewData('feeSchedule')['rows'])->pluck('name')->all());

        $viewingOther = $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/fee-items?term_id={$otherTerm->id}");
        $viewingOther->assertOk();
        $this->assertSame($otherTerm->id, $viewingOther->viewData('scheduleTerm')->id);
        $this->assertEqualsCanonicalizing(['Other Term Fee'], collect($viewingOther->viewData('feeSchedule')['rows'])->pluck('name')->all());
    }

    /** User request 2026-07-26: "make it possible to view the fees per section... under the selected term." */
    public function test_the_fee_schedule_can_be_narrowed_to_one_section(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finsectionfilter');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $primarySection = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $secondarySection = \App\Models\Tenant\Section::create(['name' => 'Secondary', 'sequence' => 2]);
        $primaryClass = \App\Models\Tenant\SchoolClass::create(['section_id' => $primarySection->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $secondaryClass = \App\Models\Tenant\SchoolClass::create(['section_id' => $secondarySection->id, 'name' => 'JSS 1', 'sequence' => 1]);
        FeeItem::create(['name' => 'Tuition', 'amount' => 30000, 'term_id' => $term->id]);
        $primarySectionId = $primarySection->id;
        app(TenantManager::class)->forget();

        $response = $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/fee-items?term_id={$term->id}&section_id={$primarySectionId}");
        $response->assertOk();
        $this->assertEqualsCanonicalizing(['Primary 1'], collect($response->viewData('scheduleClasses'))->pluck('name')->all());
    }

    /** The downloadable CSV template (user request 2026-07-25: "add a downloadable csv too"). */
    public function test_the_fee_item_csv_template_downloads_with_the_expected_columns(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'fintemplate');

        $response = $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/fee-items/template");
        $response->assertOk();
        $this->assertStringContainsString('name,amount,term,allow_installments,classes', $response->getContent());
    }

    /**
     * The bulk upload (user request 2026-07-25: "make the fee items that
     * one can upload as a bulk file... alternatively, the school can
     * decide to upload a file that will act as the school fees").
     */
    public function test_a_bursar_can_bulk_upload_fee_items_via_csv(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finbulkone');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Nursery', 'sequence' => 1]);
        $nursery = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Nursery 1', 'sequence' => 1]);
        app(TenantManager::class)->forget();

        $csv = "name,amount,term,allow_installments,classes\n"
            ."Tuition,50000,{$term->name},no,\n"
            ."Books and Stationery,8000,{$term->name},no,Nursery 1\n"
            ."Unknown Fee,1000,Nonexistent Term,no,\n";
        $file = UploadedFile::fake()->createWithContent('fees.csv', $csv);

        $response = $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items/bulk-upload", [
            'file' => $file,
        ]);
        $response->assertRedirect();

        app(TenantManager::class)->use($school);
        $items = FeeItem::with('schoolClasses')->orderBy('name')->get();
        app(TenantManager::class)->forget();

        $this->assertCount(2, $items, 'the row with an unknown term must be skipped, not created');
        $stationery = $items->firstWhere('name', 'Books and Stationery');
        $this->assertTrue($stationery->appliesToClass($nursery->id));
        $tuition = $items->firstWhere('name', 'Tuition');
        $this->assertTrue($tuition->schoolClasses->isEmpty());
    }

    /**
     * "Under Term it should also show the new coming session if the
     * school is in Third term currently... automated based on the
     * current term if third term and session year" (user request
     * 2026-07-25).
     */
    public function test_the_upcoming_terms_first_term_is_offered_once_in_the_last_term_of_a_session(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finupcomingone');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026 Session', 'is_current' => true]);
        $thirdTerm = Term::create(['academic_session_id' => $session->id, 'name' => 'Third Term 2025/2026', 'sequence' => 3, 'is_current' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/fee-items")
            ->assertOk()->assertSee('First Term 2026/2027 (not created yet)');
    }

    public function test_selecting_the_upcoming_term_creates_the_real_session_and_uses_its_first_term(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finupcomingtwo');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026 Session', 'is_current' => true]);
        Term::create(['academic_session_id' => $session->id, 'name' => 'Third Term 2025/2026', 'sequence' => 3, 'is_current' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items", [
            'name' => 'New Session Tuition', 'amount' => 60000, 'term_id' => 'upcoming:2026',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $nextSession = AcademicSession::where('name', '2026/2027 Session')->first();
        $this->assertNotNull($nextSession, 'the upcoming session must be created for real once actually used');
        $feeItem = FeeItem::where('name', 'New Session Tuition')->first();
        $this->assertNotNull($feeItem);
        $this->assertSame('First Term 2026/2027', $feeItem->term->name);
        app(TenantManager::class)->forget();
    }

    public function test_the_upcoming_term_option_is_not_offered_once_the_next_session_already_exists(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finupcomingthree');

        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026 Session', 'is_current' => true]);
        Term::create(['academic_session_id' => $session->id, 'name' => 'Third Term 2025/2026', 'sequence' => 3, 'is_current' => true]);
        AcademicSession::create(['name' => '2026/2027 Session']);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/fee-items")
            ->assertOk()->assertDontSee('not created yet');
    }

    /**
     * Fee approval chain (user request 2026-07-25: "admin can set an
     * approval level for school fees which the school owner, principal,
     * vice principal, head teacher, assistant head teacher or any other
     * name from the staff list"). Off by default, a fee item stays
     * auto-approved exactly as before until a school explicitly turns
     * this on and configures at least one approver.
     */
    public function test_a_fee_item_is_auto_approved_when_approval_is_off(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finapprovalone');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items", [
            'name' => 'Tuition', 'amount' => 50000, 'term_id' => $term->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $feeItem = FeeItem::first();
        app(TenantManager::class)->forget();

        $this->assertSame(FeeItem::APPROVAL_APPROVED, $feeItem->approval_status);
    }

    public function test_a_new_fee_item_starts_pending_once_approval_is_on_and_configured(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finapprovaltwo');
        $principal = User::factory()->create();
        $principalRole = $this->rbac->cloneTemplateRole($school, 'principal');
        $school->users()->attach($principal->id, ['role_id' => $principalRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items/approval-settings", [
            'enabled' => 1, 'chain' => ['role:principal'],
        ])->assertRedirect();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items", [
            'name' => 'Tuition', 'amount' => 50000, 'term_id' => $term->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $feeItem = FeeItem::first();
        $notified = Notification::where('user_id', $principal->id)->where('category', 'fee_item_pending_approval')->exists();
        app(TenantManager::class)->forget();

        $this->assertSame(FeeItem::APPROVAL_PENDING, $feeItem->approval_status);
        $this->assertTrue($notified, 'the configured approver must be notified');
    }

    public function test_a_pending_fee_item_is_hidden_from_a_parent_until_approved(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finapprovalthree');
        $parent = User::factory()->create();
        $parentRole = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parent->id, ['role_id' => $parentRole->id, 'status' => 'active']);
        $principal = User::factory()->create();
        $principalRole = $this->rbac->cloneTemplateRole($school, 'principal');
        $school->users()->attach($principal->id, ['role_id' => $principalRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        // A parent with no linked child sees "No student on record" and
        // the fee item picker never renders at all, which would make
        // both assertions below trivially (and misleadingly) pass.
        $student = Student::create(['admission_number' => 'FA1', 'first_name' => 'Approval', 'last_name' => 'Child']);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items/approval-settings", [
            'enabled' => 1, 'chain' => ['role:principal'],
        ])->assertRedirect();
        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items", [
            'name' => 'Excursion Fee', 'amount' => 12000, 'term_id' => $term->id,
        ])->assertRedirect();

        // assertDontSee('Excursion Fee') alone would be a false positive
        // here: the bursar's own store() flash message ("\"Excursion
        // Fee\" added and is awaiting approval...") shares this test's
        // session/flash state and would otherwise still be on screen for
        // the very next request. The formatted amount, which only the
        // dropdown option itself would render, is a reliable stand-in.
        $this->actingAs($parent)->get("http://{$school->slug}.localhost/finance/payments/create")
            ->assertOk()->assertDontSee('₦12,000.00');

        app(TenantManager::class)->use($school);
        $feeItem = FeeItem::where('name', 'Excursion Fee')->first();
        app(TenantManager::class)->forget();

        $this->actingAs($principal)->post("http://{$school->slug}.localhost/finance/fee-items/{$feeItem->id}/approve")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(FeeItem::APPROVAL_APPROVED, $feeItem->fresh()->approval_status);
        $this->assertSame($principal->id, $feeItem->fresh()->approved_by_user_id);
        app(TenantManager::class)->forget();

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/finance/payments/create")
            ->assertOk()->assertSee('₦12,000.00');
    }

    public function test_a_specific_staff_member_can_be_named_an_approver_by_search(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finapprovalfour');
        $teacherUser = User::factory()->create();
        $teacherRole = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($teacherUser->id, ['role_id' => $teacherRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $staff = \App\Models\Tenant\Staff::create(['staff_id' => 'STF/APP1', 'first_name' => 'Assistant', 'last_name' => 'HeadTeacher', 'user_id' => $teacherUser->id]);
        $term = $this->currentTerm();
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items/approval-settings", [
            'enabled' => 1, 'chain' => ["staff:{$staff->id}"],
        ])->assertRedirect();
        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items", [
            'name' => 'Tuition', 'amount' => 50000, 'term_id' => $term->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $feeItem = FeeItem::first();
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->post("http://{$school->slug}.localhost/finance/fee-items/{$feeItem->id}/approve")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(FeeItem::APPROVAL_APPROVED, $feeItem->fresh()->approval_status);
        app(TenantManager::class)->forget();
    }

    public function test_someone_not_in_the_approval_chain_cannot_approve(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finapprovalfive');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items/approval-settings", [
            'enabled' => 1, 'chain' => ['role:principal'],
        ])->assertRedirect();
        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items", [
            'name' => 'Tuition', 'amount' => 50000, 'term_id' => $term->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $feeItem = FeeItem::first();
        app(TenantManager::class)->forget();

        // The bursar created it but is not in the configured chain, so
        // cannot approve their own submission.
        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items/{$feeItem->id}/approve")
            ->assertForbidden();
    }

    public function test_a_rejected_fee_item_cannot_be_approved_afterwards(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finapprovalsix');
        $principal = User::factory()->create();
        $principalRole = $this->rbac->cloneTemplateRole($school, 'principal');
        $school->users()->attach($principal->id, ['role_id' => $principalRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items/approval-settings", [
            'enabled' => 1, 'chain' => ['role:principal'],
        ])->assertRedirect();
        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/fee-items", [
            'name' => 'Tuition', 'amount' => 50000, 'term_id' => $term->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $feeItem = FeeItem::first();
        app(TenantManager::class)->forget();

        $this->actingAs($principal)->post("http://{$school->slug}.localhost/finance/fee-items/{$feeItem->id}/reject")
            ->assertRedirect();
        $this->actingAs($principal)->post("http://{$school->slug}.localhost/finance/fee-items/{$feeItem->id}/approve")
            ->assertRedirect()->assertSessionHas('error');

        app(TenantManager::class)->use($school);
        $this->assertSame(FeeItem::APPROVAL_REJECTED, $feeItem->fresh()->approval_status);
        app(TenantManager::class)->forget();
    }

    /**
     * "Under payments, show a data table of all the classes in the
     * school, number of student per class, number that has paid full
     * fees, number that has paid partial payments, a trigger [to nudge]
     * parent to those who are yet to pay at all, or have partial payment
     * left" (user request 2026-07-25).
     */
    public function test_the_payments_page_shows_class_level_fee_status_for_the_current_term(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finstatusone');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id]);
        $feeItem = FeeItem::create(['name' => 'Tuition', 'amount' => 10000, 'term_id' => $term->id]);
        $feeItem->schoolClasses()->sync([$class->id]);

        $paidFull = Student::create(['admission_number' => 'FS1', 'first_name' => 'Paid', 'last_name' => 'Full', 'current_class_arm_id' => $arm->id]);
        $paidPartial = Student::create(['admission_number' => 'FS2', 'first_name' => 'Paid', 'last_name' => 'Partial', 'current_class_arm_id' => $arm->id]);
        $unpaid = Student::create(['admission_number' => 'FS3', 'first_name' => 'Not', 'last_name' => 'Paid', 'current_class_arm_id' => $arm->id]);
        Payment::forceCreate(['student_id' => $paidFull->id, 'fee_item_id' => $feeItem->id, 'amount' => 10000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $bursar->id]);
        Payment::forceCreate(['student_id' => $paidPartial->id, 'fee_item_id' => $feeItem->id, 'amount' => 4000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $bursar->id]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/payments");
        $response->assertOk()
            ->assertSee('fee status by class', false)
            ->assertSee('Primary 1');
    }

    /**
     * User report 2026-07-25: "classes with streams was not splitted" — a
     * class with more than one stream (e.g. Year 1's Gold/Silver arms,
     * or SSS's Science/Arts/Commercial arms) was being shown as one
     * merged row on the class level fee status table instead of one row
     * per stream.
     */
    public function test_a_class_with_multiple_streams_shows_one_row_per_stream_not_merged(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finstreamone');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Year 1', 'sequence' => 1]);
        $gold = \App\Models\Tenant\Arm::create(['name' => 'Gold']);
        $silver = \App\Models\Tenant\Arm::create(['name' => 'Silver']);
        $goldArm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $gold->id]);
        $silverArm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $silver->id]);
        $feeItem = FeeItem::create(['name' => 'Tuition', 'amount' => 10000, 'term_id' => $term->id]);
        $feeItem->schoolClasses()->sync([$class->id]);

        Student::create(['admission_number' => 'FSG1', 'first_name' => 'Gold', 'last_name' => 'One', 'current_class_arm_id' => $goldArm->id]);
        Student::create(['admission_number' => 'FSS1', 'first_name' => 'Silver', 'last_name' => 'One', 'current_class_arm_id' => $silverArm->id]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/payments");
        $response->assertOk()
            ->assertSee('Year 1 Gold')
            ->assertSee('Year 1 Silver');
    }

    public function test_nudging_only_notifies_guardians_of_students_who_still_owe(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finstatustwo');
        $paidGuardianUser = User::factory()->create();
        $unpaidGuardianUser = User::factory()->create();
        $parentRole = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($paidGuardianUser->id, ['role_id' => $parentRole->id, 'status' => 'active']);
        $school->users()->attach($unpaidGuardianUser->id, ['role_id' => $parentRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 2', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id]);
        $feeItem = FeeItem::create(['name' => 'Tuition', 'amount' => 10000, 'term_id' => $term->id]);
        $feeItem->schoolClasses()->sync([$class->id]);

        $paidStudent = Student::create(['admission_number' => 'FS4', 'first_name' => 'Paid', 'last_name' => 'Up', 'current_class_arm_id' => $arm->id]);
        $unpaidStudent = Student::create(['admission_number' => 'FS5', 'first_name' => 'Owes', 'last_name' => 'Still', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $paidStudent->id, 'name' => 'Paid Guardian', 'relationship' => 'Mother', 'guardian_user_id' => $paidGuardianUser->id, 'is_primary' => true]);
        StudentGuardian::create(['student_id' => $unpaidStudent->id, 'name' => 'Unpaid Guardian', 'relationship' => 'Mother', 'guardian_user_id' => $unpaidGuardianUser->id, 'is_primary' => true]);
        Payment::forceCreate(['student_id' => $paidStudent->id, 'fee_item_id' => $feeItem->id, 'amount' => 10000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $bursar->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/payments/nudge", [
            'class_arm_id' => $arm->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $paidNotified = Notification::where('user_id', $paidGuardianUser->id)->where('category', 'payment_reminder')->exists();
        $unpaidNotified = Notification::where('user_id', $unpaidGuardianUser->id)->where('category', 'payment_reminder')->exists();
        app(TenantManager::class)->forget();

        $this->assertFalse($paidNotified, 'a guardian whose child already paid in full must not be nudged');
        $this->assertTrue($unpaidNotified, 'a guardian whose child still owes must be nudged');
    }

    /**
     * User request 2026-07-26: "add the access to view parents who have
     * paid under the Payments section so that if the user click on the
     * class, it shows all the students who have paid, amount paid,
     * balance if there is, date paid, and same for those who are yet to
     * pay, this is done per class."
     */
    public function test_clicking_a_class_on_the_payments_page_shows_the_per_student_payment_detail(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finclassdetail');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 5', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id]);
        $feeItem = FeeItem::create(['name' => 'Tuition', 'amount' => 10000, 'term_id' => $term->id]);
        $feeItem->schoolClasses()->sync([$class->id]);

        $paidFull = Student::create(['admission_number' => 'FD1', 'first_name' => 'Full', 'last_name' => 'Payer', 'current_class_arm_id' => $arm->id]);
        $partial = Student::create(['admission_number' => 'FD2', 'first_name' => 'Part', 'last_name' => 'Payer', 'current_class_arm_id' => $arm->id]);
        $unpaid = Student::create(['admission_number' => 'FD3', 'first_name' => 'No', 'last_name' => 'Payer', 'current_class_arm_id' => $arm->id]);
        Payment::forceCreate(['student_id' => $paidFull->id, 'fee_item_id' => $feeItem->id, 'amount' => 10000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $bursar->id, 'verified_at' => now()]);
        Payment::forceCreate(['student_id' => $partial->id, 'fee_item_id' => $feeItem->id, 'amount' => 4000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $bursar->id, 'verified_at' => now()]);
        $armId = $arm->id;
        app(TenantManager::class)->forget();

        // The class name on the class status table links straight here.
        $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/payments?term_id={$term->id}")
            ->assertOk()->assertSee(route('school.finance.payments.class-detail', ['school' => $school->slug, 'classArmId' => $armId, 'term_id' => $term->id]), false);

        $response = $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/payments/classes/{$armId}?term_id={$term->id}");
        $response->assertOk()
            ->assertSee('Full Payer')->assertSee('Part Payer')->assertSee('No Payer')
            ->assertSee('₦10,000.00')  // full payer's amount paid
            ->assertSee('₦4,000.00')   // partial payer's amount paid
            ->assertSee('₦6,000.00')   // partial payer's balance (10000 - 4000)
            ->assertSee('Paid in full')->assertSee('Partial')->assertSee('Not paid');
    }

    /**
     * Term switcher (user request 2026-07-25: "one can switch term view
     * but the default for parent should be set by admin"). Precedence:
     * ?term_id= query param, then the finance_active_term_id setting,
     * then Term::current().
     */
    public function test_the_payments_page_defaults_to_the_current_term_when_no_setting_is_configured(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finswitchone');

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/payments")
            ->assertOk()->assertSee($term->name.' ('.$term->session->name.')');
    }

    public function test_a_bursar_can_set_the_default_term_parents_see_and_it_overrides_current_term(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finswitchtwo');

        app(TenantManager::class)->use($school);
        $currentTerm = $this->currentTerm();
        $otherSession = AcademicSession::create(['name' => '2026/2027']);
        $upcomingTerm = Term::create([
            'academic_session_id' => $otherSession->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => false,
            'starts_on' => now()->addMonths(4), 'ends_on' => now()->addMonths(7),
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/payments/active-term", [
            'term_id' => $upcomingTerm->id,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertEquals($upcomingTerm->id, (int) \App\Models\Tenant\Setting::grab('finance_active_term_id'));
        app(TenantManager::class)->forget();

        // Now no ?term_id= is passed, so the page falls back to the
        // admin-set default (the upcoming term), not Term::current().
        $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/payments")
            ->assertOk()->assertSee('selected', false)
            ->assertSee($upcomingTerm->name.' ('.$upcomingTerm->session->name.')');
    }

    public function test_a_query_string_term_id_overrides_the_admin_default_for_viewing_only(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finswitchthree');

        app(TenantManager::class)->use($school);
        $currentTerm = $this->currentTerm();
        \App\Models\Tenant\Setting::put('finance_active_term_id', $currentTerm->id);
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 9', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id]);
        $otherSession = AcademicSession::create(['name' => '2026/2027']);
        $otherTerm = Term::create([
            'academic_session_id' => $otherSession->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => false,
            'starts_on' => now()->addMonths(4), 'ends_on' => now()->addMonths(7),
        ]);
        $feeItem = FeeItem::create(['name' => 'Tuition', 'amount' => 5000, 'term_id' => $otherTerm->id]);
        $feeItem->schoolClasses()->sync([$class->id]);
        Student::create(['admission_number' => 'FS9', 'first_name' => 'Some', 'last_name' => 'Kid', 'current_class_arm_id' => $arm->id]);
        app(TenantManager::class)->forget();

        // Viewing the other term explicitly shows its class status, even
        // though the admin default is still the current term.
        $this->actingAs($bursar)->get("http://{$school->slug}.localhost/finance/payments?term_id={$otherTerm->id}")
            ->assertOk()->assertSee($otherTerm->name.' fee status by class', false)
            ->assertSee('Primary 9');

        // The stored admin default is untouched by merely viewing another term.
        app(TenantManager::class)->use($school);
        $this->assertEquals($currentTerm->id, (int) \App\Models\Tenant\Setting::grab('finance_active_term_id'));
        app(TenantManager::class)->forget();
    }

    public function test_a_parent_cannot_change_the_default_term_setting(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finswitchfour');
        $parent = User::factory()->create();
        $parentRole = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parent->id, ['role_id' => $parentRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        app(TenantManager::class)->forget();

        $this->actingAs($parent)->post("http://{$school->slug}.localhost/finance/payments/active-term", [
            'term_id' => $term->id,
        ])->assertForbidden();
    }

    /**
     * Payment countdown/reminder popup (user request 2026-07-25: "let the
     * admin configure a payment countdown date to parents who will see
     * how many days left to payments, if it has past, it keeps showing
     * as over due, 15 days (as set by the admin) it shows daily on the
     * parent login as pop up reminder to pay").
     */
    public function test_a_bursar_can_set_the_payment_due_date_and_reminder_window(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finduedateone');

        $this->actingAs($bursar)->post("http://{$school->slug}.localhost/finance/payments/due-date", [
            'payment_due_date' => now()->addDays(20)->toDateString(), 'payment_reminder_days' => 10,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $due = \App\Models\Tenant\Setting::grab('payment_due_date');
        $days = \App\Models\Tenant\Setting::grab('payment_reminder_days');
        app(TenantManager::class)->forget();

        $this->assertNotNull($due);
        $this->assertSame(10, (int) $days);
    }

    public function test_a_parent_with_an_outstanding_balance_sees_the_payment_popup_inside_the_reminder_window(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finduedatetwo');
        $parent = User::factory()->create();
        $parentRole = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parent->id, ['role_id' => $parentRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 3', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id]);
        $feeItem = FeeItem::create(['name' => 'Tuition', 'amount' => 10000, 'term_id' => $term->id]);
        $feeItem->schoolClasses()->sync([$class->id]);
        $student = Student::create(['admission_number' => 'FD1', 'first_name' => 'Owes', 'last_name' => 'Money', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        \App\Models\Tenant\Setting::put('payment_due_date', now()->addDays(5)->toDateString());
        \App\Models\Tenant\Setting::put('payment_reminder_days', 15);
        app(TenantManager::class)->forget();

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('payPopupScrim', false)->assertSee('₦10,000.00');
    }

    public function test_a_parent_who_has_paid_in_full_does_not_see_the_payment_popup(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finduedatethree');
        $parent = User::factory()->create();
        $parentRole = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parent->id, ['role_id' => $parentRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 4', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id]);
        $feeItem = FeeItem::create(['name' => 'Tuition', 'amount' => 10000, 'term_id' => $term->id]);
        $feeItem->schoolClasses()->sync([$class->id]);
        $student = Student::create(['admission_number' => 'FD2', 'first_name' => 'Paid', 'last_name' => 'Up', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        Payment::forceCreate(['student_id' => $student->id, 'fee_item_id' => $feeItem->id, 'amount' => 10000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $bursar->id]);
        \App\Models\Tenant\Setting::put('payment_due_date', now()->addDays(5)->toDateString());
        \App\Models\Tenant\Setting::put('payment_reminder_days', 15);
        app(TenantManager::class)->forget();

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertDontSee('payPopupScrim', false);
    }

    public function test_a_parent_does_not_see_the_payment_popup_outside_the_reminder_window(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finduedatefour');
        $parent = User::factory()->create();
        $parentRole = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parent->id, ['role_id' => $parentRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 5', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id]);
        $feeItem = FeeItem::create(['name' => 'Tuition', 'amount' => 10000, 'term_id' => $term->id]);
        $feeItem->schoolClasses()->sync([$class->id]);
        $student = Student::create(['admission_number' => 'FD3', 'first_name' => 'Not', 'last_name' => 'Yet', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        // Due date is 30 days out, reminder window is only the last 15,
        // so today is not inside it yet.
        \App\Models\Tenant\Setting::put('payment_due_date', now()->addDays(30)->toDateString());
        \App\Models\Tenant\Setting::put('payment_reminder_days', 15);
        app(TenantManager::class)->forget();

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertDontSee('payPopupScrim', false);
    }

    public function test_an_overdue_payment_popup_keeps_showing_regardless_of_the_reminder_window(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finduedatefive');
        $parent = User::factory()->create();
        $parentRole = $this->rbac->cloneTemplateRole($school, 'parent');
        $school->users()->attach($parent->id, ['role_id' => $parentRole->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 6', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id]);
        $feeItem = FeeItem::create(['name' => 'Tuition', 'amount' => 10000, 'term_id' => $term->id]);
        $feeItem->schoolClasses()->sync([$class->id]);
        $student = Student::create(['admission_number' => 'FD4', 'first_name' => 'Overdue', 'last_name' => 'Family', 'current_class_arm_id' => $arm->id]);
        StudentGuardian::create(['student_id' => $student->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        // 60 days overdue, well outside the 15 day reminder window, but
        // overdue must always still show ("if it has past, it keeps
        // showing as over due").
        \App\Models\Tenant\Setting::put('payment_due_date', now()->subDays(60)->toDateString());
        \App\Models\Tenant\Setting::put('payment_reminder_days', 15);
        app(TenantManager::class)->forget();

        $this->actingAs($parent)->get("http://{$school->slug}.localhost/dashboard")
            ->assertOk()->assertSee('payPopupScrim', false)->assertSee('Fees payment overdue');
    }

    public function test_fee_balance_service_computes_outstanding_across_multiple_children(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finbalanceone');
        $parent = User::factory()->create();

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $classA = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 7', 'sequence' => 1]);
        $classB = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 8', 'sequence' => 2]);
        $armA = \App\Models\Tenant\ClassArm::create(['school_class_id' => $classA->id]);
        $armB = \App\Models\Tenant\ClassArm::create(['school_class_id' => $classB->id]);
        $feeA = FeeItem::create(['name' => 'Tuition', 'amount' => 10000, 'term_id' => $term->id]);
        $feeA->schoolClasses()->sync([$classA->id]);
        $feeB = FeeItem::create(['name' => 'Tuition', 'amount' => 15000, 'term_id' => $term->id]);
        $feeB->schoolClasses()->sync([$classB->id]);
        $childA = Student::create(['admission_number' => 'FB1', 'first_name' => 'Child', 'last_name' => 'A', 'current_class_arm_id' => $armA->id]);
        $childB = Student::create(['admission_number' => 'FB2', 'first_name' => 'Child', 'last_name' => 'B', 'current_class_arm_id' => $armB->id]);
        StudentGuardian::create(['student_id' => $childA->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        StudentGuardian::create(['student_id' => $childB->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        Payment::forceCreate(['student_id' => $childA->id, 'amount' => 10000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $bursar->id]);
        Payment::forceCreate(['student_id' => $childB->id, 'amount' => 6000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $bursar->id]);

        $outstanding = app(\App\Services\Finance\FeeBalanceService::class)->outstandingForGuardian($parent, $term);
        app(TenantManager::class)->forget();

        // Child A fully paid (0 owed), Child B owes 15000-6000 = 9000.
        $this->assertSame(9000.0, $outstanding);
    }

    /**
     * User directive 2026-07-27, after directly rejecting a per-child
     * clamped combined balance: "he overpaid for [one child] and so the
     * overpaid amount should cover other children... calculate the
     * total to be pay by all the children... then deduct the [total]
     * paid... that should be the new balance." A family is billed and
     * pays as one pool, so a surplus on one child's fees must reduce
     * the balance owed for the rest of the family, not disappear at
     * that child's own zero floor the way outstandingForGuardian()
     * (tested above) intentionally still does.
     */
    public function test_fee_balance_service_pools_an_overpayment_across_siblings(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finpooledone');
        $parent = User::factory()->create();

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $classA = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 9', 'sequence' => 1]);
        $classB = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 10', 'sequence' => 2]);
        $armA = \App\Models\Tenant\ClassArm::create(['school_class_id' => $classA->id]);
        $armB = \App\Models\Tenant\ClassArm::create(['school_class_id' => $classB->id]);
        $feeA = FeeItem::create(['name' => 'Tuition', 'amount' => 71500, 'term_id' => $term->id]);
        $feeA->schoolClasses()->sync([$classA->id]);
        $feeB = FeeItem::create(['name' => 'Tuition', 'amount' => 190000, 'term_id' => $term->id]);
        $feeB->schoolClasses()->sync([$classB->id]);
        $childA = Student::create(['admission_number' => 'FP1', 'first_name' => 'Overpaid', 'last_name' => 'Sibling', 'current_class_arm_id' => $armA->id]);
        $childB = Student::create(['admission_number' => 'FP2', 'first_name' => 'Unpaid', 'last_name' => 'Sibling', 'current_class_arm_id' => $armB->id]);
        StudentGuardian::create(['student_id' => $childA->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        StudentGuardian::create(['student_id' => $childB->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        // Child A overpaid by 128500 (200000 paid vs 71500 owed), Child B
        // has paid nothing on their own 190000.
        Payment::forceCreate(['student_id' => $childA->id, 'amount' => 200000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $bursar->id]);

        $pooled = app(\App\Services\Finance\FeeBalanceService::class)->nonOptionalOutstandingPooled($parent, $term);
        $perChildClamped = app(\App\Services\Finance\FeeBalanceService::class)->outstandingForGuardian($parent, $term);
        app(TenantManager::class)->forget();

        // Pooled: (71500 + 190000) owed - 200000 paid = 61500, Child A's
        // surplus reduces Child B's real balance.
        $this->assertSame(61500.0, $pooled);
        // The older per-child clamped total intentionally stays a
        // different, higher figure (Child A's surplus clamped away
        // instead of carried over): 0 + 190000 = 190000. Both methods
        // are kept, only the callers changed which one they use.
        $this->assertSame(190000.0, $perChildClamped);
    }

    /**
     * User directive 2026-07-28, reacting to the per-child popup showing
     * a real overpaid child's raw payment against only their own fee:
     * "the status ought to show 71,500.00 paid and the balance from
     * the 200,000 spill to other children using same approach." The
     * per row figures must visibly explain the pooled total, not just
     * restate each child's own raw payment next to it.
     */
    public function test_fee_balance_service_waterfalls_a_surplus_across_multiple_siblings_per_row(): void
    {
        [$school, $bursar] = $this->schoolWithMember('bursar', 'finwaterfallone');
        $parent = User::factory()->create();

        app(TenantManager::class)->use($school);
        $term = $this->currentTerm();
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $classA = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $classB = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 4', 'sequence' => 2]);
        $classC = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 2', 'sequence' => 3]);
        $armA = \App\Models\Tenant\ClassArm::create(['school_class_id' => $classA->id]);
        $armB = \App\Models\Tenant\ClassArm::create(['school_class_id' => $classB->id]);
        $armC = \App\Models\Tenant\ClassArm::create(['school_class_id' => $classC->id]);
        $feeA = FeeItem::create(['name' => 'Tuition', 'amount' => 71500, 'term_id' => $term->id]);
        $feeA->schoolClasses()->sync([$classA->id]);
        $feeB = FeeItem::create(['name' => 'Tuition', 'amount' => 83500, 'term_id' => $term->id]);
        $feeB->schoolClasses()->sync([$classB->id]);
        $feeC = FeeItem::create(['name' => 'Tuition', 'amount' => 106500, 'term_id' => $term->id]);
        $feeC->schoolClasses()->sync([$classC->id]);
        $childA = Student::create(['admission_number' => 'FW1', 'first_name' => 'Overpaid', 'last_name' => 'One', 'current_class_arm_id' => $armA->id]);
        $childB = Student::create(['admission_number' => 'FW2', 'first_name' => 'Unpaid', 'last_name' => 'Two', 'current_class_arm_id' => $armB->id]);
        $childC = Student::create(['admission_number' => 'FW3', 'first_name' => 'Unpaid', 'last_name' => 'Three', 'current_class_arm_id' => $armC->id]);
        StudentGuardian::create(['student_id' => $childA->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        StudentGuardian::create(['student_id' => $childB->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        StudentGuardian::create(['student_id' => $childC->id, 'name' => 'Parent', 'relationship' => 'Mother', 'guardian_user_id' => $parent->id, 'is_primary' => true]);
        // Child A owes 71500, paid 200000 (128500 surplus). Children B
        // and C owe 83500 and 106500 respectively, paid nothing.
        Payment::forceCreate(['student_id' => $childA->id, 'amount' => 200000, 'method' => 'bank_transfer', 'status' => Payment::STATUS_VERIFIED, 'submitted_by_user_id' => $bursar->id]);

        $rows = app(\App\Services\Finance\FeeBalanceService::class)->nonOptionalOutstandingPerChildPooled($parent, $term)->values();
        app(TenantManager::class)->forget();

        // Child A: fully covered by their own payment, none of the
        // surplus is attributed back to them.
        $this->assertSame(71500.0, $rows[0]['owed']);
        $this->assertSame(71500.0, $rows[0]['paid']);
        $this->assertSame(0.0, $rows[0]['due']);
        // Child B: fully covered by Child A's surplus (83500 of the
        // 128500 applied here first).
        $this->assertSame(83500.0, $rows[1]['owed']);
        $this->assertSame(83500.0, $rows[1]['paid']);
        $this->assertSame(0.0, $rows[1]['due']);
        // Child C: gets whatever surplus is left (128500 - 83500 =
        // 45000), leaving a real balance of 106500 - 45000 = 61500.
        $this->assertSame(106500.0, $rows[2]['owed']);
        $this->assertSame(45000.0, $rows[2]['paid']);
        $this->assertSame(61500.0, $rows[2]['due']);
        // The three rows' balances still sum to the same pooled total.
        $this->assertSame(61500.0, $rows->sum('due'));
    }
}
