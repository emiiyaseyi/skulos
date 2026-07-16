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
 * FEATURES INTO ONE AND CAN ALSO ASSIGN A ROLE TO A USER ETC"; extended
 * 2026-07-16: "CHECK THE BILLING FEATURES AGAIN WHERE WE HAVE SO MANY MAIN
 * SECTIONS AND SUB SECTION WITH ALL THE FEATURES UNDER EACH SECTION, THAT
 * IS HOW I WANT IT TO APPEAR... SAME THING FOR EXISITING ROLES, ONE SHOULD
 * BE ABLE TO CLICK ON THEM AND SEE WHAT THEY HAVE ACCESS TO, ADD OR
 * REMOVE... UNDER 'Roles', I WANT TO CLICK ON A ROLE AND SEE ALL THE NAME
 * OF USERS UNDER THE ROLE - THIS CAN COME AS POP UP"). One screen, three
 * capabilities:
 *   1. Create a custom role by combining individual permissions across
 *      several modules (a genuinely new App\Models\Role row, not a
 *      per-user override) — each module is its own collapsible section,
 *      each of its real granular permissions (view/create/update/...) its
 *      own checkbox within it, the same "sections and sub sections" shape
 *      Billing's plan feature catalog already uses.
 *   2. Reassign which role a staff member holds, and click a role to see
 *      every user currently on it (a popup, not a separate page).
 *   3. The per-user "extra access" grant that used to live on the
 *      individual staff profile page, relocated here.
 *
 * Grants were originally additive-only by deliberate design ("a coarse
 * grant-everything-or-strip-everything editor would silently destroy a
 * template role's fine-grained tuning"). The user has since explicitly
 * asked for full add-or-remove editing of an existing role's permissions,
 * superseding that; a per-user *extra access* override stays additive
 * (it is layered on top of a role, not the role's own definition, so
 * "remove" there has never been meaningful, a user still has whatever
 * their base role grants regardless).
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

    /** Every module from the curated real-screen list, labelled, for the per-user extra access picker (whole-module grants only, unchanged). */
    protected function moduleOptions(): array
    {
        return collect(config('skulos.staff_extra_access_modules'))
            ->mapWithKeys(fn ($code) => [$code => config("skulos.modules.{$code}.name", $code)])
            ->all();
    }

    /**
     * The same curated real-screen module list, but each with its full
     * set of real granular Permission rows (view/create/update/delete/
     * export/bulk plus whatever config('skulos.extra_permissions') adds
     * for that module), for the role builder/editor's nested section
     * checklist. Restricting to this curated list (rather than every
     * module Permission rows happen to exist for) keeps the checklist to
     * modules that actually have a screen behind them, matching why
     * moduleOptions() above is scoped the same way.
     *
     * @return \Illuminate\Support\Collection<string, array{name: string, permissions: \Illuminate\Support\Collection<int, Permission>}>
     */
    protected function permissionCatalog(): \Illuminate\Support\Collection
    {
        $permissionsByModule = Permission::whereIn('module', config('skulos.staff_extra_access_modules'))
            ->orderBy('code')
            ->get()
            ->groupBy('module');

        return collect(config('skulos.staff_extra_access_modules'))
            ->mapWithKeys(fn ($code) => [$code => [
                'name' => config("skulos.modules.{$code}.name", $code),
                'permissions' => $permissionsByModule->get($code, collect()),
            ]]);
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
            ->with('permissions:id,code,module')
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

        // Grouped for the "click a role, see who is on it" popup (user
        // directive 2026-07-16), built here rather than a second endpoint
        // the popup would have to fetch, this page already loaded every
        // member and their role.
        $usersByRoleId = $members->groupBy(fn ($row) => $row['role']?->id)
            ->map(fn ($rows) => $rows->pluck('user.name')->values());

        return view('school.roles.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'roles' => $roles,
            'userCountsByRole' => $userCountsByRole,
            'usersByRoleId' => $usersByRoleId,
            'members' => $members,
            'moduleOptions' => $this->moduleOptions(),
            'permissionCatalog' => $this->permissionCatalog(),
        ]);
    }

    /** Every real Permission id from the curated module list, for validating a submitted checklist. */
    protected function validPermissionIds(): array
    {
        return Permission::whereIn('module', config('skulos.staff_extra_access_modules'))->pluck('id')->all();
    }

    public function storeRole(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [Rule::in($this->validPermissionIds())],
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

        $role->permissions()->sync($data['permissions']);

        return redirect()->route('school.roles.index', ['school' => $school->slug])
            ->with('status', "\"{$role->name}\" created with ".count($data['permissions']).' permission(s).');
    }

    /**
     * Full add-or-remove sync of a role's own permission set (user
     * directive 2026-07-16: "ONE SHOULD BE ABLE TO CLICK ON THEM AND SEE
     * WHAT THEY HAVE ACCESS TO, ADD OR REMOVE"), superseding the earlier
     * additive-only design for this specific screen (see class docblock).
     * Only ever touches permissions from the curated module list this
     * screen's own checklist offers; any permission this role holds
     * outside that list (e.g. a template role's own fine-grained baseline
     * from a module not on this curated list) is preserved untouched,
     * since the checklist never had a checkbox for it in the first place.
     */
    public function updateRolePermissions(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $role = Role::where('school_id', $school->id)->findOrFail($request->route('roleId'));

        $data = $request->validate([
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [Rule::in($this->validPermissionIds())],
        ]);
        $checked = $data['permissions'] ?? [];

        $outsideCatalog = $role->permissions()->whereNotIn('permissions.id', $this->validPermissionIds())->pluck('permissions.id');
        $role->permissions()->sync($outsideCatalog->merge($checked)->unique());

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
