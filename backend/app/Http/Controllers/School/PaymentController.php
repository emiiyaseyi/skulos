<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\BankAccount;
use App\Models\Tenant\FeeItem;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Payment;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\Tenant\Term;
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
        $activeTerm = $this->resolveActiveTerm($request);

        $payments = Payment::with('student.currentClassArm.schoolClass', 'feeItem', 'installment')
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
            'terms' => Term::with('session')->get()->sortByDesc(fn (Term $t) => sprintf('%05d-%05d', $t->session?->id ?? 0, $t->sequence ?? 0))->values(),
            'activeTerm' => $activeTerm,
            'defaultTermId' => Setting::grab('finance_active_term_id'),
            'classStatus' => $activeTerm ? $this->classStatus($activeTerm) : [],
            'paymentDueDate' => Setting::grab('payment_due_date'),
            'paymentReminderDays' => Setting::grab('payment_reminder_days', 15),
        ]);
    }

    /**
     * Which term the Finance screens show by default (user request
     * 2026-07-25: "one can switch term view but the default for parent
     * should be set by admin, use First Term 2026/2027 as what parent
     * can see but they also can switch term to see previous terms"),
     * deliberately its own tenant Setting ("finance_active_term_id"),
     * separate from the system wide Term::current() every other module
     * (attendance, academic calendar, report cards, ...) already reads:
     * a school setting up next term's fees ahead of time should not have
     * to flip the whole school over to that term early just to preview
     * this one screen. Falls back to Term::current() when the admin has
     * never set one, so nothing changes for a school that never opens
     * this setting. ?term_id on the query string (the switcher itself)
     * always wins when present, for anyone, admin or parent.
     */
    protected function resolveActiveTerm(Request $request): ?Term
    {
        $requestedId = $request->query('term_id');
        if ($requestedId && $term = Term::find($requestedId)) {
            return $term;
        }

        $defaultId = Setting::grab('finance_active_term_id');
        if ($defaultId && $term = Term::find($defaultId)) {
            return $term;
        }

        return Term::current()->first();
    }

    /** Which term parents see by default on Finance screens, until they switch (user request 2026-07-25). */
    public function updateActiveTerm(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');

        // The "school's current term (automatic)" option posts an empty
        // string, not the absence of the field, so it must be normalised
        // to null before validation, or `exists` would reject it.
        if ($request->input('term_id') === '') {
            $request->merge(['term_id' => null]);
        }
        $data = $request->validate(['term_id' => ['nullable', 'exists:tenant.terms,id']]);

        Setting::put('finance_active_term_id', $data['term_id'] ?? null);

        return back()->with('status', $data['term_id']
            ? 'Parents will now see this term by default on Finance screens (they can still switch to view another).'
            : 'Cleared. Finance screens fall back to the school\'s current term by default.');
    }

    /**
     * The payment countdown/reminder popup's own settings (user request
     * 2026-07-25: "let the admin configure a payment countdown date...
     * 15 days (as set by the admin)"). Left blank/cleared, no popup ever
     * shows, current behaviour for a school that never opens this.
     */
    public function updateDueDate(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');

        $data = $request->validate([
            'payment_due_date' => ['nullable', 'date'],
            'payment_reminder_days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        Setting::put('payment_due_date', $data['payment_due_date'] ?? null);
        Setting::put('payment_reminder_days', $data['payment_reminder_days']);

        return back()->with('status', $data['payment_due_date']
            ? 'Payment due date saved. Parents with an outstanding balance will see a daily reminder starting '.$data['payment_reminder_days'].' day(s) before it.'
            : 'Payment due date cleared. Parents will no longer see the reminder pop up.');
    }

    /**
     * "Under payments, show a data table of all the classes in the
     * school, number of student per class, number that has paid full
     * fees, number that has paid partial payments, a trigger [to nudge]
     * parent to those who are yet to pay at all, or have partial payment
     * left" (user request 2026-07-25). Scoped to the current term: what a
     * student owes is the sum of every approved, active fee item that
     * applies to their class for that term; what they have paid is the
     * sum of their own verified payments, regardless of which specific
     * fee item each one was recorded against (a school cares whether the
     * family is square for the term, not fee-item-by-fee-item
     * reconciliation, which the installment schedule already covers for
     * a fee item that specifically opts into it).
     *
     * One row per class ARM, not per class (user report 2026-07-25:
     * "classes with streams was not splitted" — a class with more than
     * one stream, e.g. Year 1's Gold/Silver or SSS's Science/Arts/
     * Commercial, was being shown as a single merged row). Fee items
     * still apply at the whole-class level (FeeItem::appliesToClass()),
     * only the student roll up is per arm, using ClassArm::label() for
     * the exact same "Basic 1 A" / "Basic 1" naming used everywhere else
     * a class arm is displayed.
     *
     * @return list<array{arm: ClassArm, label: string, studentCount: int, fullyPaid: int, partial: int, unpaid: int}>
     */
    protected function classStatus(Term $term): array
    {
        $arms = ClassArm::with('schoolClass.section', 'arm')
            ->whereHas('schoolClass', fn ($q) => $q->where('is_active', true))
            ->get()
            ->sortBy(fn (ClassArm $a) => sprintf('%05d-%05d-%05d', $a->schoolClass?->section?->sequence ?? 0, $a->schoolClass?->sequence ?? 0, $a->arm_id ?? 0));

        $feeItemsByClass = FeeItem::where('term_id', $term->id)->active()
            ->where('approval_status', FeeItem::APPROVAL_APPROVED)
            ->with('schoolClasses')->get();

        $verifiedByStudent = Payment::where('status', Payment::STATUS_VERIFIED)
            ->selectRaw('student_id, sum(amount) as total')->groupBy('student_id')->pluck('total', 'student_id');

        $rows = [];
        foreach ($arms as $arm) {
            $owed = $feeItemsByClass->filter(fn (FeeItem $f) => $f->appliesToClass($arm->school_class_id))->sum('amount');
            $students = Student::active()->where('current_class_arm_id', $arm->id)->get(['id']);

            if ($students->isEmpty() || $owed <= 0) {
                continue;
            }

            $fullyPaid = $partial = $unpaid = 0;
            foreach ($students as $student) {
                $paid = (float) ($verifiedByStudent[$student->id] ?? 0);
                if ($paid >= $owed) {
                    $fullyPaid++;
                } elseif ($paid > 0) {
                    $partial++;
                } else {
                    $unpaid++;
                }
            }

            $rows[] = ['arm' => $arm, 'label' => $arm->label(), 'studentCount' => $students->count(), 'fullyPaid' => $fullyPaid, 'partial' => $partial, 'unpaid' => $unpaid];
        }

        return $rows;
    }

    /**
     * Drills into one class arm's own list of students (user request
     * 2026-07-26: "add the access to view parents who have paid under
     * the Payments section so that if the user click on the class, it
     * shows all the students who have paid, amount paid, balance if
     * there is, date paid, and same for those who are yet to pay"). Same
     * owed calc as classStatus()/nudgeUnpaid(), scoped to whichever term
     * the class status table was actually showing (the term switcher).
     */
    public function classDetail(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'finance.view');
        $school = $this->school($request);
        $arm = ClassArm::with('schoolClass.section', 'arm')->findOrFail($request->route('classArmId'));

        $term = $this->resolveActiveTerm($request);
        abort_unless($term, 404, 'There is no term to show.');

        $owed = FeeItem::where('term_id', $term->id)->active()->where('approval_status', FeeItem::APPROVAL_APPROVED)
            ->with('schoolClasses')->get()->filter(fn (FeeItem $f) => $f->appliesToClass($arm->school_class_id))->sum('amount');

        $students = Student::active()->where('current_class_arm_id', $arm->id)->orderBy('first_name')->get();

        $paymentsByStudent = Payment::where('status', Payment::STATUS_VERIFIED)
            ->whereIn('student_id', $students->pluck('id'))
            ->get(['student_id', 'amount', 'verified_at']);

        $rows = $students->map(function (Student $student) use ($paymentsByStudent, $owed) {
            $payments = $paymentsByStudent->where('student_id', $student->id);
            $paid = (float) $payments->sum('amount');

            return [
                'student' => $student,
                'paid' => $paid,
                'balance' => max(0, $owed - $paid),
                'status' => $owed > 0 && $paid >= $owed ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
                'lastPaidAt' => $payments->max('verified_at'),
            ];
        })->sortBy(fn ($row) => $row['status'] === 'paid' ? 1 : 0)->values();

        return view('school.finance.payments.class-detail', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'arm' => $arm,
            'term' => $term,
            'owed' => $owed,
            'rows' => $rows,
        ]);
    }

    /**
     * Reminds every guardian whose child is in this class and has not
     * yet paid in full this term (user request 2026-07-25: "a trigger
     * parent to those who are yet to pay at all, or have partial payment
     * left"). One notification per guardian, not per student, so a
     * parent with two children owing in the same class is not nudged
     * twice.
     */
    public function nudgeUnpaid(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');
        $school = $this->school($request);
        $data = $request->validate([
            'class_arm_id' => ['required', 'exists:tenant.class_arms,id'],
            'term_id' => ['nullable', 'exists:tenant.terms,id'],
        ]);

        // Nudges for whichever term the class-status table was actually
        // showing at the time (the term switcher, user request
        // 2026-07-25), not necessarily the school's system wide current
        // term.
        $term = ! empty($data['term_id']) ? Term::find($data['term_id']) : $this->resolveActiveTerm($request);
        abort_unless($term, 404, 'There is no term to nudge for.');
        $arm = ClassArm::with('schoolClass', 'arm')->findOrFail($data['class_arm_id']);
        $label = $arm->label();

        $owed = FeeItem::where('term_id', $term->id)->active()->where('approval_status', FeeItem::APPROVAL_APPROVED)
            ->with('schoolClasses')->get()->filter(fn (FeeItem $f) => $f->appliesToClass($arm->school_class_id))->sum('amount');
        abort_if($owed <= 0, 404, 'This class has no fees set up for the current term.');

        $verifiedByStudent = Payment::where('status', Payment::STATUS_VERIFIED)
            ->selectRaw('student_id, sum(amount) as total')->groupBy('student_id')->pluck('total', 'student_id');

        $students = Student::active()->where('current_class_arm_id', $arm->id)->get();
        $owingStudentIds = $students->filter(fn (Student $s) => (float) ($verifiedByStudent[$s->id] ?? 0) < $owed)->pluck('id');

        $guardianUserIds = StudentGuardian::whereIn('student_id', $owingStudentIds)->whereNotNull('guardian_user_id')->pluck('guardian_user_id')->unique();

        foreach ($guardianUserIds as $userId) {
            Notification::create([
                'user_id' => $userId,
                'category' => 'payment_reminder',
                'type' => Notification::typeFor('payment_reminder'),
                'title' => 'Outstanding fees reminder',
                'body' => "This is a reminder that {$term->name} fees for {$label} are still outstanding. Please submit your payment when ready.",
            ]);
        }

        return back()->with('status', "Reminded {$guardianUserIds->count()} parent(s) with outstanding fees in {$label}.");
    }

    public function create(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'finance.create');
        $school = $this->school($request);
        $user = Auth::user();
        $familyStudentIds = app(FamilyScope::class)->studentIds($user, $school);

        $students = Student::active()
            ->when($familyStudentIds !== null, fn ($q) => $q->whereIn('id', $familyStudentIds))
            ->with('currentClassArm.schoolClass')
            ->orderBy('last_name')
            ->get();

        return view('school.finance.payments.create', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'students' => $students,
            // A fee item may only apply to certain classes (user request
            // 2026-07-25); eager loading schoolClasses lets the create
            // form's JS filter the picker to only what the chosen
            // student's own class is actually charged, without another
            // request per student.
            // approval_status excludes a fee item still awaiting approval,
            // or rejected outright (user request 2026-07-25: an approval
            // chain, off by default; every existing/approval-disabled fee
            // item is already 'approved', so this changes nothing for a
            // school that has not turned approval on).
            'feeItems' => FeeItem::active()->where('approval_status', FeeItem::APPROVAL_APPROVED)
                ->with('term', 'installments', 'schoolClasses')->orderByDesc('created_at')->get(),
            'bankAccounts' => BankAccount::active()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request, ImageProcessingService $images): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.create');
        $school = $this->school($request);
        $user = Auth::user();
        $familyStudentIds = app(FamilyScope::class)->studentIds($user, $school);

        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', 'exists:tenant.students,id'],
            'fee_item_id' => ['nullable', 'exists:tenant.fee_items,id'],
            'fee_item_installment_id' => ['nullable', 'exists:tenant.fee_item_installments,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:120'],
            'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.config('skulos.upload_limits.payment_evidence_kb')],
        ]);

        // A crafted installment id belonging to a different fee item than
        // the one submitted is silently dropped rather than acted on, the
        // same "never trust cross-field ids" convention the rest of this
        // controller already follows for student ids.
        if (! empty($data['fee_item_installment_id'])) {
            $belongs = \App\Models\Tenant\FeeItemInstallment::where('id', $data['fee_item_installment_id'])
                ->where('fee_item_id', $data['fee_item_id'] ?? null)->exists();
            if (! $belongs) {
                $data['fee_item_installment_id'] = null;
            }
        }

        // A parent/student may only ever submit for their own linked
        // children/self, any crafted student id outside that set is
        // dropped rather than acted on, never silently redirected onto
        // someone else's record.
        $studentIds = collect($data['student_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        if ($familyStudentIds !== null) {
            $studentIds = $studentIds->filter(fn ($id) => $familyStudentIds->contains($id))->values();
        }
        abort_if($studentIds->isEmpty(), 404);

        // Financial evidence never goes on the public disk (audit finding
        // M-2, 2026-07-17): a bank receipt/proof-of-payment is served only
        // through the authenticated evidence() action below, which re-runs
        // the same finance.view + FamilyScope check every other action on
        // this controller already enforces.
        $file = $request->file('evidence');
        $evidencePath = $file->getClientOriginalExtension() === 'pdf'
            ? $file->storeAs("payments/{$school->slug}", Str::random(24).'.pdf', 'local')
            : $images->toWebp($file, "payments/{$school->slug}", disk: 'local');

        // For parents with more than one child (user request 2026-07-26):
        // one evidence upload can cover several children at once. Each
        // child still gets its own Payment row (so verification, balances,
        // and records all stay per-student as before), sharing an even
        // split of the total amount and tagged with the same
        // payment_group_id so the records screen can show they came from
        // one submission. The split's rounding remainder lands on the last
        // student so the shares always sum back to the entered total.
        $count = $studentIds->count();
        $share = round(((float) $data['amount']) / $count, 2);
        $groupId = $count > 1 ? (string) Str::uuid() : null;

        // If the record create fails after the file already uploaded
        // successfully, the file is deleted rather than left orphaned on
        // disk indefinitely, unreferenced by any record (audit finding
        // M-6, 2026-07-17).
        try {
            $payments = collect();
            foreach ($studentIds as $index => $studentId) {
                $amount = $index === $count - 1
                    ? round((float) $data['amount'] - $share * ($count - 1), 2)
                    : $share;

                // status is intentionally not passed here: it is not
                // mass-assignable (see Payment::$fillable), so a new
                // payment always starts at the migration's own
                // status='pending' column default, the single source of
                // truth for that.
                $payments->push(Payment::create([
                    'student_id' => $studentId,
                    'fee_item_id' => $data['fee_item_id'] ?? null,
                    'fee_item_installment_id' => $data['fee_item_installment_id'] ?? null,
                    'amount' => $amount,
                    'method' => 'bank_transfer',
                    'bank_name' => $data['bank_name'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'evidence_path' => $evidencePath,
                    'payment_group_id' => $groupId,
                    'submitted_by_user_id' => $user->id,
                ]));
            }
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($evidencePath);
            throw $e;
        }

        foreach ($payments as $payment) {
            $this->notifyFinanceStaff($school, $payment, 'payment_submitted', 'New payment submitted', "{$payment->student->fullName()}'s payment of ₦".number_format((float) $payment->amount, 2).' is awaiting verification.');
        }

        $status = $count > 1
            ? "Payment submitted for {$count} children. You will be notified once each is verified."
            : 'Payment submitted. You will be notified once it is verified.';

        return redirect()->route('school.finance.payments.index', ['school' => $school->slug])
            ->with('status', $status);
    }

    public function show(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'finance.view');
        $school = $this->school($request);
        $user = Auth::user();
        $familyStudentIds = app(FamilyScope::class)->studentIds($user, $school);

        $payment = Payment::with('student.currentClassArm.schoolClass', 'feeItem', 'installment')->findOrFail($request->route('paymentId'));

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
