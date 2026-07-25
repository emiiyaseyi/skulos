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
            'student_id' => $student->id,
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
            'student_id' => $student->id,
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
            'student_id' => $otherStudent->id,
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
            'student_id' => $child->id, 'fee_item_id' => $item->id, 'fee_item_installment_id' => $installment->id,
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
            'student_id' => $child->id, 'fee_item_id' => $itemA->id, 'fee_item_installment_id' => $installmentOfB->id,
            'amount' => 30000, 'evidence' => $file,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $payment = Payment::where('student_id', $child->id)->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($payment);
        $this->assertNull($payment->fee_item_installment_id);
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
}
