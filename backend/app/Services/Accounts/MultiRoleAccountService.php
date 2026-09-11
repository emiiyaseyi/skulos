<?php

namespace App\Services\Accounts;

use App\Models\Role;
use App\Models\School;
use App\Models\Tenant\Staff;
use App\Models\User;
use App\Models\UserAdditionalRole;

/**
 * Granting/revoking an additional role, beyond a user's primary one
 * (2026-09-11, multi-role accounts). Named to sit next to
 * AccountProvisioningService, same App\Services\Accounts domain: both
 * are about managing who can sign in as what.
 */
class MultiRoleAccountService
{
    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function grantRole(School $school, User $user, Role $role, User $grantedBy): UserAdditionalRole
    {
        $primaryRoleId = $school->users()->where('users.id', $user->id)->value('school_user.role_id');

        abort_if((int) $primaryRoleId === $role->id, 422, 'That is already their primary role.');

        $grant = UserAdditionalRole::firstOrNew([
            'school_id' => $school->id, 'user_id' => $user->id, 'role_id' => $role->id,
        ]);
        if (! $grant->exists) {
            $grant->granted_by_user_id = $grantedBy->id;
            $grant->granted_at = now();
            $grant->save();
        }

        // A teaching-scoped role means nothing without the same Staff
        // record a real teacher has behind it (class/subject assignment,
        // TeachingAssignment rows) — per the user's own confirmed
        // answer, granting the role auto-creates one if missing, rather
        // than leaving the grant looking like it "worked" with no way to
        // actually assign the person to a class.
        if (in_array($role->slug, config('skulos.teacher_scoped_roles', []), true)) {
            $this->ensureStaffRecord($school, $user);
        }

        return $grant;
    }

    public function revokeRole(School $school, User $user, Role $role): void
    {
        // No special handling needed for "what if this is their currently
        // active role": User::roleIn() re-validates against
        // user_additional_roles on every cache miss, so the very next
        // request simply finds the session value no longer held and
        // falls back to the primary role automatically, no error.
        UserAdditionalRole::where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->where('role_id', $role->id)
            ->delete();
    }

    protected function ensureStaffRecord(School $school, User $user): Staff
    {
        $existing = Staff::where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }

        $parts = explode(' ', trim($user->name), 2);
        $nextSeq = Staff::withTrashed()->count() + 1;

        return Staff::create([
            'staff_id' => sprintf('STF/%04d', $nextSeq),
            'first_name' => $parts[0] !== '' ? $parts[0] : $user->name,
            'last_name' => $parts[1] ?? '',
            'email' => str_ends_with((string) $user->email, '.skulos.local') ? null : $user->email,
            'phone' => $user->phone,
            'position' => 'Staff',
            'status' => Staff::STATUS_ACTIVE,
            'user_id' => $user->id,
        ]);
    }
}
