<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TrialRequestInfoRequestMail;
use App\Mail\TrialRequestStatusMail;
use App\Models\School;
use App\Models\TrialRequest;
use App\Services\Schools\TrialProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Platform admin oversight of "Start a Trial" leads. Since 2026-11-06
 * (direct instruction: "instant access for setup with manual review from
 * the backend"), a submission on the public form already provisions
 * itself instantly via TrialProvisioningService -- this screen is no
 * longer the gate in front of that. "Approve" survives as a manual retry
 * for the rare request whose instant provisioning failed (still pending,
 * no school_id), and "Reject" becomes an after-the-fact intervention: for
 * a request that is already a live school, it suspends that school rather
 * than pretending no account was ever created.
 */
class TrialRequestAdminController extends Controller
{
    public function index(Request $request): \Illuminate\View\View
    {
        $status = $request->query('status', 'pending');

        // Paginated (audit follow-up 2026-07-30): trial requests are
        // append-only and never purged, so this grows for the life of the
        // platform, faster than the Schools table it feeds.
        $trials = TrialRequest::query()
            ->when(in_array($status, ['pending', 'approved', 'rejected'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.trial-requests', [
            'trials' => $trials,
            // Only plans that can actually be sold: an approving admin
            // should not be able to put a brand new school onto a plan
            // marked "coming soon".
            'plans' => \App\Models\Plan::where('is_purchasable', true)->orderBy('price_per_student_per_term')->get(),
            'status' => $status,
            'counts' => [
                'pending' => TrialRequest::pending()->count(),
                'approved' => TrialRequest::where('status', TrialRequest::STATUS_APPROVED)->count(),
                'rejected' => TrialRequest::where('status', TrialRequest::STATUS_REJECTED)->count(),
            ],
        ]);
    }

    public function approve(Request $request, TrialRequest $trialRequest, TrialProvisioningService $provisioning): RedirectResponse
    {
        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
            'plan_id' => ['nullable', 'exists:plans,id'],
        ]);

        // This is now only ever reached for a request still pending --
        // the public form provisions on submit. Kept as a manual retry
        // for the rare case that instant provisioning threw (a tenant DB
        // or mail failure) and left the request sitting pending with no
        // school_id.
        if ($trialRequest->school_id) {
            return back()->with('status', "{$trialRequest->school_name} already has a live account.");
        }

        $result = $provisioning->provision($trialRequest, Auth::id(), $data['review_note'] ?? null, $data['plan_id'] ?? null);

        return back()->with('status',
            "{$trialRequest->school_name} approved and set up at {$result['school']->slug}.".config('app.domain').". {$trialRequest->contact_name} has been emailed their sign in details.");
    }

    /**
     * A real, actionable "targeted campaign" lever (2026-11-06, direct
     * instruction): every trial request already carries real contact
     * info (name, email, phone, country, student count, when they
     * signed up) -- this hands it to the admin as a plain CSV, filtered
     * by status and date range, for genuine outreach (a WhatsApp/email
     * follow-up to everyone still pending from a given window, every
     * school from a given country, etc.) rather than guessing at who to
     * reach out to.
     */
    public function export(Request $request): \Illuminate\Http\Response
    {
        $status = $request->query('status', 'all');
        $from = $request->query('from');
        $to = $request->query('to');

        $trials = TrialRequest::query()
            ->when(in_array($status, ['pending', 'approved', 'rejected'], true), fn ($q) => $q->where('status', $status))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->get();

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Date', 'School', 'Contact', 'Email', 'Phone', 'Country', 'Students', 'Status', 'Web address']);
        foreach ($trials as $trial) {
            fputcsv($out, [
                $trial->created_at?->toDateString(),
                $trial->school_name,
                $trial->contact_name,
                $trial->email,
                $trial->phone,
                $trial->country,
                $trial->student_count,
                $trial->status,
                $trial->preferred_slug,
            ]);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="trial-requests-'.$status.'-'.now()->toDateString().'.csv"',
        ]);
    }

    public function reject(Request $request, TrialRequest $trialRequest): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:2000']]);

        // Since trials now provision instantly on submission (2026-11-06),
        // a request reaching this action usually already has a live
        // school behind it -- "reject" here means revoking access after
        // the fact, not quietly pretending no account was ever created.
        // A still-pending request (instant provisioning never ran or
        // failed) keeps the old, simpler behaviour: declined, no school.
        if ($trialRequest->school_id) {
            School::find($trialRequest->school_id)?->update(['status' => School::STATUS_SUSPENDED]);
        }

        $trialRequest->update([
            'status' => TrialRequest::STATUS_REJECTED,
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        Mail::to($trialRequest->email)->send(new TrialRequestStatusMail($trialRequest));

        return back()->with('status', $trialRequest->school_id
            ? "{$trialRequest->school_name}'s account has been suspended. {$trialRequest->contact_name} has been emailed."
            : "{$trialRequest->school_name} declined. {$trialRequest->contact_name} has been emailed.");
    }

    /**
     * Ask a requester for more detail before a decision is made
     * (2026-08-19). Deliberately does not change the request's own
     * status -- asking a question is not a decision, and the request
     * should stay visible in the pending queue until the admin actually
     * approves or rejects it.
     */
    public function requestInfo(Request $request, TrialRequest $trialRequest): RedirectResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'message.required' => 'Say what you need from them before sending.',
        ]);

        Mail::to($trialRequest->email)->send(new TrialRequestInfoRequestMail($trialRequest, $data['message']));

        return back()->with('status', "Question sent to {$trialRequest->contact_name} at {$trialRequest->school_name}.");
    }

    /**
     * The manual "checking in on your setup" nudge (2026-08-19), for an
     * approved trial whose school has already had its onboarding letter
     * for a while. Only meaningful once a School actually exists.
     */
    public function nudge(TrialRequest $trialRequest, \App\Services\Schools\SchoolFollowUpService $service): RedirectResponse
    {
        abort_unless($trialRequest->school_id, 404);

        $count = $service->nudge($trialRequest->school);

        return back()->with('status', $count > 0
            ? "Nudge sent to {$count} admin(s) at {$trialRequest->school_name}."
            : "{$trialRequest->school_name} has no active admin to nudge yet.");
    }

    /**
     * Bulk decline across several pending trials at once, same list-screen
     * bulk-action pattern used elsewhere. Approve is deliberately NOT
     * offered in bulk: it provisions a whole new tenant database per
     * school (real disk I/O and migrations, several seconds each), and
     * running several of those synchronously in one request risks the
     * same PHP max_execution_time timeout already hit once on this
     * codebase (2026-07-20, the /login stall). Declining is cheap (a
     * status update and an email) and safe to batch.
     */
    public function bulkReject(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'trial_ids' => ['required', 'array', 'min:1'],
            'trial_ids.*' => ['exists:trial_requests,id'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $trials = TrialRequest::whereIn('id', $data['trial_ids'])->where('status', TrialRequest::STATUS_PENDING)->get();

        foreach ($trials as $trial) {
            $trial->update([
                'status' => TrialRequest::STATUS_REJECTED,
                'review_note' => $data['review_note'] ?? null,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
            ]);

            Mail::to($trial->email)->send(new TrialRequestStatusMail($trial));
        }

        return back()->with('status', "{$trials->count()} trial request(s) declined and emailed.");
    }
}
