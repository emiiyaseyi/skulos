<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TrialRequestInfoRequestMail;
use App\Mail\TrialRequestStatusMail;
use App\Models\School;
use App\Models\TrialRequest;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Support\TemporaryPassword;

/**
 * Platform admin reviews "Start a Trial" leads (user request 2026-07-09):
 * approve or reject, with a note that goes to the applicant by email
 * (setup status, demo, and training arrangements on approval). Approving
 * now provisions the school automatically (its own request, 2026-07-09:
 * "the school account should be setup automatically") rather than only
 * sending an email — the applicant becomes that school's administrator
 * and is emailed their sign in details along with the admin's note.
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

    public function approve(Request $request, TrialRequest $trialRequest, TenantManager $tenants, Rbac $rbac): RedirectResponse
    {
        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
            // Optional: a trial can be set up on a specific plan right
            // away rather than being left with none, which is what
            // happened before -- a brand new school fell back to the
            // default plan for its features and showed "No plan set" on
            // the Billing screen until someone noticed.
            'plan_id' => ['nullable', 'exists:plans,id'],
        ]);

        $slug = self::uniqueSlug($trialRequest->school_name, $trialRequest->preferred_slug);
        $temporaryPassword = TemporaryPassword::generate();

        $school = School::create([
            'name' => $trialRequest->school_name,
            'slug' => $slug,
            'email' => $trialRequest->email,
            'phone' => $trialRequest->phone,
        ]);
        $tenants->provision($school);

        $admin = User::firstOrNew(['email' => Str::lower($trialRequest->email)]);
        $admin->name = $admin->name ?: $trialRequest->contact_name;
        $admin->password = Hash::make($temporaryPassword);
        $admin->email_verified_at = $admin->email_verified_at ?: now();
        // Force a change at first sign in (2026-08-16, reported live:
        // trial-approved schools kept the emailed temporary password
        // indefinitely). SchoolAdminController::attachAdmin() has always
        // done this for a manually created school; this path was written
        // separately and never picked it up, so the one route a real
        // customer actually arrives through was the one route that left
        // a password sitting in an inbox valid forever.
        $admin->must_change_password = true;
        $admin->save();

        // Bug found 2026-07-16 (user report: a platform broadcast to
        // "school owners" reached 0 schools): this used to assign the
        // shared TEMPLATE role's id directly (school_id null), never
        // cloning it into this school first, so anything that queries by
        // a school-scoped role id (like a platform broadcast's recipient
        // resolution) never matched this owner at all. Also meant this
        // school's owner could never actually benefit from customizing
        // their own "School Owner" role's permissions, since they were
        // never really pointing at their own school's copy of it.
        $ownerRoleId = $rbac->cloneTemplateRole($school, 'school-owner')->id;
        $school->users()->syncWithoutDetaching([$admin->id => ['role_id' => $ownerRoleId, 'status' => 'active']]);

        // Assign the chosen plan the same way BillingController does,
        // module states included. Setting plan_id alone is not enough:
        // module_states is what actually gates access, and a school with
        // no explicit rows silently falls back to the default plan's
        // features (the gotcha documented in CLAUDE.md).
        if (! empty($data['plan_id'])) {
            $plan = \App\Models\Plan::find($data['plan_id']);
            if ($plan && $plan->is_purchasable) {
                $school->update(['plan_id' => $plan->id]);
                foreach (\App\Models\Module::optional() as $code => $meta) {
                    $rbac->setModuleState($school, $code, in_array($code, $plan->modules ?? [], true)
                        ? \App\Models\Module::STATE_ENABLED
                        : \App\Models\Module::STATE_DISABLED);
                }
            }
        }

        $trialRequest->update([
            'status' => TrialRequest::STATUS_APPROVED,
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'school_id' => $school->id,
        ]);

        Mail::to($trialRequest->email)->send(new TrialRequestStatusMail($trialRequest, [
            'portal_url' => $school->portalUrl('/login'),
            'email' => $admin->email,
            'password' => $temporaryPassword,
        ]));

        return back()->with('status',
            "{$trialRequest->school_name} approved and set up at {$school->slug}.".config('app.domain').". {$trialRequest->contact_name} has been emailed their sign in details.");
    }

    public function reject(Request $request, TrialRequest $trialRequest): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:2000']]);

        $trialRequest->update([
            'status' => TrialRequest::STATUS_REJECTED,
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        Mail::to($trialRequest->email)->send(new TrialRequestStatusMail($trialRequest));

        return back()->with('status', "{$trialRequest->school_name} declined. {$trialRequest->contact_name} has been emailed.");
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

    /**
     * A school's subdomain. No separator (2026-08-16, direct
     * instruction): Str::slug()'s default turns "Bright Star Academy"
     * into "bright-star-academy", and a hyphenated subdomain is harder
     * to say aloud, easier to mistype, and looks worse on a letterhead
     * than "brightstaracademy". The numeric suffix a clash falls back
     * to is appended for the same reason without one.
     *
     * A school that wants something different is not stuck with this:
     * the approval email says so, and a platform admin can change the
     * subdomain afterwards from the school's own Manage page.
     */
    public static function uniqueSlug(string $name, ?string $preferred = null): string
    {
        $base = Str::slug($preferred ?: $name, '') ?: 'school';
        $slug = $base;
        $suffix = 1;

        while (School::where('slug', $slug)->exists()) {
            $slug = $base.(++$suffix);
        }

        return $slug;
    }
}
