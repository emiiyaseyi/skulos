<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\FeeItem;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Payment;
use App\Models\Tenant\Student;
use App\Services\Media\ImageProcessingService;
use App\Services\Rbac\FamilyScope;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Bank Transfer Payment Option, Payment Evidence Upload, Payment
 * Verification (Foundation Finance slice, 2026-07-17): a guardian/
 * student submits proof of a bank transfer against a student, a human
 * (Bursar/Accountant/admin) verifies or rejects it. No payment gateway,
 * no automated matching, verification is a deliberate human step.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class PaymentController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request, string $permission): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), $permission),
            403,
            'You do not have permission for this.'
        );
    }

    /**
     * A staff member with finance.view sees every submission (optionally
     * filtered by status), a parent/student sees only their own family's
     * (FamilyScope, the same scoping pattern Students/Assignments already
     * use for these two roles).
     */
    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'finance.view');
        $school = $this->school($request);
        $user = Auth::user();
        $familyStudentIds = app(FamilyScope::class)->studentIds($user, $school);

        $status = $request->query('status');

        $payments = Payment::with('student.currentClassArm.schoolClass', 'feeItem')
            ->when($familyStudentIds !== null, fn ($q) => $q->whereIn('student_id', $familyStudentIds))
            ->when(in_array($status, [Payment::STATUS_PENDING, Payment::STATUS_VERIFIED, Payment::STATUS_REJECTED], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('school.finance.payments.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'payments' => $payments,
            'status' => $status,
            'isFamily' => $familyStudentIds !== null,
            'canVerify' => app(Rbac::class)->check($user, $school, 'finance.update'),
            'canSubmit' => app(Rbac::class)->check($user, $school, 'finance.create'),
        ]);
    }

    public function create(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'finance.create');
        $school = $this->school($request);
        $user = Auth::user();
        $familyStudentIds = app(FamilyScope::class)->studentIds($user, $school);

        $students = Student::active()
            ->when($familyStudentIds !== null, fn ($q) => $q->whereIn('id', $familyStudentIds))
            ->orderBy('last_name')
            ->get();

        return view('school.finance.payments.create', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'students' => $students,
            'feeItems' => FeeItem::active()->with('term')->orderByDesc('created_at')->get(),
        ]);
    }

    public function store(Request $request, ImageProcessingService $images): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.create');
        $school = $this->school($request);
        $user = Auth::user();
        $familyStudentIds = app(FamilyScope::class)->studentIds($user, $school);

        $data = $request->validate([
            'student_id' => ['required', 'exists:tenant.students,id'],
            'fee_item_id' => ['nullable', 'exists:tenant.fee_items,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:120'],
            'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.config('skulos.upload_limits.payment_evidence_kb')],
        ]);

        // A parent/student may only ever submit for their own linked
        // child/self, a crafted student_id outside that set is rejected,
        // never silently redirected onto someone else's record.
        if ($familyStudentIds !== null && ! $familyStudentIds->contains((int) $data['student_id'])) {
            abort(404);
        }

        // Financial evidence never goes on the public disk (audit finding
        // M-2, 2026-07-17): a bank receipt/proof-of-payment is served only
        // through the authenticated evidence() action below, which re-runs
        // the same finance.view + FamilyScope check every other action on
        // this controller already enforces.
        $file = $request->file('evidence');
        $evidencePath = $file->getClientOriginalExtension() === 'pdf'
            ? $file->storeAs("payments/{$school->slug}", Str::random(24).'.pdf', 'local')
            : $images->toWebp($file, "payments/{$school->slug}", disk: 'local');

        // If the record create fails after the file already uploaded
        // successfully, the file is deleted rather than left orphaned on
        // disk indefinitely, unreferenced by any record (audit finding
        // M-6, 2026-07-17).
        try {
            // status is intentionally not passed here: it is not
            // mass-assignable (see Payment::$fillable), so a new payment
            // always starts at the migration's own status='pending'
            // column default, the single source of truth for that.
            $payment = Payment::create([
                'student_id' => $data['student_id'],
                'fee_item_id' => $data['fee_item_id'] ?? null,
                'amount' => $data['amount'],
                'method' => 'bank_transfer',
                'bank_name' => $data['bank_name'] ?? null,
                'reference' => $data['reference'] ?? null,
                'evidence_path' => $evidencePath,
                'submitted_by_user_id' => $user->id,
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($evidencePath);
            throw $e;
        }

        $this->notifyFinanceStaff($school, $payment, 'payment_submitted', 'New payment submitted', "{$payment->student->fullName()}'s payment of ₦".number_format((float) $payment->amount, 2).' is awaiting verification.');

        return redirect()->route('school.finance.payments.index', ['school' => $school->slug])
            ->with('status', 'Payment submitted. You will be notified once it is verified.');
    }

    public function show(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'finance.view');
        $school = $this->school($request);
        $user = Auth::user();
        $familyStudentIds = app(FamilyScope::class)->studentIds($user, $school);

        $payment = Payment::with('student.currentClassArm.schoolClass', 'feeItem')->findOrFail($request->route('paymentId'));

        if ($familyStudentIds !== null && ! $familyStudentIds->contains($payment->student_id)) {
            abort(404);
        }

        return view('school.finance.payments.show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'payment' => $payment,
            'canVerify' => app(Rbac::class)->check($user, $school, 'finance.update'),
        ]);
    }

    /**
     * Streams evidence from the private 'local' disk rather than a bare
     * public URL, re-checking finance.view + FamilyScope on every request
     * (audit finding M-2, 2026-07-17): financial evidence was previously
     * served straight from the public disk, reachable by anyone with the
     * URL, entirely outside this app's RBAC/family scoping.
     */
    public function evidence(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeAction($request, 'finance.view');
        $school = $this->school($request);
        $user = Auth::user();
        $familyStudentIds = app(FamilyScope::class)->studentIds($user, $school);

        $payment = Payment::findOrFail($request->route('paymentId'));
        if ($familyStudentIds !== null && ! $familyStudentIds->contains($payment->student_id)) {
            abort(404);
        }
        abort_unless($payment->evidence_path, 404);

        return Storage::disk('local')->response($payment->evidence_path);
    }

    public function verify(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->decide($request, Payment::STATUS_VERIFIED);
    }

    public function reject(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->decide($request, Payment::STATUS_REJECTED);
    }

    /**
     * The read-then-write shape this replaced let two staff acting on the
     * same payment within the same window both pass the "still pending"
     * check and both write, double-notifying the submitting guardian and
     * leaving the payment's final status a race (audit finding C-2,
     * 2026-07-17). The update itself is now the atomic guard: only a row
     * that is still pending at the moment of the UPDATE can transition, and
     * only the request whose UPDATE actually changed a row proceeds to
     * notify.
     */
    protected function decide(Request $request, string $status): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');
        $payment = Payment::with('student')->findOrFail($request->route('paymentId'));

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        $applied = $this->applyDecision($payment, $status, $data['notes'] ?? null);

        if (! $applied) {
            return back()->with('error', 'This payment has already been processed.');
        }

        $verb = $status === Payment::STATUS_VERIFIED ? 'verified' : 'rejected';

        return back()->with('status', "Payment marked as {$verb}.");
    }

    /**
     * The atomic status-transition guard (audit finding C-2, 2026-07-17)
     * plus the resulting notification, shared by the single-record
     * decide() above and bulkAction() below so both apply the exact same
     * race-safe logic rather than two copies drifting apart.
     */
    protected function applyDecision(Payment $payment, string $status, ?string $notes): bool
    {
        $updated = Payment::where('id', $payment->id)
            ->where('status', Payment::STATUS_PENDING)
            ->update([
                'status' => $status,
                'verified_by_user_id' => Auth::id(),
                'verified_at' => now(),
                'notes' => $notes,
            ]);

        if ($updated === 0) {
            return false;
        }

        $verb = $status === Payment::STATUS_VERIFIED ? 'verified' : 'rejected';
        $category = $status === Payment::STATUS_VERIFIED ? 'payment_verified' : 'payment_rejected';
        Notification::create([
            'user_id' => $payment->submitted_by_user_id,
            'category' => $category,
            'type' => Notification::typeFor($category),
            'title' => "Payment {$verb}",
            'body' => "{$payment->student->fullName()}'s payment of ₦".number_format((float) $payment->amount, 2)." was {$verb}.".($notes ? ' Note: '.$notes : ''),
        ]);

        return true;
    }

    /** Bulk verify/reject for ticked pending payments, matching the standing bulk-actions convention every list screen follows (audit finding M-12, 2026-07-17). */
    public function bulkAction(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');
        $data = $request->validate([
            'payment_ids' => ['required', 'array', 'min:1'],
            'payment_ids.*' => ['exists:tenant.payments,id'],
            'action' => ['required', 'in:verify,reject'],
        ]);
        $status = $data['action'] === 'verify' ? Payment::STATUS_VERIFIED : Payment::STATUS_REJECTED;

        $count = 0;
        foreach (Payment::with('student')->whereIn('id', $data['payment_ids'])->get() as $payment) {
            if ($this->applyDecision($payment, $status, null)) {
                $count++;
            }
        }

        $verb = $status === Payment::STATUS_VERIFIED ? 'verified' : 'rejected';

        return back()->with('status', "{$count} payment(s) marked as {$verb}.");
    }

    protected function notifyFinanceStaff(School $school, Payment $payment, string $category, string $title, string $body): void
    {
        $roleIds = \App\Models\Role::where('school_id', $school->id)
            ->whereIn('slug', ['bursar', 'accountant'])
            ->pluck('id');
        $roleIds = $roleIds->merge(\App\Models\Role::where('school_id', $school->id)
            ->whereIn('slug', config('skulos.leadership_role_slugs', []))
            ->pluck('id'));

        $userIds = \Illuminate\Support\Facades\DB::table('school_user')
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->whereIn('role_id', $roleIds)
            ->pluck('user_id');

        foreach ($userIds->unique() as $userId) {
            Notification::create([
                'user_id' => $userId,
                'category' => $category,
                'type' => Notification::typeFor($category),
                'title' => $title,
                'body' => $body,
            ]);
        }
    }
}
