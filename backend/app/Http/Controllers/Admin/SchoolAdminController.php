<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetByAdminMail;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\Auditing\Recorder;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\SchoolCloneService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Platform super admin: create and oversee schools (the Schools section,
 * distinct from the platform wide Dashboard/analytics in
 * PlatformDashboardController — user request 2026-07-09). Creating a
 * school provisions its dedicated database, seeds its calendar defaults,
 * and gives it a subdomain, all from this one place.
 */
class SchoolAdminController extends Controller
{
    /** The Schools section: create a school, and the full schools list. */
    public function schools(): \Illuminate\View\View
    {
        $schools = School::with('plan')->orderByDesc('created_at')->get();

        return view('admin.schools-index', [
            'schools' => $schools,
            'stats' => [
                'schools' => $schools->count(),
                'active' => $schools->where('status', School::STATUS_ACTIVE)->count(),
                'pending' => $schools->where('status', School::STATUS_PENDING)->count(),
            ],
        ]);
    }

    /**
     * A quick read only overview for the Schools list "View" action (user
     * request 2026-07-10): name, address, plan, next billing date, and
     * live counts pulled from the school's own tenant database. Kept
     * separate from the full "Manage" page so the list doesn't need to
     * navigate away just to see who/how many are in a school.
     */
    public function overview(School $school, TenantManager $tenants): \Illuminate\Http\JsonResponse
    {
        $counts = ['students' => 0, 'staff' => 0, 'parents' => 0, 'classes' => 0];

        if ($school->isProvisioned()) {
            $tenants->use($school);
            $counts = [
                'students' => \App\Models\Tenant\Student::count(),
                'staff' => \App\Models\Tenant\Staff::count(),
                'parents' => \App\Models\Tenant\StudentGuardian::whereNotNull('email')->distinct('email')->count('email'),
                'classes' => \App\Models\Tenant\ClassArm::count(),
            ];
            $tenants->forget();
        }

        return response()->json([
            'name' => $school->name,
            'address' => $school->address,
            'email' => $school->email,
            'phone' => $school->phone,
            'subdomain' => $school->slug.'.'.config('app.domain', 'localhost'),
            'status' => ucfirst($school->status),
            'plan' => $school->plan?->name ?? 'No plan set',
            'next_billing_date' => $school->next_billing_date?->toFormattedDateString(),
            'provisioned' => $school->isProvisioned(),
            'provisioned_at' => $school->provisioned_at?->toFormattedDateString(),
            'admins' => $school->users()->wherePivot('status', 'active')->count(),
            'counts' => $counts,
        ]);
    }

    /** Set or clear a school's next billing date, admin entered for now. */
    public function updateBillingDate(Request $request, School $school): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['next_billing_date' => ['nullable', 'date']]);
        $school->update(['next_billing_date' => $data['next_billing_date'] ?? null]);

        return back()->with('status', "Next billing date updated for {$school->name}.");
    }

    public function store(Request $request, TenantManager $tenants): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'alpha_dash', 'max:60', Rule::unique('schools', 'slug')],
            'phone' => ['nullable', 'string', 'max:40'],
            // The school's own administrator, who will sign in and run setup.
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:150'],
            'admin_password' => ['required', 'string', 'min:8', 'max:100'],
        ]);

        // Always normalized through Str::slug(), even when the admin typed
        // their own value: a raw typed slug only passed alpha_dash
        // validation (which allows uppercase), so "Training" would have
        // been stored verbatim, mismatching the always-lowercase subdomain
        // a browser sends and 404ing (found live 2026-07-12, school
        // "Training" was unreachable at training.<domain> because its
        // stored slug was "Training"). This also stops the tenant database
        // name (TenantManager::databaseNameFor(), which strips anything
        // outside [a-z0-9_] without lowercasing first) from silently
        // eating an uppercase letter, which is how that school's database
        // ended up literally named "raining" instead of "training".
        $slug = Str::slug($data['slug'] ?? $data['name']);
        if (School::where('slug', $slug)->exists()) {
            return back()->with('error', "A school with the address '{$slug}' already exists.")->withInput();
        }

        $school = School::create([
            'name' => $data['name'],
            'slug' => $slug,
            'email' => $data['admin_email'],
            'phone' => $data['phone'] ?? null,
        ]);

        $tenants->provision($school);

        // provision() already marks the school active/reachable at its
        // subdomain the moment it returns. If attaching the owner fails
        // afterwards, the school would otherwise be left silently active
        // with no owner able to sign in and run setup — a real gap found
        // by audit (finding L-2, 2026-07-17). Caught here and suspended
        // rather than left in that half-finished state.
        try {
            // Create (or reuse) the school administrator account and attach
            // it as this school's owner. This is who signs in on the
            // subdomain to run setup, not the platform super admin.
            $this->attachAdmin($school, $data['admin_name'], $data['admin_email'], $data['admin_password']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Provisioning {$school->slug} succeeded but attaching its owner failed: ".$e->getMessage());
            $school->forceFill([
                'status' => School::STATUS_SUSPENDED,
                'suspension_reason' => School::SUSPENSION_REASON_PROVISIONING_FAILED,
            ])->save();

            return back()->with('error', "{$school->name}'s tenant database was created, but attaching the administrator account failed. The school has been suspended pending manual review rather than left reachable with no owner. Try creating the owner again from the school's Admins page, then reinstate it.")->withInput();
        }

        return redirect()->route('admin.schools.index')->with('status',
            "{$school->name} is ready. The administrator (".Str::lower($data['admin_email']).") can now sign in at {$school->slug}.".config('app.domain')." to finish setup.");
    }

    protected function attachAdmin(School $school, string $name, string $email, string $password): User
    {
        $email = Str::lower($email);
        $admin = User::firstOrNew(['email' => $email]);
        $admin->name = $admin->name ?: $name;
        $admin->password = Hash::make($password);
        $admin->email_verified_at = $admin->email_verified_at ?: now();
        // Set by a platform admin on this owner's behalf, not chosen by
        // the owner themselves, must be changed at first sign in (user
        // directive 2026-07-13).
        $admin->must_change_password = true;
        $admin->save();

        // Bug found 2026-07-16 (user report: a platform broadcast to
        // "school owners" reached 0 schools): this used to assign the
        // shared TEMPLATE role's id directly (school_id null), never
        // cloning it into this school first, so anything that queries by
        // a school-scoped role id (like a platform broadcast's recipient
        // resolution, or the admins list on this school's own admin
        // page) never matched this owner at all.
        $ownerRole = app(Rbac::class)->cloneTemplateRole($school, 'school-owner')->id;
        $school->users()->syncWithoutDetaching([$admin->id => ['role_id' => $ownerRole, 'status' => 'active']]);

        return $admin;
    }

    /**
     * Clone a school's structure (sections/classes/subjects/grading/houses/
     * look and feel) onto a brand new school (user request 2026-07-09): a
     * fast way to stand up a similarly configured school without repeating
     * the setup. Student/staff/parent data is never copied.
     */
    public function cloneSchool(Request $request, School $school, TenantManager $tenants, SchoolCloneService $cloner): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'alpha_dash', 'max:60', Rule::unique('schools', 'slug')],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:150'],
            'admin_password' => ['required', 'string', 'min:8', 'max:100'],
        ]);

        // Always normalized through Str::slug(), even when the admin typed
        // their own value: a raw typed slug only passed alpha_dash
        // validation (which allows uppercase), so "Training" would have
        // been stored verbatim, mismatching the always-lowercase subdomain
        // a browser sends and 404ing (found live 2026-07-12, school
        // "Training" was unreachable at training.<domain> because its
        // stored slug was "Training"). This also stops the tenant database
        // name (TenantManager::databaseNameFor(), which strips anything
        // outside [a-z0-9_] without lowercasing first) from silently
        // eating an uppercase letter, which is how that school's database
        // ended up literally named "raining" instead of "training".
        $slug = Str::slug($data['slug'] ?? $data['name']);
        if (School::where('slug', $slug)->exists()) {
            return back()->with('error', "A school with the address '{$slug}' already exists.")->withInput();
        }

        $clone = School::create(['name' => $data['name'], 'slug' => $slug, 'email' => $data['admin_email']]);
        $tenants->provision($clone);

        // Same partial-failure exposure as store() above (audit finding
        // L-2, 2026-07-17): the clone is already active/reachable the
        // moment provision() returns, so a failure attaching its owner or
        // copying the source school's structure would otherwise leave it
        // silently active and half-finished.
        try {
            $this->attachAdmin($clone, $data['admin_name'], $data['admin_email'], $data['admin_password']);
            $cloner->clone($school, $clone);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Cloning {$school->slug} into {$clone->slug} failed after provisioning: ".$e->getMessage());
            $clone->forceFill([
                'status' => School::STATUS_SUSPENDED,
                'suspension_reason' => School::SUSPENSION_REASON_PROVISIONING_FAILED,
            ])->save();

            return back()->with('error', "{$clone->name}'s tenant database was created, but finishing the clone failed. The school has been suspended pending manual review.")->withInput();
        }

        return redirect()->route('admin.schools.index')->with('status',
            "{$clone->name} has been created as a clone of {$school->name}'s setup. Student, staff, and parent records were not copied.");
    }

    /** Suspend a school (temporary): access blocked, data kept. */
    public function suspend(School $school): RedirectResponse
    {
        $school->update(['status' => School::STATUS_SUSPENDED]);

        return back()->with('status', "{$school->name} has been suspended. Its users can no longer sign in until you resume it.");
    }

    /** Archive a school (long term): access blocked, data kept. */
    public function archive(School $school): RedirectResponse
    {
        $school->update(['status' => School::STATUS_ARCHIVED]);

        return back()->with('status', "{$school->name} has been archived. Resume it any time to restore access.");
    }

    /** Resume a suspended or archived school. */
    public function activate(School $school): RedirectResponse
    {
        $school->update(['status' => School::STATUS_ACTIVE]);

        return back()->with('status', "{$school->name} is active again. Its users can sign in.");
    }

    /**
     * Self-hosted licensing (user request 2026-07-18): mark a school as
     * running its own copy of the codebase and issue its license key,
     * the value entered into that installation's own license settings
     * so it can call home. See App\Services\Licensing\LicenseService.
     */
    public function enableSelfHosted(School $school, \App\Services\Licensing\LicenseService $licenses, Recorder $recorder): RedirectResponse
    {
        $licenses->issueFor($school);
        $recorder->record('school.license.enabled', $school, [], ['is_self_hosted' => true]);

        return back()->with('status', "Self-hosted licensing enabled for {$school->name}. Its license key is shown below, enter it into that installation's own license settings.");
    }

    /** Invalidate the current key and issue a new one, e.g. after a suspected leak. */
    public function regenerateLicense(School $school, \App\Services\Licensing\LicenseService $licenses, Recorder $recorder): RedirectResponse
    {
        abort_unless($school->is_self_hosted, 404);
        $licenses->regenerate($school);
        $recorder->record('school.license.regenerated', $school);

        return back()->with('status', "A new license key has been issued for {$school->name}. The old key no longer works.");
    }

    /** Stop treating a school as self-hosted; its next check-in fails and its own grace period then takes over. */
    public function revokeSelfHosted(School $school, \App\Services\Licensing\LicenseService $licenses, Recorder $recorder): RedirectResponse
    {
        $licenses->revoke($school);
        $recorder->record('school.license.revoked', $school);

        return back()->with('status', "Self-hosted licensing revoked for {$school->name}.");
    }

    /** Remove a school (soft delete): hidden from the platform, recoverable. */
    public function destroy(School $school): RedirectResponse
    {
        $name = $school->name;
        $school->delete();

        return redirect()->route('admin.schools.index')
            ->with('status', "{$name} has been removed and moved to the archive. Its database is retained and it can be restored at any time.");
    }

    /**
     * Bulk suspend/archive/resume/delete (2026-07-19 UI audit: the
     * platform's busiest admin list screen was the one list screen in
     * the app with no bulk action support, the standing per-list-screen
     * rule this project otherwise applies everywhere). Same standalone
     * actions this controller already exposes one row at a time, applied
     * to every ticked school in one request.
     */
    public function bulkAction(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'school_ids' => ['required', 'array', 'min:1'],
            'school_ids.*' => ['exists:schools,id'],
            'action' => ['required', 'in:suspend,archive,activate,delete'],
        ]);

        $schools = School::whereIn('id', $data['school_ids'])->get();

        if ($data['action'] === 'delete') {
            $count = $schools->count();
            School::whereIn('id', $data['school_ids'])->delete();

            return back()->with('status', "{$count} school(s) removed and moved to the archive. Their data is retained and each can be restored at any time.");
        }

        $statusMap = [
            'suspend' => School::STATUS_SUSPENDED,
            'archive' => School::STATUS_ARCHIVED,
            'activate' => School::STATUS_ACTIVE,
        ];
        $targetStatus = $statusMap[$data['action']];
        $changed = 0;
        foreach ($schools as $school) {
            if ($school->status !== $targetStatus) {
                $school->update(['status' => $targetStatus]);
                $changed++;
            }
        }

        $verb = ['suspend' => 'suspended', 'archive' => 'archived', 'activate' => 'resumed'][$data['action']];

        return back()->with('status', $changed > 0 ? "{$changed} school(s) {$verb}." : 'No changes to make, the selected schools were already in that state.');
    }

    /** Deleted schools, still recoverable (user request 2026-07-09). */
    public function trashed(): \Illuminate\View\View
    {
        $schools = School::onlyTrashed()->orderByDesc('deleted_at')->get();

        return view('admin.trashed', ['schools' => $schools]);
    }

    /** Bring a deleted school back onto the active schools list. */
    public function restore(string $schoolId): RedirectResponse
    {
        $school = School::onlyTrashed()->findOrFail($schoolId);
        $school->restore();

        return back()->with('status', "{$school->name} has been restored.");
    }

    /** Bulk version of restore(), same list-screen bulk-action pattern as bulkAction() above. */
    public function bulkRestore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'school_ids' => ['required', 'array', 'min:1'],
            'school_ids.*' => ['exists:schools,id'],
        ]);

        $schools = School::onlyTrashed()->whereIn('id', $data['school_ids'])->get();
        foreach ($schools as $school) {
            $school->restore();
        }

        return back()->with('status', "{$schools->count()} school(s) restored.");
    }

    public function show(School $school): \Illuminate\View\View
    {
        // school-scoped, not the shared template (2026-07-16 fix): a
        // school's own cloned "School Owner" role, not id=1 shared by
        // every school, otherwise this list is wrong in both directions
        // depending on which bug-era a given owner's account was created
        // under.
        $ownerRoleId = Role::where('school_id', $school->id)->where('slug', 'school-owner')->value('id');

        $admins = $school->users()
            ->wherePivot('role_id', $ownerRoleId)
            ->withPivot(['status'])
            ->orderBy('name')
            ->get();

        $backups = \App\Models\Backup::where('school_id', $school->id)->orderByDesc('created_at')->get();

        $optionalModules = \App\Models\Module::optional();
        $rbac = app(\App\Services\Rbac\Rbac::class);
        $moduleStates = collect($optionalModules)->keys()
            ->mapWithKeys(fn ($code) => [$code => $rbac->moduleState($school, $code)])->all();
        $waivers = \App\Models\BillingWaiver::where('school_id', $school->id)->orderByDesc('created_at')->get();
        $schoolAddOns = \App\Models\SchoolAddOn::where('school_id', $school->id)->orderByDesc('created_at')->get();

        return view('admin.school', [
            'school' => $school, 'admins' => $admins, 'backups' => $backups,
            'optionalModules' => $optionalModules, 'moduleStates' => $moduleStates, 'waivers' => $waivers,
            'addOnCatalog' => config('skulos.add_on_catalog'), 'schoolAddOns' => $schoolAddOns,
        ]);
    }

    /**
     * Reset a school administrator's password (user request 2026-07-09:
     * the platform owner needs a way to help a locked out school admin
     * without knowing their old password). Generates a new one and shows
     * it once in the flash message; the admin should change it after
     * signing in.
     */
    public function resetAdminPassword(School $school, User $user): RedirectResponse
    {
        abort_unless($school->users()->whereKey($user->id)->exists(), 404);

        $newPassword = Str::password(12);
        $user->update(['password' => Hash::make($newPassword)]);

        Mail::to($user->email)->send(new PasswordResetByAdminMail($user, $school, $newPassword));

        return back()->with('status',
            "Password for {$user->email} has been reset to: {$newPassword} and emailed to them. Share it securely if they need it sooner.");
    }

    /**
     * Create another school-owner level administrator for this school, or
     * reuse an existing platform user by email and attach them as one.
     */
    public function storeAdmin(Request $request, School $school): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ]);

        $email = Str::lower($data['email']);
        $admin = User::firstOrNew(['email' => $email]);
        $admin->name = $admin->name ?: $data['name'];
        $admin->password = Hash::make($data['password']);
        $admin->email_verified_at = $admin->email_verified_at ?: now();
        $admin->save();

        $ownerRoleId = app(Rbac::class)->cloneTemplateRole($school, 'school-owner')->id;
        $school->users()->syncWithoutDetaching([$admin->id => ['role_id' => $ownerRoleId, 'status' => 'active']]);

        return back()->with('status', "{$email} can now sign in as an administrator of {$school->name}.");
    }

    /**
     * Grant or revoke one administrator's access to this school, without
     * touching the school's own status or any other member.
     */
    public function toggleAdminAccess(School $school, User $user): RedirectResponse
    {
        abort_unless($school->users()->whereKey($user->id)->exists(), 404);

        $current = $school->users()->whereKey($user->id)->first()->pivot->status;
        $next = $current === 'active' ? 'suspended' : 'active';
        $school->users()->updateExistingPivot($user->id, ['status' => $next]);

        return back()->with('status', $next === 'active'
            ? "{$user->email} can sign in to {$school->name} again."
            : "{$user->email}'s access to {$school->name} has been revoked.");
    }

    /** Bulk revoke/restore/reset password across several of this school's administrators at once, same list-screen bulk-action pattern used elsewhere. */
    public function bulkAdminAction(Request $request, School $school): RedirectResponse
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['exists:users,id'],
            'action' => ['required', 'in:revoke,restore,reset-password'],
        ]);

        $members = $school->users()->whereIn('users.id', $data['user_ids'])->withPivot(['status'])->get();

        if ($data['action'] === 'reset-password') {
            foreach ($members as $admin) {
                $newPassword = Str::password(12);
                $admin->update(['password' => Hash::make($newPassword)]);
                Mail::to($admin->email)->send(new PasswordResetByAdminMail($admin, $school, $newPassword));
            }

            return back()->with('status', "{$members->count()} administrator(s) had their password reset and emailed to them.");
        }

        $targetStatus = $data['action'] === 'revoke' ? 'suspended' : 'active';
        $changed = 0;
        foreach ($members as $admin) {
            if ($admin->pivot->status !== $targetStatus) {
                $school->users()->updateExistingPivot($admin->id, ['status' => $targetStatus]);
                $changed++;
            }
        }

        $verb = $targetStatus === 'active' ? 'restored' : 'revoked';

        return back()->with('status', $changed > 0 ? "Access {$verb} for {$changed} administrator(s)." : 'No changes to make, they were already in that state.');
    }

    /**
     * Support access (user request 2026-07-09: "how to support a school
     * with any issue, do we create a backdoor or not"). Deliberately NOT a
     * backdoor: this signs the platform admin in AS the member using that
     * member's own permissions (no elevation), every start/end is written
     * to the audit trail with who/when, and a persistent banner is shown
     * the whole time so it can never be silently forgotten. It replaces
     * needing to reset a password just to see what a user sees.
     */
    public function impersonate(Request $request, School $school, User $user, Recorder $recorder): RedirectResponse
    {
        abort_unless($school->users()->whereKey($user->id)->wherePivot('status', 'active')->exists(), 404);

        $admin = Auth::user();
        $request->session()->put('impersonator_id', $admin->id);
        Auth::login($user);
        $request->session()->regenerate();

        $recorder->record('support.impersonate.start', $user, new: [
            'impersonator_id' => $admin->id,
            'impersonator_email' => $admin->email,
            'school_id' => $school->id,
        ]);

        return redirect()->route('school.dashboard', ['school' => $school->slug])
            ->with('status', "You are now viewing {$school->name} as {$user->name} for support. End this from the banner at the top when you are done.");
    }

    /** Return to the platform admin's own session. */
    public function endImpersonation(Request $request, Recorder $recorder): RedirectResponse
    {
        $impersonatorId = $request->session()->get('impersonator_id');
        abort_unless($impersonatorId, 404);

        $viewedAs = Auth::user();
        $admin = User::findOrFail($impersonatorId);

        $request->session()->forget('impersonator_id');
        Auth::login($admin);
        $request->session()->regenerate();

        $recorder->record('support.impersonate.end', $viewedAs, new: [
            'impersonator_id' => $admin->id,
            'impersonator_email' => $admin->email,
        ]);

        return redirect()->route('admin.dashboard')->with('status', 'You are back in your own account.');
    }
}
