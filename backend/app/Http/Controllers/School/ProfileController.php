<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ProfileChangeRequest;
use App\Models\User;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
 * Password changes are deliberately left out of this gate and this
 * pass entirely: no self-service "change my password" flow exists yet
 * anywhere in the app (only an admin resetting someone else's), and
 * gating a user's own password behind another person's approval would
 * be a real security anti-pattern (a compromised account should be
 * fixable immediately, not left waiting), so it is left as a distinct,
 * separate concern rather than folded in here.
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

        return back()->with('status', "Your changes have been sent to {$school->name}'s admin for approval, and will apply once approved.");
    }

    public function approvals(Request $request): \Illuminate\View\View
    {
        abort_unless($this->canSelfApply($request), 403, 'You do not have permission for this.');
        $school = $this->school($request);

        $requests = ProfileChangeRequest::where('status', ProfileChangeRequest::STATUS_PENDING)
            ->with('applicant')
            ->orderBy('created_at')
            ->get();

        return view('school.profile.approvals', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'requests' => $requests,
        ]);
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

        return back()->with('status', 'The profile change has been rejected.');
    }
}
