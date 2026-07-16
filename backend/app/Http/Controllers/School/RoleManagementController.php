<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Tenant\Staff;
use App\Models\User;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * User & Role Management (user directive 2026-07-15: "ALL THESE EXTRA
 * ACCESS SHOULD BE MOVED TO USER AND ROLE MANAGEMENT WITH MORE FEATURES TO
 * BE GIVEN TO A USER AND THE ADMIN CAN CREATE A NEW ROLE BY COMBINING MORE
 * FEATURES INTO ONE AND CAN ALSO ASSIGN A ROLE TO A USER ETC"). One
 * screen, three capabilities:
 *   1. Create a custom role by combining full access to several modules
 *      (a genuinely new App\Models\Role row, not a per-user override).
 *   2. Reassign which role a staff member holds.
 *   3. The per-user "extra access" grant that used to live on the
 *      individual staff profile page, relocated here.
 *
 * Module grants (both role-building and per-user extra access) are
 * deliberately additive-only: checking a module's box grants full access
 * to it, leaving unchecked modules' existing permissions untouched. A
 * role cloned from a template can carry fine-grained partial grants
 * (e.g. bursar's students.view-only), and a coarse "grant everything or
 * strip everything" editor here would silently destroy that tuning. This
 * screen only ever adds capability, it does not remove any.
 */
class RoleManagementController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), 'staff.update'),
            403,
            'You do not have permission for this.'
        );
    }

    /** Every module from the curated real-screen list, labelled, for a checkbox picker. */
    protected function moduleOptions(): array
    {
        return collect(config('skulos.staff_extra_access_modules'))
            ->mapWithKeys(fn ($code) => [$code => config("skulos.modules.{$code}.name", $code)])
            ->all();
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $rbac = app(Rbac::class);

        // Every standard role template is available to pick from, cloned
        // into this school on first view if it never had cause to be
        // before now (idempotent, cloneTemplateRole() no-ops if already
        // cloned), so the assign-role dropdown always offers the full
        // catalog, not just whichever roles a staff member happened to
        // already be created with.
        foreach (array_keys(config('skulos.roles')) as $slug) {
            if ($slug === 'school-owner') {
                continue;
            }
            $rbac->cloneTemplateRole($school, $slug);
        }

        $roles = Role::where('school_id', $school->id)
            ->withCount('permissions')
            ->orderBy('name')
            ->get();
        $userCountsByRole = DB::table('school_user')
            ->where('school_id', $school->id)
            ->whereNotNull('role_id')
            ->select('role_id', DB::raw('count(*) as aggregate'))
            ->groupBy('role_id')
            ->pluck('aggregate', 'role_id');

        $members = $school->users()
            ->wherePivot('status', 'active')
            ->get()
            ->map(function (User $user) use ($school, $rbac) {
                return [
                    'user' => $user,
                    'role' => $user->roleIn($school),
                    'extraModules' => collect($this->moduleOptions())->keys()
                        ->filter(fn ($code) => $rbac->hasModuleOverride($school, $user, $code))
                        ->values(),
                ];
            })
            ->sortBy(fn ($row) => $row['user']->name)
            ->values();

        return view('school.roles.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'roles' => $roles,
            'userCountsByRole' => $userCountsByRole,
            'members' => $members,
            'moduleOptions' => $this->moduleOptions(),
        ]);
    }

    public function storeRole(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => [Rule::in(array_keys($this->moduleOptions()))],
        ]);

        $slug = Str::slug($data['name']);
        $suffix = 1;
        while (Role::where('school_id', $school->id)->where('slug', $slug)->exists()) {
            $slug = Str::slug($data['name']).'-'.(++$suffix);
        }

        // Role::create() would silently drop school_id, it is deliberately
        // not mass-assignable (Role's own docblock: "a request can never
        // re-home a role to another school"), so it is set directly, the
        // same pattern Rbac::cloneTemplateRole() already uses.
        $role = new Role(['name' => $data['name'], 'slug' => $slug]);
        $role->school_id = $school->id;
        $role->save();

        $codes = Permission::whereIn('module', $data['modules'])->pluck('id');
        $role->permissions()->sync($codes);

        return redirect()->route('school.roles.index', ['school' => $school->slug])
            ->with('status', "\"{$role->name}\" created with full access to ".count($data['modules']).' module(s).');
    }

    /** Additive: grants full access to the checked modules on top of whatever this role already has. */
    public function addRoleModules(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $role = Role::where('school_id', $school->id)->findOrFail($request->route('roleId'));

        $data = $request->validate([
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => [Rule::in(array_keys($this->moduleOptions()))],
        ]);

        $codes = Permission::whereIn('module', $data['modules'])->pluck('id');
        $role->permissions()->syncWithoutDetaching($codes);

        return back()->with('status', "\"{$role->name}\" updated.");
    }

    public function assignRole(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $user = User::findOrFail($request->route('userId'));
        abort_unless($user->isMemberOf($school), 404);

        $data = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('school_id', $school->id)],
        ]);

        DB::table('school_user')
            ->where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->update(['role_id' => $data['role_id'], 'updated_at' => now()]);

        return back()->with('status', "{$user->name}'s role updated.");
    }

    /** Relocated from the staff profile page (StaffController::updateExtraAccess() had this before 2026-07-15). */
    public function updateExtraAccess(Request $request, Rbac $rbac): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $user = User::findOrFail($request->route('userId'));
        abort_unless($user->isMemberOf($school), 404);

        $allowed = array_keys($this->moduleOptions());
        $data = $request->validate([
            'modules' => ['sometimes', 'array'],
            'modules.*' => [Rule::in($allowed)],
        ]);
        $granted = $data['modules'] ?? [];

        foreach ($allowed as $moduleCode) {
            if (in_array($moduleCode, $granted, true)) {
                $rbac->grantModuleOverride($school, $user, $moduleCode);
            } else {
                $rbac->revokeModuleOverride($school, $user, $moduleCode);
            }
        }

        return back()->with('status', "{$user->name}'s extra access updated.");
    }
}
