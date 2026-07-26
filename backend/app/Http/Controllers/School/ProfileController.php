<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\PasswordResetRequest;
use App\Models\Tenant\ProfileChangeRequest;
use App\Models\User;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * A school member's own profile: view/edit name, email, phone (user
 * request: "a user profile page (click name to view/edit)"). A member
 * trusted with staff.update (an owner or admin) has their own edits
 * apply immediately, everyone else's are held as a ProfileChangeRequest
 * for an admin to approve first ("gated behind a school-admin approval
 * workflow before any change takes effect") — reusing staff.update
 * rather than inventing a new permission, since anyone already trusted
 * to edit a *colleague's* staff record is reasonably trusted to edit
 * their own identity fields without a second sign-off.
 *
 * Password self-service (user directive 2026-07-13: "let's add a new
 * feature to all users profile, where they can change their own
 * password if they want too"): unlike name/email/phone, a password
 * change made while already authenticated applies immediately, no
 * approval, a compromised account needs to be fixable right away, not
 * left waiting on someone else's sign-off. This same action also backs
 * the mandatory first-login gate (RequirePasswordChange middleware).
 * Forgot-password (not authenticated) is a different, explicitly
 * admin-approved flow, see PasswordResetRequest and requestReset()/
 * approveReset()/rejectReset() below.
 */
class ProfileController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function canSelfApply(Request $request): bool
    {
        return app(Rbac::class)->check(Auth::user(), $this->school($request), 'staff.update');
    }

    public function edit(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        $user = Auth::user();

        return view('school.profile.edit', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'profileUser' => $user,
            'pending' => ProfileChangeRequest::where('user_id', $user->id)
                ->where('status', ProfileChangeRequest::STATUS_PENDING)
                ->latest()
                ->first(),
            'canSelfApply' => $this->canSelfApply($request),
        ]);
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        $user = Auth::user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40', Rule::unique('users', 'phone')->ignore($user->id)],
        ]);

        $original = ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone];
        $changed = array_diff_assoc($data, $original);

        if (empty($changed)) {
            return back()->with('status', 'Nothing changed.');
        }

        if ($this->canSelfApply($request)) {
            $user->update($changed);

            return back()->with('status', 'Your profile has been updated.');
        }

        ProfileChangeRequest::where('user_id', $user->id)
            ->where('status', ProfileChangeRequest::STATUS_PENDING)
            ->update(['status' => ProfileChangeRequest::STATUS_REJECTED, 'notes' => 'Superseded by a newer request.']);

        ProfileChangeRequest::create([
            'user_id' => $user->id,
            'original' => array_intersect_key($original, $changed),
            'requested' => $changed,
            'status' => ProfileChangeRequest::STATUS_PENDING,
        ]);

        $this->notifyApprovers($school, 'Profile change awaiting your review', "{$user->name} asked to change their profile details. Review it under Users > Profile approvals.");

        return back()->with('status', "Your changes have been sent to {$school->name}'s admin for approval, and will apply once approved.");
    }

    /**
     * Real bug found live, 2026-07-24 (user report: "a user made a
     * profile change that requires admin review and approval, admin
     * did not see it as a notification which from where admin can
     * access the request, review, approve or reject with comment"): a
     * ProfileChangeRequest/PasswordResetRequest was created, but no one
     * with staff.update (the same permission the approvals page itself
     * is gated on) was ever actually told one existed, only findable by
     * proactively opening the page. Every active member whose role
     * grants staff.update gets an in app notification pointed at the
     * approvals page.
     */
    protected function notifyApprovers(School $school, string $title, string $body): void
    {
        $roleIds = \App\Models\Role::where('school_id', $school->id)
            ->whereHas('permissions', fn ($q) => $q->where('code', 'staff.update'))
            ->pluck('id');

        $approverUserIds = $school->users()
            ->wherePivot('status', 'active')
            ->whereIn('school_user.role_id', $roleIds)
            ->pluck('users.id');

        foreach ($approverUserIds as $userId) {
            \App\Models\Tenant\Notification::create([
                'user_id' => $userId,
                'title' => $title,
                'body' => $body,
                'category' => 'profile_approval',
                'type' => \App\Models\Tenant\Notification::typeFor('profile_approval'),
            ]);
        }
    }

    /**
     * The change-password form. Doubles as the mandatory first-login
     * gate (RequirePasswordChange redirects here) and a voluntary
     * "change my password" action reachable from the profile page,
     * same form and action either way.
     */
    public function changePasswordForm(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);

        return view('school.profile.change-password', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'forced' => (bool) Auth::user()->must_change_password,
        ]);
    }

    public function updatePassword(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'That is not your current password.']);
        }

        $user->password = Hash::make($data['password']);
        $user->must_change_password = false;
        $user->save();

        $school = $this->school($request);
        $role = $user->is_super_admin ? null : $user->roleIn($school);
        $routeName = LandingPageController::landingRouteFor($role);

        return redirect()->route($routeName, ['school' => $school->slug])->with('status', 'Your password has been changed.');
    }

    /**
     * Forgot password (user directive 2026-07-13: "a forgot password
     * option to the login page which users can reset their password to
     * a new password they want whcih the school admin will have to
     * approve"). Not authenticated, so the new password is collected
     * immediately, hashed right away, and held pending — the OLD
     * password keeps working until an admin approves the request,
     * unlike a typical email-token reset which grants access
     * immediately on its own.
     */
    public function forgotPasswordForm(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);

        return view('school.profile.forgot-password', [
            'school' => $school,
            'accent' => $school->branding()['color'],
        ]);
    }

    public function requestPasswordReset(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);

        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $identifier = trim($data['identifier']);
        $user = str_contains($identifier, '@')
            ? User::where('email', \Illuminate\Support\Str::lower($identifier))->first()
            : (\App\Models\Tenant\Student::where('admission_number', $identifier)->first()?->user
                ?? User::where('phone', preg_replace('/\D+/', '', $identifier))->first());

        // Never confirm whether an identifier matched a real account,
        // same message either way, so this cannot be used to enumerate
        // who has an account at this school.
        $generic = 'If that matches an account at this school, your request has been sent to the admin for approval.';

        if ($user && $user->isMemberOf($school)) {
            PasswordResetRequest::where('user_id', $user->id)
                ->where('status', PasswordResetRequest::STATUS_PENDING)
                ->update(['status' => PasswordResetRequest::STATUS_REJECTED, 'notes' => 'Superseded by a newer request.']);

            PasswordResetRequest::create([
                'user_id' => $user->id,
                'password_hash' => Hash::make($data['password']),
                'status' => PasswordResetRequest::STATUS_PENDING,
            ]);

            $this->notifyApprovers($school, 'Password reset awaiting your review', "{$user->name} asked to reset their password. Review it under Users > Profile approvals.");
        }

        return redirect()->route('school.login', ['school' => $school->slug])->with('status', $generic);
    }

    /**
     * The second forgot-password option (user directive 2026-07-13: "the
     * user make use of password reset link and get it as a mail"):
     * proving access to the inbox is the identity check, so this path
     * applies immediately once the link is used, no admin approval,
     * unlike requestPasswordReset() above. Reuses Laravel's own
     * `password_reset_tokens` table directly rather than the full
     * Password broker/Notification pipeline, since that pipeline
     * assumes a single non-tenant `password.reset` route and this app's
     * reset link has to land back on the right school subdomain.
     */
    public function sendResetLink(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        $data = $request->validate(['email' => ['required', 'email']]);

        $email = \Illuminate\Support\Str::lower($data['email']);
        $user = User::where('email', $email)->first();
        $generic = 'If that email matches an account at this school, a reset link has been sent.';

        if ($user && $user->isMemberOf($school)) {
            $token = \Illuminate\Support\Str::random(64);
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $resetUrl = route('school.password.reset', ['school' => $school->slug, 'token' => $token, 'email' => $email]);
            \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\PasswordResetLinkMail($user, $school, $resetUrl));
        }

        return redirect()->route('school.login', ['school' => $school->slug])->with('status', $generic);
    }

    public function resetViaTokenForm(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);

        return view('school.profile.reset-token', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'token' => $request->query('token'),
            'email' => $request->query('email'),
        ]);
    }

    public function resetViaToken(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $email = \Illuminate\Support\Str::lower($data['email']);
        $record = \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', $email)->first();

        $valid = $record
            && Hash::check($data['token'], $record->token)
            && now()->diffInMinutes($record->created_at) <= 60;

        if (! $valid) {
            return back()->withErrors(['token' => 'This reset link is invalid or has expired, request a new one.']);
        }

        $user = User::where('email', $email)->first();
        abort_unless($user && $user->isMemberOf($school), 404);

        $user->password = Hash::make($data['password']);
        $user->must_change_password = false;
        $user->save();

        \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', $email)->delete();

        return redirect()->route('school.login', ['school' => $school->slug])->with('status', 'Your password has been reset, you can sign in now.');
    }

    public function approvals(Request $request): \Illuminate\View\View
    {
        abort_unless($this->canSelfApply($request), 403, 'You do not have permission for this.');
        $school = $this->school($request);

        $requests = ProfileChangeRequest::where('status', ProfileChangeRequest::STATUS_PENDING)
            ->with('applicant')
            ->orderBy('created_at')
            ->get();

        $passwordRequests = PasswordResetRequest::where('status', PasswordResetRequest::STATUS_PENDING)
            ->with('applicant')
            ->orderBy('created_at')
            ->get();

        return view('school.profile.approvals', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'requests' => $requests,
            'passwordRequests' => $passwordRequests,
        ]);
    }

    public function approveReset(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($this->canSelfApply($request), 403, 'You do not have permission for this.');
        $resetRequest = PasswordResetRequest::where('status', PasswordResetRequest::STATUS_PENDING)
            ->findOrFail($request->route('requestId'));

        $applicant = User::findOrFail($resetRequest->user_id);
        // Set the column directly, bypassing Eloquent's 'hashed' cast:
        // password_hash was already hashed at request time, re-assigning
        // it through $user->password = ... would hash it a second time.
        \Illuminate\Support\Facades\DB::table('users')->where('id', $applicant->id)->update([
            'password' => $resetRequest->password_hash,
            'must_change_password' => false,
        ]);

        $resetRequest->update([
            'status' => PasswordResetRequest::STATUS_APPROVED,
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        // Tells the user their own chosen password is now live (user
        // directive 2026-07-13: "will show approved on the user end once
        // the admin approve"); they are not authenticated to see an
        // in-app notification, email is the only channel available.
        if ($applicant->email) {
            \Illuminate\Support\Facades\Mail::to($applicant->email)
                ->send(new \App\Mail\PasswordResetApprovedMail($applicant, $this->school($request)));
        }

        return back()->with('status', "{$applicant->name}'s password reset has been approved and applied.");
    }

    public function rejectReset(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($this->canSelfApply($request), 403, 'You do not have permission for this.');
        $resetRequest = PasswordResetRequest::where('status', PasswordResetRequest::STATUS_PENDING)
            ->findOrFail($request->route('requestId'));

        $data = $request->validate(['notes' => ['required', 'string', 'max:500']]);

        $resetRequest->update([
            'status' => PasswordResetRequest::STATUS_REJECTED,
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
            'notes' => $data['notes'],
        ]);

        return back()->with('status', 'The password reset request has been rejected.');
    }

    public function approve(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($this->canSelfApply($request), 403, 'You do not have permission for this.');
        $changeRequest = ProfileChangeRequest::where('status', ProfileChangeRequest::STATUS_PENDING)
            ->findOrFail($request->route('requestId'));

        $applicant = User::findOrFail($changeRequest->user_id);
        $data = $changeRequest->requested;

        if (isset($data['email']) || isset($data['phone'])) {
            $conflict = User::where('id', '!=', $applicant->id)
                ->where(function ($q) use ($data) {
                    if (isset($data['email'])) {
                        $q->orWhere('email', $data['email']);
                    }
                    if (isset($data['phone'])) {
                        $q->orWhere('phone', $data['phone']);
                    }
                })
                ->exists();
            if ($conflict) {
                return back()->with('error', 'That email or phone is now used by another account, ask them to submit the change again.');
            }
        }

        $applicant->update($data);
        $changeRequest->update([
            'status' => ProfileChangeRequest::STATUS_APPROVED,
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
        ]);
        $this->notifyApplicantOfDecision($applicant, 'profile_change_approved', 'Your profile change was approved', 'The change you asked for has been approved and is now live on your profile.');

        return back()->with('status', "{$applicant->name}'s profile change has been approved and applied.");
    }

    public function reject(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($this->canSelfApply($request), 403, 'You do not have permission for this.');
        $changeRequest = ProfileChangeRequest::where('status', ProfileChangeRequest::STATUS_PENDING)
            ->findOrFail($request->route('requestId'));

        $data = $request->validate(['notes' => ['required', 'string', 'max:500']]);

        $changeRequest->update([
            'status' => ProfileChangeRequest::STATUS_REJECTED,
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
            'notes' => $data['notes'],
        ]);
        if ($applicant = User::find($changeRequest->user_id)) {
            $this->notifyApplicantOfDecision($applicant, 'profile_change_rejected', 'Your profile change was rejected', "Your requested profile change was not approved: {$data['notes']}");
        }

        return back()->with('status', 'The profile change has been rejected.');
    }

    /**
     * The other side of notifyApprovers() above: tells the applicant
     * themselves once their own request is decided (user report
     * 2026-07-25, the same shape of gap as the approver-side one this
     * page's approvals notification already fixed: nothing told them a
     * decision had even been made, besides re-opening their own profile
     * and noticing the fields had quietly changed). In app only, unlike
     * the password reset flow's email: a profile-change applicant is by
     * definition already an authenticated member who can see an in app
     * notification, not someone locked out and reachable only by email.
     */
    protected function notifyApplicantOfDecision(User $applicant, string $category, string $title, string $body): void
    {
        \App\Models\Tenant\Notification::create([
            'user_id' => $applicant->id,
            'title' => $title,
            'body' => $body,
            'category' => $category,
            'type' => \App\Models\Tenant\Notification::typeFor($category),
        ]);
    }

    /**
     * Bulk version of approve(), same list-screen bulk-action pattern used
     * across the app. Mirrors approve()'s own email/phone conflict guard
     * per row, skipping (not failing the whole batch on) any request that
     * now conflicts with another account.
     */
    public function bulkApprove(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($this->canSelfApply($request), 403, 'You do not have permission for this.');
        $data = $request->validate([
            'request_ids' => ['required', 'array', 'min:1'],
            'request_ids.*' => ['exists:tenant.profile_change_requests,id'],
        ]);

        $changeRequests = ProfileChangeRequest::where('status', ProfileChangeRequest::STATUS_PENDING)
            ->whereIn('id', $data['request_ids'])->get();

        $approved = 0;
        $skipped = [];
        foreach ($changeRequests as $changeRequest) {
            $applicant = User::find($changeRequest->user_id);
            if (! $applicant) {
                continue;
            }
            $fields = $changeRequest->requested;

            if (isset($fields['email']) || isset($fields['phone'])) {
                $conflict = User::where('id', '!=', $applicant->id)
                    ->where(function ($q) use ($fields) {
                        if (isset($fields['email'])) {
                            $q->orWhere('email', $fields['email']);
                        }
                        if (isset($fields['phone'])) {
                            $q->orWhere('phone', $fields['phone']);
                        }
                    })
                    ->exists();
                if ($conflict) {
                    $skipped[] = $applicant->name;
                    continue;
                }
            }

            $applicant->update($fields);
            $changeRequest->update([
                'status' => ProfileChangeRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => Auth::id(),
                'reviewed_at' => now(),
            ]);
            $this->notifyApplicantOfDecision($applicant, 'profile_change_approved', 'Your profile change was approved', 'The change you asked for has been approved and is now live on your profile.');
            $approved++;
        }

        $message = $approved > 0 ? "{$approved} profile change(s) approved and applied." : 'No changes approved.';
        if ($skipped) {
            $message .= ' Skipped (email or phone now used elsewhere): '.implode(', ', $skipped).'.';
        }

        return back()->with($skipped ? 'error' : 'status', $message);
    }

    /** Bulk version of reject(), one shared reason applied to every selected request. */
    public function bulkReject(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($this->canSelfApply($request), 403, 'You do not have permission for this.');
        $data = $request->validate([
            'request_ids' => ['required', 'array', 'min:1'],
            'request_ids.*' => ['exists:tenant.profile_change_requests,id'],
            'notes' => ['required', 'string', 'max:500'],
        ]);

        $applicantUserIds = ProfileChangeRequest::where('status', ProfileChangeRequest::STATUS_PENDING)
            ->whereIn('id', $data['request_ids'])->pluck('user_id');

        $count = ProfileChangeRequest::where('status', ProfileChangeRequest::STATUS_PENDING)
            ->whereIn('id', $data['request_ids'])
            ->update([
                'status' => ProfileChangeRequest::STATUS_REJECTED,
                'reviewed_by_user_id' => Auth::id(),
                'reviewed_at' => now(),
                'notes' => $data['notes'],
            ]);

        foreach (User::whereIn('id', $applicantUserIds)->get() as $applicant) {
            $this->notifyApplicantOfDecision($applicant, 'profile_change_rejected', 'Your profile change was rejected', "Your requested profile change was not approved: {$data['notes']}");
        }

        return back()->with('status', "{$count} profile change(s) rejected.");
    }

    /** Bulk version of approveReset(), same "set the hash directly" step per row. */
    public function bulkApproveReset(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($this->canSelfApply($request), 403, 'You do not have permission for this.');
        $data = $request->validate([
            'request_ids' => ['required', 'array', 'min:1'],
            'request_ids.*' => ['exists:tenant.password_reset_requests,id'],
        ]);

        $resetRequests = PasswordResetRequest::where('status', PasswordResetRequest::STATUS_PENDING)
            ->whereIn('id', $data['request_ids'])->get();
        $school = $this->school($request);

        $approved = 0;
        foreach ($resetRequests as $resetRequest) {
            $applicant = User::find($resetRequest->user_id);
            if (! $applicant) {
                continue;
            }

            // Set the column directly, bypassing Eloquent's 'hashed' cast:
            // password_hash was already hashed at request time, re-assigning
            // it through $user->password = ... would hash it a second time.
            \Illuminate\Support\Facades\DB::table('users')->where('id', $applicant->id)->update([
                'password' => $resetRequest->password_hash,
                'must_change_password' => false,
            ]);

            $resetRequest->update([
                'status' => PasswordResetRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => Auth::id(),
                'reviewed_at' => now(),
            ]);

            if ($applicant->email) {
                \Illuminate\Support\Facades\Mail::to($applicant->email)
                    ->send(new \App\Mail\PasswordResetApprovedMail($applicant, $school));
            }
            $approved++;
        }

        return back()->with('status', "{$approved} password reset request(s) approved.");
    }

    /** Bulk version of rejectReset(), one shared reason applied to every selected request. */
    public function bulkRejectReset(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($this->canSelfApply($request), 403, 'You do not have permission for this.');
        $data = $request->validate([
            'request_ids' => ['required', 'array', 'min:1'],
            'request_ids.*' => ['exists:tenant.password_reset_requests,id'],
            'notes' => ['required', 'string', 'max:500'],
        ]);

        $count = PasswordResetRequest::where('status', PasswordResetRequest::STATUS_PENDING)
            ->whereIn('id', $data['request_ids'])
            ->update([
                'status' => PasswordResetRequest::STATUS_REJECTED,
                'reviewed_by_user_id' => Auth::id(),
                'reviewed_at' => now(),
                'notes' => $data['notes'],
            ]);

        return back()->with('status', "{$count} password reset request(s) rejected.");
    }
}
