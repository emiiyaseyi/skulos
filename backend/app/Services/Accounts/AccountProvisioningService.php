<?php

namespace App\Services\Accounts;

use App\Mail\AccountCreatedMail;
use App\Mail\PasswordResetByAdminMail;
use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Creating a login for a staff member or a parent/guardian (Staff and
 * Parent Portal, user request 2026-07-12: both roles' dashboards already
 * existed, RoleDashboard::parent()/School Administrator etc, but there was
 * no screen anywhere to actually create the account in the first place —
 * only the demo seeder could). Mirrors the platform admin's own school
 * administrator credential pattern (Admin\SchoolAdminController::
 * storeAdmin/toggleAdminAccess/resetPassword): a random temporary
 * password, emailed via the existing branded transactional layout, never
 * typed by the person creating the account on the other person's behalf.
 */
class AccountProvisioningService
{
    /**
     * @return array{user: User, password: string} $password is only ever
     *   returned for the caller to flash/display once; it is never stored.
     */
    public function provision(School $school, string $name, string $email, string $roleSlug): array
    {
        $email = Str::lower($email);
        $password = Str::password(12);

        $user = User::firstOrNew(['email' => $email]);
        $isNewLogin = ! $user->exists || ! $school->users()->whereKey($user->id)->exists();
        $user->name = $user->name ?: $name;
        $user->password = Hash::make($password);
        $user->email_verified_at = $user->email_verified_at ?: now();
        $user->save();

        // cloneTemplateRole(), not a raw reference to the shared template
        // row: every other role attachment in the product goes through
        // this so a school's own permission customizations are isolated
        // to that school, not shared platform wide.
        $role = app(Rbac::class)->cloneTemplateRole($school, $roleSlug);
        $school->users()->syncWithoutDetaching([$user->id => ['role_id' => $role->id, 'status' => 'active']]);

        if ($isNewLogin) {
            Mail::to($user->email)->send(new AccountCreatedMail($user, $school, $password, $role->name));
        }

        return ['user' => $user, 'password' => $password];
    }

    public function resetPassword(School $school, User $user): string
    {
        $password = Str::password(12);
        $user->update(['password' => Hash::make($password)]);

        Mail::to($user->email)->send(new PasswordResetByAdminMail($user, $school, $password));

        return $password;
    }

    /** Suspends this user's access to this one school only, without touching any other school or the account itself. */
    public function revokeAccess(School $school, User $user): void
    {
        $school->users()->updateExistingPivot($user->id, ['status' => 'suspended']);
    }

    public function restoreAccess(School $school, User $user): void
    {
        $school->users()->updateExistingPivot($user->id, ['status' => 'active']);
    }
}
