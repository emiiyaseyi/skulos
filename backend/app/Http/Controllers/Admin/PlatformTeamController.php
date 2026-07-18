<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auditing\Recorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Platform team management (user request 2026-07-18: "so many features
 * that we have in the school end are missing under the platform owner
 * end... i need full control of the whole thing"). Until now the only
 * way to grant platform-admin access at all was the
 * `platform:make-super-admin` console command; there was no screen to
 * see who has it, add another platform staff member, or revoke it.
 *
 * is_super_admin is a single flat privilege bit today (no platform-side
 * roles/permissions exist, unlike the school-side RBAC system), so every
 * account here has full platform control — this screen manages WHO has
 * that, not different tiers of it. Every grant/revoke is written to the
 * platform audit trail, and is_super_admin is only ever set via
 * forceFill, never mass-assigned (User::$fillable deliberately excludes
 * it), the same rule the console command already followed.
 */
class PlatformTeamController extends Controller
{
    public function index(): \Illuminate\View\View
    {
        return view('admin.team', [
            'admins' => User::where('is_super_admin', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, Recorder $recorder): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'password' => Hash::make($data['password']),
            'email_verified_at' => now(),
        ]);
        $user->forceFill(['is_super_admin' => true, 'must_change_password' => true])->save();

        $recorder->record('platform.team.granted', $user, new: [
            'granted_by_user_id' => Auth::id(),
            'granted_to_email' => $user->email,
        ]);

        return back()->with('status', "{$user->email} now has full platform admin access. They'll be asked to change their password on first sign in.");
    }

    /**
     * Revoke platform admin access. Refuses to revoke your own account
     * (self-lockout, the only way this route is ever reachable with just
     * one admin left, since reaching it at all requires being a super
     * admin yourself) and, as defense in depth against two admins
     * concurrently revoking each other, refuses to let a revoke bring the
     * count to zero. Matches the same "can't lock everyone out" caution
     * the school-side admin-access toggle follows.
     */
    public function revoke(Request $request, User $user, Recorder $recorder): RedirectResponse
    {
        abort_unless($user->is_super_admin, 404);
        abort_if($user->id === Auth::id(), 422, 'You cannot revoke your own platform admin access.');

        $remaining = User::where('is_super_admin', true)->where('id', '!=', $user->id)->count();
        abort_if($remaining === 0, 422, 'At least one platform admin must always remain.');

        $user->forceFill(['is_super_admin' => false])->save();

        $recorder->record('platform.team.revoked', $user, new: [
            'revoked_by_user_id' => Auth::id(),
            'revoked_email' => $user->email,
        ]);

        return back()->with('status', "{$user->email}'s platform admin access has been revoked.");
    }
}
