<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetByAdminMail;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\Auditing\Recorder;
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
        $schools = School::orderByDesc('created_at')->get();

        return view('admin.schools-index', [
            'schools' => $schools,
            'stats' => [
                'schools' => $schools->count(),
                'active' => $schools->where('status', School::STATUS_ACTIVE)->count(),
                'pending' => $schools->where('status', School::STATUS_PENDING)->count(),
            ],
        ]);
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

        $slug = $data['slug'] ?? Str::slug($data['name']);
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

        // Create (or reuse) the school administrator account and attach it as
        // this school's owner. This is who signs in on the subdomain to run
        // setup, not the platform super admin.
        $this->attachAdmin($school, $data['admin_name'], $data['admin_email'], $data['admin_password']);

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
        $admin->save();

        $ownerRole = Role::whereNull('school_id')->where('slug', 'school-owner')->value('id');
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

        $slug = $data['slug'] ?? Str::slug($data['name']);
        if (School::where('slug', $slug)->exists()) {
            return back()->with('error', "A school with the address '{$slug}' already exists.")->withInput();
        }

        $clone = School::create(['name' => $data['name'], 'slug' => $slug, 'email' => $data['admin_email']]);
        $tenants->provision($clone);
        $this->attachAdmin($clone, $data['admin_name'], $data['admin_email'], $data['admin_password']);
        $cloner->clone($school, $clone);

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

    /** Remove a school (soft delete): hidden from the platform, recoverable. */
    public function destroy(School $school): RedirectResponse
    {
        $name = $school->name;
        $school->delete();

        return redirect()->route('admin.schools.index')
            ->with('status', "{$name} has been removed and moved to the archive. Its database is retained and it can be restored at any time.");
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

    public function show(School $school): \Illuminate\View\View
    {
        $ownerRoleId = Role::whereNull('school_id')->where('slug', 'school-owner')->value('id');

        $admins = $school->users()
            ->wherePivot('role_id', $ownerRoleId)
            ->withPivot(['status'])
            ->orderBy('name')
            ->get();

        $backups = \App\Models\Backup::where('school_id', $school->id)->orderByDesc('created_at')->get();

        return view('admin.school', ['school' => $school, 'admins' => $admins, 'backups' => $backups]);
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

        $ownerRoleId = Role::whereNull('school_id')->where('slug', 'school-owner')->value('id');
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
