<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\UserAdditionalRole;
use App\Services\Auditing\Recorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Switches which of a user's held roles is currently active at this
 * school (2026-09-11, multi-role accounts -- "a switch account button at
 * the top to switch from one user role to another"). Writes a session
 * value User::roleIn() reads and re-validates on every cache miss; see
 * that method's own docblock for the full mechanism.
 */
class RoleSwitchController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    public function switch(Request $request, Recorder $recorder): RedirectResponse
    {
        $school = $this->school($request);
        $user = Auth::user();

        $data = $request->validate(['role_id' => ['required', 'integer']]);
        $roleId = (int) $data['role_id'];

        $primaryRoleId = (int) $school->users()->where('users.id', $user->id)->value('school_user.role_id');
        $holdsAsAdditional = UserAdditionalRole::where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->exists();

        // Deny-by-default: the requested role must be either this user's
        // own primary role at this school, or one explicitly granted to
        // them in user_additional_roles -- never anything else, no
        // matter what other role rows exist at this school.
        abort_unless($roleId === $primaryRoleId || $holdsAsAdditional, 403, 'You do not hold that role.');

        $request->session()->put('active_role_'.$school->id, $roleId);
        $recorder->record('multi_role.switch', $user, new: ['school_id' => $school->id, 'role_id' => $roleId]);

        return redirect()->route('school.dashboard', ['school' => $school->slug]);
    }
}
