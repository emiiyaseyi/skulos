<?php

namespace App\Services\Accounts;

use App\Mail\AccountCreatedMail;
use App\Mail\PasswordResetByAdminMail;
use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Support\TemporaryPassword;

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
    public function __construct(protected WelcomeEmailService $welcomeEmails)
    {
    }

    /**
     * @return array{user: User, password: string} $password is only ever
     *   returned for the caller to flash/display once; it is never stored.
     */
    public function provision(School $school, string $name, string $email, string $roleSlug): array
    {
        $email = Str::lower($email);
        $password = TemporaryPassword::generate();

        $user = User::firstOrNew(['email' => $email]);
        $isNewLogin = ! $user->exists || ! $school->users()->whereKey($user->id)->exists();
        $user->name = $user->name ?: $name;
        $user->password = Hash::make($password);
        $user->email_verified_at = $user->email_verified_at ?: now();
        // A system-generated password must be changed at first sign in
        // (user directive 2026-07-13), not mass assignable, set directly.
        $user->must_change_password = true;
        $user->save();

        // cloneTemplateRole(), not a raw reference to the shared template
        // row: every other role attachment in the product goes through
        // this so a school's own permission customizations are isolated
        // to that school, not shared platform wide.
        $role = app(Rbac::class)->cloneTemplateRole($school, $roleSlug);
        $school->users()->syncWithoutDetaching([$user->id => ['role_id' => $role->id, 'status' => 'active']]);

        if ($isNewLogin && ! $this->welcomeEmails->isPaused()) {
            $this->sendSafely(fn () => Mail::to($user->email)->send(new AccountCreatedMail($user, $school, $password, $role->name, $roleSlug)), $user->email);
        }

        return ['user' => $user, 'password' => $password];
    }

    /**
     * A transient SMTP failure must never block the account itself from
     * being created (user report 2026-09-11: several staff members were
     * silently left with no login at all, root-caused to exactly this --
     * every call site below sent its welcome/reset email inline, unguarded,
     * so one mail failure killed the whole request/loop iteration it was
     * part of, silently skipping every record still to come in the same
     * batch). The account and its role are already saved by the time this
     * runs; a failed notification is logged and swallowed, never re-thrown.
     */
    protected function sendSafely(\Closure $send, string $to): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning('Account notification email failed to send, the account itself was still created.', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Auto-provisions a login with a known, admin-set default password
     * (user request 2026-07-21: no manual "create login" step, a student
     * signs in with their admission number and a default password they
     * must change at first sign in). Deliberately not provision() above:
     * that method's random password only works because it is always
     * emailed somewhere, but a student has no guaranteed email or phone
     * to send anything to, and the whole point here is the password is
     * already known/told to them out of band, not a secret to deliver.
     * $identityEmail/$identityPhone is whichever real contact this person
     * actually has (a guardian always has at least one, per the school's
     * own parent_identifier requirement, but that could be phone only);
     * $syntheticEmail is stored (never contacted) whenever no real email
     * exists, since users.email is a NOT NULL platform column regardless
     * of whether a phone number is also on file.
     *
     * @return array{user: User, isNew: bool}
     */
    public function provisionAuto(
        School $school,
        string $name,
        ?string $identityEmail,
        ?string $identityPhone,
        string $roleSlug,
        string $defaultPassword,
        string $syntheticEmail
    ): array {
        $realEmail = $identityEmail ? Str::lower($identityEmail) : null;
        // Lookup prefers whichever real contact exists, so a person with
        // an account already (a guardian linked to a sibling, most
        // commonly) is found and reused rather than duplicated; the
        // synthetic email is the lookup key only as a last resort.
        $lookup = $realEmail ? ['email' => $realEmail] : ($identityPhone ? ['phone' => $identityPhone] : ['email' => $syntheticEmail]);

        $user = User::firstOrNew($lookup);
        $isNew = ! $user->exists;
        $user->name = $user->name ?: $name;
        $user->email = $user->email ?: ($realEmail ?: $syntheticEmail);
        if ($identityPhone) {
            $user->phone = $user->phone ?: $identityPhone;
        }
        if ($isNew) {
            $user->password = Hash::make($defaultPassword);
            $user->email_verified_at = $realEmail ? now() : null;
            $user->must_change_password = true;
        }
        $user->save();

        $role = app(Rbac::class)->cloneTemplateRole($school, $roleSlug);
        $school->users()->syncWithoutDetaching([$user->id => ['role_id' => $role->id, 'status' => 'active']]);

        // A welcome email only ever goes to a REAL email address (never
        // the synthetic placeholder above, that would just bounce), and
        // only for a genuinely new login, the same "only once" guard
        // provision() above already applies.
        if ($isNew && $realEmail && ! $this->welcomeEmails->isPaused()) {
            $this->sendSafely(fn () => Mail::to($realEmail)->send(new AccountCreatedMail($user, $school, $defaultPassword, $role->name, $roleSlug)), $realEmail);
        }

        return ['user' => $user, 'isNew' => $isNew];
    }

    public function resetPassword(School $school, User $user): string
    {
        $password = TemporaryPassword::generate();
        $user->password = Hash::make($password);
        $user->must_change_password = true;
        // Back to a known default again (user request 2026-09-11: "if it
        // has been changed, put password last change date and time"), so
        // an admin-issued reset correctly reads as "using the default
        // password" again, not still showing a stale change date from
        // before the reset.
        $user->password_changed_at = null;
        $user->save();

        $this->sendSafely(fn () => Mail::to($user->email)->send(new PasswordResetByAdminMail($user, $school, $password)), $user->email);

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
