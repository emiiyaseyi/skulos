<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

        $trials = TrialRequest::query()
            ->when(in_array($status, ['pending', 'approved', 'rejected'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->get();

        return view('admin.trial-requests', [
            'trials' => $trials,
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
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:2000']]);

        $slug = $this->uniqueSlug($trialRequest->school_name);
        $temporaryPassword = Str::password(12);

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

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'school';
        $slug = $base;
        $suffix = 1;

        while (School::where('slug', $slug)->exists()) {
            $slug = "{$base}-".(++$suffix);
        }

        return $slug;
    }
}
