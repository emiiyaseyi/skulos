<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Models\UserAdditionalRole;
use App\Services\Accounts\MultiRoleAccountService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Multi-role accounts (2026-09-11) -- "add a feature to the admin
 * setting that allows one account to serve as both parent and teacher...
 * allow the school admin to also be a class teacher or subject teacher,
 * or other roles." A deliberately SEPARATE screen from Role Management
 * (the user's own explicit answer), since granting a SECOND role is a
 * different, less common action from the everyday "assign this person's
 * one real role" Role Management already does.
 */
class MultiRoleAccountController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), 'staff.manage_multi_role'),
            403, 'You do not have permission for this.'
        );
    }

    /**
     * Every role from the platform's own catalog except school-owner,
     * the same exclusion StaffController::assignableRoles() already
     * applies — NOT limited to roles this school happens to have already
     * cloned, so an admin can grant a role nobody at this school has
     * ever held before (grant() below clones it into a real school-scoped
     * Role row on demand, same as AccountProvisioningService always does).
     *
     * @return array<int, array{slug: string, name: string}>
     */
    protected function assignableRoles(): array
    {
        return collect(config('skulos.roles'))
            ->except(['school-owner'])
            ->map(fn ($def, $slug) => ['slug' => $slug, 'name' => $def['name']])
            ->values()
            ->all();
    }

    public function index(Request $request): View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        // Search by name or email (user directive 2026-09-12: "include
        // search button here too and every page or section like this
        // to aid searching" -- same convention as Role Management's
        // own Users table).
        $search = trim((string) $request->query('q'));

        $members = $school->users()
            ->wherePivot('status', 'active')
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            }))
            ->orderBy('users.name')
            ->paginate(30)
            ->withQueryString();

        $members->getCollection()->transform(function (User $u) use ($school) {
            $u->setAttribute('primaryRole', \App\Models\Role::find($u->pivot->role_id));
            $u->setAttribute('additionalRoles', UserAdditionalRole::with('role')
                ->where('school_id', $school->id)->where('user_id', $u->id)->get());

            return $u;
        });

        return view('school.multi-role.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'members' => $members,
            'search' => $search,
            'roles' => $this->assignableRoles(),
        ]);
    }

    public function grant(Request $request, MultiRoleAccountService $service, Rbac $rbac): RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role_slug' => ['required', 'string', \Illuminate\Validation\Rule::in(array_column($this->assignableRoles(), 'slug'))],
        ]);

        $user = User::whereHas('schools', fn ($q) => $q->where('schools.id', $school->id))
            ->findOrFail($data['user_id']);
        // cloneTemplateRole() is idempotent (returns the existing
        // school-scoped row if this school has already cloned this
        // role), so this works whether or not anyone here has ever
        // held it before.
        $role = $rbac->cloneTemplateRole($school, $data['role_slug']);

        $service->grantRole($school, $user, $role, Auth::user());

        return back()->with('status', "{$user->name} can now also act as {$role->name}.");
    }

    public function revoke(Request $request, MultiRoleAccountService $service): RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role_id' => ['required', 'integer'],
        ]);

        $user = User::findOrFail($data['user_id']);
        $role = Role::where('school_id', $school->id)->findOrFail($data['role_id']);

        $service->revokeRole($school, $user, $role);

        return back()->with('status', "{$role->name} access removed from {$user->name}.");
    }
}
