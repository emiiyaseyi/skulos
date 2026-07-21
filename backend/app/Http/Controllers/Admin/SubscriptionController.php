<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPayment;
use App\Services\Billing\SubscriptionBankService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Platform subscription billing (user request 2026-07-17): every
 * school's subscription due date/amount at a glance, the same bank
 * transfer + evidence + verification flow the Foundation Finance slice
 * gave schools for their own parents, mirrored here for a school paying
 * SKULOS itself. Reminder/extension/demand are real, auditable actions
 * on a school's own next_billing_date/status, never a fabricated
 * countdown.
 */
class SubscriptionController extends Controller
{
    /**
     * Every provisioned school's tenant database is briefly connected to
     * here to read its live learner count. Previously a single missing or
     * corrupt tenant DB threw uncaught, 500ing this page for every school,
     * not just the affected one, and the query had no pagination so this
     * scaled linearly with the number of schools on the platform (audit
     * finding H-2, 2026-07-17). Each school's snapshot is now isolated: a
     * failure is logged and shown as "unavailable" for that row only.
     */
    public function index(Request $request, SubscriptionService $subs): \Illuminate\View\View
    {
        $schools = School::whereNotNull('provisioned_at')->with('plan')->orderBy('name')->paginate(25);

        $schools->through(function (School $school) use ($subs) {
            try {
                $snapshot = $subs->billingSnapshot($school);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Subscription billing snapshot failed for school {$school->slug}: ".$e->getMessage());
                $snapshot = ['learners' => null, 'amountDue' => null, 'daysUntilDue' => null];
            }

            return [
                'school' => $school,
                'learners' => $snapshot['learners'],
                'amountDue' => $snapshot['amountDue'],
                'daysUntilDue' => $snapshot['daysUntilDue'],
                'unavailable' => $snapshot['learners'] === null,
            ];
        });

        return view('admin.subscriptions.index', [
            'rows' => $schools,
            'pendingPayments' => SubscriptionPayment::where('status', SubscriptionPayment::STATUS_PENDING)->with('school')->count(),
        ]);
    }

    public function show(School $school, SubscriptionService $subs): \Illuminate\View\View
    {
        try {
            $snapshot = $subs->billingSnapshot($school);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Subscription billing snapshot failed for school {$school->slug}: ".$e->getMessage());
            $snapshot = ['learners' => null, 'amountDue' => null, 'daysUntilDue' => null];
        }

        return view('admin.subscriptions.show', [
            'school' => $school,
            'learners' => $snapshot['learners'],
            'amountDue' => $snapshot['amountDue'],
            'daysUntilDue' => $snapshot['daysUntilDue'],
            'payments' => SubscriptionPayment::where('school_id', $school->id)->orderByDesc('created_at')->get(),
            'events' => SubscriptionEvent::where('school_id', $school->id)->with('actionedBy')->orderByDesc('created_at')->take(20)->get(),
            'verifiedUnapplied' => $subs->verifiedUnappliedTotal($school),
        ]);
    }

    public function bankEdit(SubscriptionBankService $bank): \Illuminate\View\View
    {
        return view('admin.subscriptions.bank', ['settings' => $bank->effective()]);
    }

    public function bankUpdate(Request $request, SubscriptionBankService $bank): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:100'],
            'account_name' => ['required', 'string', 'max:150'],
            'account_number' => ['required', 'string', 'max:30'],
        ]);

        $bank->saveSettings($data);

        return back()->with('status', 'Subscription bank details saved.');
    }

    /**
     * Streams evidence from the private 'local' disk (audit finding M-2,
     * 2026-07-17); already super_admin-gated by the enclosing route
     * group's middleware.
     */
    public function evidence(SubscriptionPayment $payment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($payment->evidence_path, 404);

        return Storage::disk('local')->response($payment->evidence_path);
    }

    public function verify(SubscriptionPayment $payment, SubscriptionService $subs): \Illuminate\Http\RedirectResponse
    {
        return $this->decide($payment, SubscriptionPayment::STATUS_VERIFIED, $subs);
    }

    public function reject(SubscriptionPayment $payment, SubscriptionService $subs): \Illuminate\Http\RedirectResponse
    {
        return $this->decide($payment, SubscriptionPayment::STATUS_REJECTED, $subs);
    }

    /**
     * The read-then-write shape this replaced let two admins acting on the
     * same payment within the same window both pass the "still pending"
     * check and both write, double-firing notifySchoolLeadership() and
     * leaving the payment's final status a race (audit finding C-1,
     * 2026-07-17). The update itself is now the atomic guard: only a row
     * that is still pending at the moment of the UPDATE can transition, and
     * only the request whose UPDATE actually changed a row proceeds to
     * notify.
     */
    protected function decide(SubscriptionPayment $payment, string $status, SubscriptionService $subs): \Illuminate\Http\RedirectResponse
    {
        if (! $this->applyDecision($payment, $status, $subs)) {
            return back()->with('error', 'This payment has already been processed.');
        }

        $verb = $status === SubscriptionPayment::STATUS_VERIFIED ? 'verified' : 'rejected';

        return back()->with('status', "Payment marked as {$verb}.");
    }

    /** The atomic update plus notification, shared by decide() and bulkDecide(). Returns whether this call was the one that actually transitioned the payment. */
    protected function applyDecision(SubscriptionPayment $payment, string $status, SubscriptionService $subs): bool
    {
        $updated = SubscriptionPayment::where('id', $payment->id)
            ->where('status', SubscriptionPayment::STATUS_PENDING)
            ->update([
                'status' => $status,
                'verified_by_user_id' => Auth::id(),
                'verified_at' => now(),
            ]);

        if ($updated === 0) {
            return false;
        }

        $payment->refresh();

        $verb = $status === SubscriptionPayment::STATUS_VERIFIED ? 'verified' : 'rejected';
        $category = $status === SubscriptionPayment::STATUS_VERIFIED ? 'subscription_payment_verified' : 'subscription_payment_rejected';
        $subs->notifySchoolLeadership($payment->school, $category, "Subscription payment {$verb}", 'Your payment of ₦'.number_format((float) $payment->amount, 2)." was {$verb}.");

        return true;
    }

    /** Bulk verify/reject across several pending payments on the same school, same list-screen bulk-action pattern as SchoolAdminController::bulkAction(). */
    public function bulkDecide(Request $request, SubscriptionService $subs): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'payment_ids' => ['required', 'array', 'min:1'],
            'payment_ids.*' => ['exists:subscription_payments,id'],
            'action' => ['required', 'in:verify,reject'],
        ]);

        $status = $data['action'] === 'verify' ? SubscriptionPayment::STATUS_VERIFIED : SubscriptionPayment::STATUS_REJECTED;
        $payments = SubscriptionPayment::whereIn('id', $data['payment_ids'])->get();

        $processed = 0;
        foreach ($payments as $payment) {
            if ($this->applyDecision($payment, $status, $subs)) {
                $processed++;
            }
        }

        $verb = $status === SubscriptionPayment::STATUS_VERIFIED ? 'verified' : 'rejected';

        return back()->with('status', "{$processed} payment(s) {$verb}.");
    }

    public function remind(School $school, SubscriptionService $subs): \Illuminate\Http\RedirectResponse
    {
        $amountDue = $subs->amountDue($school);
        $subs->notifySchoolLeadership(
            $school,
            'subscription_reminder',
            'Subscription payment reminder',
            'A reminder from SKULOS: your subscription payment'.($amountDue !== null ? ' of ₦'.number_format($amountDue, 2) : '').' is due'.($school->next_billing_date ? ' on '.$school->next_billing_date->format('d M Y') : '').'.'
        );

        SubscriptionEvent::create([
            'school_id' => $school->id, 'type' => SubscriptionEvent::TYPE_REMINDER,
            'actioned_by_user_id' => Auth::id(),
        ]);

        return back()->with('status', "Reminder sent to {$school->name}.");
    }

    public function extend(Request $request, School $school, SubscriptionService $subs): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:365'], 'note' => ['nullable', 'string', 'max:500']]);

        $base = $school->next_billing_date && $school->next_billing_date->isFuture() ? $school->next_billing_date : now();
        $newDate = $base->copy()->addDays($data['days']);

        // The school's due-date change and its audit-trail event are
        // platform-DB writes and must land together or not at all: a
        // failure between them previously left the due date moved with
        // no record of who extended it or why (audit finding M-4,
        // 2026-07-17). The tenant-DB notification below stays outside
        // this transaction, it's a separate database connection.
        DB::transaction(function () use ($school, $newDate, $data) {
            $school->update(['next_billing_date' => $newDate]);
            SubscriptionEvent::create([
                'school_id' => $school->id, 'type' => SubscriptionEvent::TYPE_EXTENSION,
                'note' => $data['note'] ?? null, 'extended_to' => $newDate, 'actioned_by_user_id' => Auth::id(),
            ]);
        });

        $subs->notifySchoolLeadership($school, 'subscription_extended', 'Subscription extended', "Your subscription due date has been moved to {$newDate->format('d M Y')}.".($data['note'] ?? '' ? ' Note: '.$data['note'] : ''));

        return back()->with('status', "{$school->name}'s subscription extended to {$newDate->format('d M Y')}.");
    }

    /**
     * Confirm & Renew (user request 2026-07-19: a school's amount due
     * changed mid cycle after a plan/learner-count change, was paid
     * across two separate verified payments, "there should be a way for
     * the platform owner to confirm and renew the school plan"). Marks
     * every currently verified-but-unapplied payment as applied (so a
     * later renewal never counts it again), moves next_billing_date
     * forward, and — since a school confirming payment is by definition
     * settling up — reinstates access if it had been locked out for
     * billing, all in the one action, rather than requiring a separate
     * "Restore access" click right after.
     */
    public function renew(Request $request, School $school, SubscriptionService $subs): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'next_billing_date' => ['required', 'date', 'after:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $newDate = \Illuminate\Support\Carbon::parse($data['next_billing_date']);
        $appliedTotal = $subs->verifiedUnappliedTotal($school);

        DB::transaction(function () use ($school, $newDate, $data, $appliedTotal) {
            \App\Models\SubscriptionPayment::where('school_id', $school->id)
                ->where('status', SubscriptionPayment::STATUS_VERIFIED)
                ->whereNull('applied_to_renewal_at')
                ->update(['applied_to_renewal_at' => now()]);

            $updates = ['next_billing_date' => $newDate];
            if ($school->status === School::STATUS_SUSPENDED && $school->suspension_reason === School::SUSPENSION_REASON_BILLING) {
                $updates['status'] = School::STATUS_ACTIVE;
                $updates['suspension_reason'] = null;
            }
            $school->update($updates);

            SubscriptionEvent::create([
                'school_id' => $school->id, 'type' => SubscriptionEvent::TYPE_RENEWED,
                'note' => trim('₦'.number_format($appliedTotal, 2).' confirmed. '.($data['note'] ?? '')),
                'extended_to' => $newDate, 'actioned_by_user_id' => Auth::id(),
            ]);
        });

        $subs->notifySchoolLeadership($school, 'subscription_renewed', 'Subscription renewed', "Thank you, your payment of ₦".number_format($appliedTotal, 2)." has been confirmed and your subscription renewed. Next due {$newDate->format('d M Y')}.");

        return back()->with('status', "{$school->name}'s subscription confirmed and renewed to {$newDate->format('d M Y')}.");
    }

    /**
     * Full lockout (user directive 2026-07-17: "demand payment should
     * also flag/restrict the school... full lockout until resolved").
     * Reuses the existing School::STATUS_SUSPENDED + ResolveTenant 403,
     * tagged with suspension_reason so that 403 can actually explain why
     * and what to do, distinct from the pre-existing, unrelated manual
     * "Suspend" action.
     */
    public function demand(Request $request, School $school, SubscriptionService $subs): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        DB::transaction(function () use ($school, $data) {
            $school->update(['status' => School::STATUS_SUSPENDED, 'suspension_reason' => School::SUSPENSION_REASON_BILLING]);
            SubscriptionEvent::create([
                'school_id' => $school->id, 'type' => SubscriptionEvent::TYPE_DEMAND,
                'note' => $data['note'] ?? null, 'actioned_by_user_id' => Auth::id(),
            ]);
        });

        $subs->notifySchoolLeadership($school, 'subscription_locked', 'Access locked: subscription overdue', 'SKULOS access for your school has been locked due to an overdue subscription payment. Please contact SKULOS to resolve this.'.($data['note'] ?? '' ? ' '.$data['note'] : ''));

        return back()->with('status', "{$school->name} has been locked out pending payment.");
    }

    public function reinstate(School $school, SubscriptionService $subs): \Illuminate\Http\RedirectResponse
    {
        abort_unless($school->suspension_reason === School::SUSPENSION_REASON_BILLING, 404);

        DB::transaction(function () use ($school) {
            $school->update(['status' => School::STATUS_ACTIVE, 'suspension_reason' => null]);
            SubscriptionEvent::create([
                'school_id' => $school->id, 'type' => SubscriptionEvent::TYPE_REINSTATED,
                'actioned_by_user_id' => Auth::id(),
            ]);
        });

        $subs->notifySchoolLeadership($school, 'subscription_reinstated', 'Access restored', 'SKULOS access for your school has been restored.');

        return back()->with('status', "{$school->name}'s access has been restored.");
    }
}
