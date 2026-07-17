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

        $file = $request->file('evidence');
        $evidencePath = $file->getClientOriginalExtension() === 'pdf'
            ? $file->storeAs("payments/{$school->slug}", Str::random(24).'.pdf', 'public')
            : $images->toWebp($file, "payments/{$school->slug}");

        $payment = Payment::create([
            'student_id' => $data['student_id'],
            'fee_item_id' => $data['fee_item_id'] ?? null,
            'amount' => $data['amount'],
            'method' => 'bank_transfer',
            'bank_name' => $data['bank_name'] ?? null,
            'reference' => $data['reference'] ?? null,
            'evidence_path' => $evidencePath,
            'status' => Payment::STATUS_PENDING,
            'submitted_by_user_id' => $user->id,
        ]);

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

    public function verify(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->decide($request, Payment::STATUS_VERIFIED);
    }

    public function reject(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->decide($request, Payment::STATUS_REJECTED);
    }

    protected function decide(Request $request, string $status): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');
        $school = $this->school($request);
        $user = Auth::user();
        $payment = Payment::with('student')->findOrFail($request->route('paymentId'));

        if ($payment->status !== Payment::STATUS_PENDING) {
            return back()->with('error', 'This payment has already been '.$payment->status.'.');
        }

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        $payment->update([
            'status' => $status,
            'verified_by_user_id' => $user->id,
            'verified_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);

        $verb = $status === Payment::STATUS_VERIFIED ? 'verified' : 'rejected';
        $category = $status === Payment::STATUS_VERIFIED ? 'payment_verified' : 'payment_rejected';
        Notification::create([
            'user_id' => $payment->submitted_by_user_id,
            'category' => $category,
            'type' => Notification::typeFor($category),
            'title' => "Payment {$verb}",
            'body' => "{$payment->student->fullName()}'s payment of ₦".number_format((float) $payment->amount, 2)." was {$verb}.".($data['notes'] ?? '' ? ' Note: '.$data['notes'] : ''),
        ]);

        return back()->with('status', "Payment marked as {$verb}.");
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
